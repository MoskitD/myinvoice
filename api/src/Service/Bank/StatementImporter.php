<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Persist parsed GPC do DB. Dedupe podle file_hash.
 */
final class StatementImporter
{
    public function __construct(
        private readonly Connection $db,
        private readonly GpcParser $parser,
        private readonly StatementMatcher $matcher,
    ) {}

    /**
     * @return array{statement_id:int, transactions:int, matched:int, duplicate:bool}
     */
    public function import(string $content, string $fileName, ?int $userId): array
    {
        $hash = hash('sha256', $content);
        $pdo = $this->db->pdo();

        // Dedupe
        $exists = $pdo->prepare('SELECT id FROM bank_statements WHERE file_hash = ?');
        $exists->execute([$hash]);
        $existingId = $exists->fetchColumn();
        if ($existingId !== false) {
            return ['statement_id' => (int) $existingId, 'transactions' => 0, 'matched' => 0, 'duplicate' => true];
        }

        $parsed = $this->parser->parse($content);
        $h = $parsed['header'];

        // GPC header (074) NEMÁ pole pro měnu — máme to jen v 075 transakcích
        // (pozice 118-122, ISO 4217 numeric). Odvodíme měnu výpisu v pořadí:
        //   1) Lookup do currencies podle account_number/IBAN — GPC výpis je vždy
        //      z JEDNOHO účtu (= jedna měna), takže měna registrovaného účtu je
        //      AUTORITATIVNÍ. Per-tx pole nelze upřednostnit: Fio ho dle své
        //      specifikace plní KONSTANTNĚ "0203" (CZK) i u EUR účtu (#109 —
        //      EUR výpis se pak zobrazil v Kč a kvůli currency guardu v matcheru
        //      se nikdy nespároval).
        //   2) Fallback (účet neregistrovaný): dominantní non-null currency
        //      z 075 transakcí (CREDITAS/KB plní reálný kód; původní Creditas
        //      bug report — EUR výpis s 00978 se zobrazoval jako CZK, protože
        //      bank_statements.currency zůstával NULL).
        //   3) Bez 1 i 2: NULL (UI fallback CZK).
        $accountCurrency = $this->lookupAccountCurrency($h['account_number']);
        $statementCurrency = $accountCurrency
            ?? $this->detectStatementCurrency($parsed['transactions']);

        $pdo->prepare(
            'INSERT INTO bank_statements
                 (file_name, file_hash, file_content, account_number, currency,
                  statement_number, statement_date,
                  prev_balance, curr_balance, credit_total, debit_total, transaction_count, imported_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $fileName, $hash, $content, $h['account_number'], $statementCurrency,
            $h['statement_number'], $h['statement_date'],
            $h['prev_balance'], $h['curr_balance'], $h['credit_total'], $h['debit_total'],
            count($parsed['transactions']), $userId,
        ]);
        $statementId = (int) $pdo->lastInsertId();

        $insertTx = $pdo->prepare(
            'INSERT INTO bank_transactions
                 (statement_id, posted_at, amount, currency, variable_symbol, constant_symbol, specific_symbol,
                  counterparty_account, counterparty_bank, counterparty_name, description, bank_ref)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );

        $matched = 0;
        foreach ($parsed['transactions'] as $tx) {
            // Měna registrovaného účtu přebíjí i per-tx pole (#109): výpis je
            // jednoměnový a Fio do 075 píše konstantně CZK i u EUR účtu — per-tx
            // hodnota by rozbila currency guard v matcheru. Per-tx kód se použije
            // jen jako fallback, když účet není registrovaný (CREDITAS/KB ho
            // plní reálně) — aby se EUR transakce neztratila.
            $txCurrency = $accountCurrency ?? $tx['currency'] ?? $statementCurrency;
            $insertTx->execute([
                $statementId, $tx['posted_at'], $tx['amount'], $txCurrency,
                $tx['variable_symbol'], $tx['constant_symbol'], $tx['specific_symbol'],
                $tx['counterparty_account'], $tx['counterparty_bank'], $tx['counterparty_name'],
                $tx['description'], $tx['bank_ref'],
            ]);
            $txId = (int) $pdo->lastInsertId();
            $r = $this->matcher->match($txId);
            if (in_array($r['status'], ['auto_exact', 'auto_partial'], true)) {
                $matched++;
            }
        }

        $pdo->prepare('UPDATE bank_statements SET matched_count = ? WHERE id = ?')
            ->execute([$matched, $statementId]);

        return [
            'statement_id' => $statementId,
            'transactions' => count($parsed['transactions']),
            'matched'      => $matched,
            'duplicate'    => false,
        ];
    }

    /**
     * Dominantní currency z transakcí — vrátí ten kód, který se vyskytuje
     * nejčastěji (po vyřazení NULL). NULL pokud ani jedna transakce currency
     * nemá. Multi-currency výpisy jsou v praxi vzácné; když je víc kódů,
     * statement.currency dostane majoritní.
     *
     * @param list<array{currency?:?string}> $transactions
     */
    private function detectStatementCurrency(array $transactions): ?string
    {
        $counts = [];
        foreach ($transactions as $tx) {
            $c = $tx['currency'] ?? null;
            if (is_string($c) && $c !== '') {
                $counts[$c] = ($counts[$c] ?? 0) + 1;
            }
        }
        if ($counts === []) return null;
        arsort($counts);
        return (string) array_key_first($counts);
    }

    /**
     * Lookup currency podle account_number v `currencies` tabulce. Pro případy,
     * kdy banka nevyplňuje 075.currency (= per-tx detection selže) — vezmeme
     * měnu prvního nalezeného currencies řádku se stejným číslem účtu (napříč
     * tenanty; multi-supplier separace je doménou caller — StatementImporter
     * pracuje bez tenant kontextu, ale account_number je defakto unikátní).
     *
     * AccountNumberNormalizer::equals normalizuje leading zeros / dashes pro
     * porovnání (např. `0000000112866714` z GPC vs `112866714` z UI inputu).
     * Porovnává se i domácí část IBANu (#109) — cizoměnové účty bývají
     * evidované jen IBANem a bez toho EUR výpis spadl na CZK fallback.
     */
    private function lookupAccountCurrency(string $accountNumber): ?string
    {
        if ($accountNumber === '') return null;
        $stmt = $this->db->pdo()->query(
            'SELECT account_number, iban, code FROM currencies
              WHERE account_number IS NOT NULL OR iban IS NOT NULL'
        );
        if ($stmt === false) return null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $iban = isset($row['iban']) && is_string($row['iban']) ? $row['iban'] : null;
            if (AccountNumberNormalizer::matchesAny($accountNumber, $row['account_number'] ?? null, $iban)) {
                return (string) $row['code'];
            }
        }
        return null;
    }
}
