<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Mail\PaymentThanksMailer;
use PDO;

/**
 * Matchne bankovní transakci na fakturu podle VS + amount.
 *
 * Strategie:
 *   1. Příchozí (amount > 0) — hledá fakturu se shodným varsymbol
 *      a) |amount - amount_to_pay| <= 0.05 Kč → 'auto_exact', faktura → paid
 *      b) |amount - amount_to_pay| <= 1 Kč → 'auto_partial' (částečná platba)
 *   2. Odchozí (amount < 0) — hledá přijatou fakturu (varsymbol nebo
 *      vendor_invoice_number), payment_matches N:N.
 *
 * Tolerance 0.05 Kč pro exact match: typické zaokrouhlení 21 % DPH na
 * vícepoložkové faktuře dává ±0.01 — ±0.04 Kč rozdíl mezi součtem
 * položek a total_with_vat. Příklad: 1241.34 × 1.21 = 1502.0214 →
 * zaokrouhlí buď na 1502.02 (per řádek po výpočtu DPH) nebo 1502.00
 * (suma per řádek bez DPH × sazba). Bank převod je za jednu z hodnot,
 * faktura má druhou — diff 0.02 je legitní. Před 0.05 tolerancí toto
 * padalo do auto_partial a faktura zůstávala neoznačena jako paid.
 *
 * Multi-supplier: VS je unique per (supplier_id, varsymbol). Matcher určuje
 * supplier_id z bank_statement.account_number → currencies.account_number → supplier_id.
 * Pokud žádná currency neodpovídá účtu (bank statement nepatří žádnému supplierovi),
 * vrátí 'unmatched/unknown_supplier'.
 */
final class StatementMatcher
{
    /** Tolerance pro auto_exact match — pokrývá DPH zaokrouhlení (±2-4 haléře typicky). */
    private const EXACT_MATCH_TOLERANCE = 0.05;
    /** Tolerance pro auto_partial — vyšší rozdíly už se ručně rozeznají (splátka / přeplatek). */
    private const PARTIAL_MATCH_TOLERANCE = 1.0;
    /** Účetní (tuzemská) měna — base částky, na kterou přepočítáváme cizoměnové faktury. */
    private const LOCAL_CURRENCY = 'CZK';
    /** Relativní tolerance pro cross-currency shodu (tuzemská platba cizoměnové faktury).
     *  Banka si na převodu bere spread klidně ~2 % a kurz se za pár dní pohne — 4 %
     *  dává rezervu, aby přepočet přes kurz faktury reálně sednul. */
    private const FX_MATCH_TOLERANCE_PCT = 0.04;

    public function __construct(
        private readonly Connection $db,
        private readonly FinalFromProformaCreator $finalCreator,
        // Volitelný — automatické cesty (GPC import, e-mailové avízo, cron, rescan) jdou
        // přes match() (ne přes MarkPaidAction/manualMatch), takže děkovný e-mail za úhradu
        // se musí poslat odsud. Nullable kvůli izolovaným konstrukcím v testech/skriptech;
        // produkční wiring (Bootstrap) ho vždy injektuje. Viz #127.
        private readonly ?PaymentThanksMailer $paymentThanks = null,
    ) {}

    /**
     * Očekávaná částka faktury vyjádřená v měně transakce + tolerance (exact, partial).
     * Vrací null, když měny nejdou spolehlivě porovnat (cizoměnový účet × jiná měna faktury
     * — bez kurzu transakce neumíme převést; raději nepárovat než špatně).
     *
     * @return array{expected: float, exact: float, partial: float}|null
     */
    private function expectedMatch(float $invoiceAmount, string $invoiceCcy, float $rate, ?string $txCurrency, ?float $exactTolerance = null): ?array
    {
        $exact = $exactTolerance !== null && $exactTolerance >= 0.0
            ? $exactTolerance
            : self::EXACT_MATCH_TOLERANCE;
        // Neznámá měna transakce (legacy výpisy) nebo shodná měna → přímé porovnání.
        if ($txCurrency === null || strtoupper($txCurrency) === strtoupper($invoiceCcy)) {
            return ['expected' => $invoiceAmount, 'exact' => $exact, 'partial' => max($exact, self::PARTIAL_MATCH_TOLERANCE)];
        }
        // Tuzemská platba cizoměnové faktury → přepočet kurzem faktury (CZK = částka × kurz).
        // Relativní tolerance kvůli kurzovému driftu; partial tier zde nemá smysl (= exact).
        if (strtoupper($txCurrency) === self::LOCAL_CURRENCY) {
            $r = $rate > 0 ? $rate : 1.0;
            $czk = $invoiceAmount * $r;
            $tol = max($exact, $czk * self::FX_MATCH_TOLERANCE_PCT);
            return ['expected' => $czk, 'exact' => $tol, 'partial' => $tol];
        }
        // Cizoměnový účet × jiná měna faktury (např. EUR výpis × CZK/USD faktura) — skip.
        return null;
    }

    public function match(int $transactionId): array
    {
        $pdo = $this->db->pdo();
        $tx = $pdo->prepare(
            'SELECT bt.*, bs.account_number AS recipient_account, bs.bank_code AS recipient_bank,
                    bs.currency AS statement_currency
               FROM bank_transactions bt
               JOIN bank_statements   bs ON bs.id = bt.statement_id
              WHERE bt.id = ?'
        );
        $tx->execute([$transactionId]);
        $row = $tx->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['status' => 'unmatched', 'reason' => 'transaction_not_found'];
        }
        $vs = $row['variable_symbol'];
        $amount = (float) $row['amount'];
        // VS může chybět (karetní platby) — řeší se per-směr níže: odchozí přes fuzzy
        // (částka + podobný název), příchozí stále vyžadují VS.
        // Currency guard: tx currency (preferováno) → statement currency
        // (fallback) → null (backward compat — staré výpisy bez měny matchují
        // jakoukoli fakturu, jak to dělaly před fixem v4.1.0).
        $txCurrency = is_string($row['currency'] ?? null) && $row['currency'] !== ''
            ? (string) $row['currency']
            : (is_string($row['statement_currency'] ?? null) && $row['statement_currency'] !== ''
                ? (string) $row['statement_currency']
                : null);
        // Outgoing (amount < 0) → match na purchase_invoice (přijatou) — fáze 3.
        // Incoming (amount > 0) → match na invoice (vydanou) — existing flow.
        $isOutgoing = $amount < 0;
        $exactTolerance = isset($row['match_tolerance']) && $row['match_tolerance'] !== null
            ? max(0.0, (float) $row['match_tolerance'])
            : null;

        // Určení supplier_id z bank účtu (currencies.account_number + bank_code).
        // Normalizace přes AccountNumberNormalizer (řeší zero-padding a prefix).
        // Porovnává se i domácí část IBANu (#109) — cizoměnové účty bývají evidované
        // jen IBANem (viz schema currencies), GPC ale nese domácí číslo účtu; bez toho
        // EUR výpis skončil jako unknown_supplier_for_account a nikdy se nespároval.
        $supplierId = 0;
        if (!empty($row['recipient_account'])) {
            $stmt = $pdo->query(
                'SELECT supplier_id, account_number, iban, bank_code FROM currencies
                  WHERE account_number IS NOT NULL OR iban IS NOT NULL'
            );
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $candidate) {
                $iban = isset($candidate['iban']) && is_string($candidate['iban']) ? $candidate['iban'] : null;
                // Bank code filter (jen když výpis kód banky nese): kandidátův kód
                // z bank_code sloupce, případně z IBANu. Neznámý kód nevyřazuje —
                // radši porovnat číslo účtu než ztratit IBAN-only řádek.
                if (!empty($row['recipient_bank'])) {
                    $candidateBank = (string) ($candidate['bank_code'] ?? '');
                    if ($candidateBank === '' && $iban !== null) {
                        $candidateBank = (string) AccountNumberNormalizer::czechIbanBankCode($iban);
                    }
                    if ($candidateBank !== '' && $candidateBank !== (string) $row['recipient_bank']) {
                        continue;
                    }
                }
                if (AccountNumberNormalizer::matchesAny((string) $row['recipient_account'], $candidate['account_number'] ?? null, $iban)) {
                    $supplierId = (int) $candidate['supplier_id'];
                    break;
                }
            }
        }
        if ($supplierId === 0) {
            return ['status' => 'unmatched', 'reason' => 'unknown_supplier_for_account'];
        }

        // ── Outgoing → purchase_invoice (přijaté faktury) ────────────────
        if ($isOutgoing) {
            // 1) přesný match dle VS dodavatele (vendor_invoice_number / varsymbol)
            if ($vs) {
                $res = $this->matchPurchase($pdo, $supplierId, (string) $vs, abs($amount), (string) $row['posted_at'], $transactionId, $txCurrency);
                if (($res['status'] ?? 'unmatched') !== 'unmatched') {
                    return $res;
                }
            }
            // 2) karetní platby (bez VS) / VS bez shody → fuzzy dle částky + podobného
            //    názvu protistrany (u karet je název obchodníka odlišný od jména dodavatele).
            return $this->matchPurchaseFuzzy($pdo, $supplierId, abs($amount), (string) ($row['counterparty_name'] ?? ''), (string) $row['posted_at'], $transactionId, $txCurrency);
        }

        // Příchozí platby stále vyžadují VS (vystavené faktury se párují na náš VS).
        if (!$vs) {
            return ['status' => 'unmatched', 'reason' => 'no_vs'];
        }

        // ── Incoming → invoice (vystavené faktury) — existing flow ─────────
        // Najdi fakturu s VS = transakce.VS, supplier scope, status in (issued, sent, reminded, paid),
        // amount_to_pay sedí. 'paid' je v setu, aby se transakce navázala i na fakturu už označenou
        // za zaplacenou ručně (ať ve výpisu nevisí unmatched). Status/paid_at v tom případě
        // ponecháme — netouchujeme stav, který uživatel nastavil ručně.
        // Proformu povolujeme — zaplacená proforma se označí paid a navíc vytvoří DRAFT finální faktury.
        // Currency-aware match: fakturu hledáme jen dle VS (ne dle měny), částku pak
        // porovnáváme v měně transakce — u cizoměnové faktury placené z CZK účtu přes
        // kurz faktury (viz expectedMatch). Nebezpečný případ (EUR výpis × CZK faktura
        // stejného VS+amount) expectedMatch vrátí null → zůstane unmatched.
        // VS match: 1) přesná shoda (rychlá cesta pro čistě číselné VS), 2) numerická
        // shoda po normalizaci — `invoices.varsymbol` slouží i jako číslo dokladu, takže
        // může nést pomlčku/lomítko (např. „2026-00001"), zatímco banka pošle jen číslice
        // („202600001"). CAST(REGEXP_REPLACE(...) AS UNSIGNED) zrcadlí
        // VariableSymbolNormalizer::forMatching (číslice bez vodicích nul). REGEXP '[1-9]'
        // vyřadí prázdné / samé-nuly varsymboly (CAST '' → 0), aby nevznikla planá shoda.
        $vsDigits = VariableSymbolNormalizer::digits((string) $vs);
        $sql = "SELECT i.id, i.varsymbol, i.amount_to_pay, i.exchange_rate, i.status, i.invoice_type, cur.code AS currency
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND (i.varsymbol = ?
                        OR (i.varsymbol REGEXP '[1-9]'
                            AND CAST(REGEXP_REPLACE(i.varsymbol, '[^0-9]', '') AS UNSIGNED) = CAST(? AS UNSIGNED)))
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'proforma')
                 LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$supplierId, $vs, $vsDigits]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            return ['status' => 'unmatched', 'reason' => 'no_invoice_with_vs', 'tx_currency' => $txCurrency];
        }

        $m = $this->expectedMatch((float) $inv['amount_to_pay'], (string) $inv['currency'], (float) ($inv['exchange_rate'] ?: 0), $txCurrency, $exactTolerance);
        if ($m === null) {
            return ['status' => 'unmatched', 'reason' => 'currency_mismatch',
                    'tx_currency' => $txCurrency, 'invoice_currency' => $inv['currency']];
        }

        $alreadyPaid = ($inv['status'] === 'paid');
        $diff = abs($amount - $m['expected']);
        if ($diff <= $m['exact']) {
            // Exact match — pokud faktura ještě není paid, označit ji a (u proformy) vyrobit final draft.
            // Pro již ručně paid fakturu jen navážeme transakci (status/paid_at netknuté).
            $pdo->beginTransaction();
            try {
                if (!$alreadyPaid) {
                    $pdo->prepare(
                        "UPDATE invoices SET status = 'paid', paid_at = ? WHERE id = ?"
                    )->execute([$row['posted_at'], $inv['id']]);
                }
                $pdo->prepare(
                    "UPDATE bank_transactions
                        SET matched_invoice_id = ?, match_status = 'auto_exact', matched_at = NOW()
                      WHERE id = ?"
                )->execute([$inv['id'], $transactionId]);

                $finalDraftId = null;
                if (!$alreadyPaid && $inv['invoice_type'] === 'proforma') {
                    $finalDraftId = $this->finalCreator->create((int) $inv['id'], 0);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            // Děkovný e-mail za úhradu (#57/#127) — jen pro fakturu nově označenou jako
            // paid (ne ručně paid, kterou jen navazujeme). Best-effort, mimo transakci:
            // mailer si sám ohlídá enabled + auto_send (bank_match trigger) i idempotenci
            // a nikdy nevyhazuje výjimku, takže selhání e-mailu nerozbije spárování.
            if (!$alreadyPaid) {
                $this->paymentThanks?->sendForInvoice((int) $inv['id'], 'bank_match', null, null, null, requireUnsent: true);
            }

            $result = ['status' => 'auto_exact', 'invoice_id' => (int) $inv['id'], 'varsymbol' => $vs];
            if ($finalDraftId !== null) {
                $result['final_draft_id'] = $finalDraftId;
            }
            if ($alreadyPaid) {
                $result['already_paid'] = true;
            }
            return $result;
        }
        if ($diff <= $m['partial']) {
            // Partial match — flag, ale nepaint paid (uživatel rozhodne)
            $pdo->prepare(
                "UPDATE bank_transactions
                    SET matched_invoice_id = ?, match_status = 'auto_partial', matched_at = NOW()
                  WHERE id = ?"
            )->execute([$inv['id'], $transactionId]);
            return ['status' => 'auto_partial', 'invoice_id' => (int) $inv['id'], 'diff' => $diff];
        }

        return ['status' => 'unmatched', 'reason' => 'amount_mismatch', 'expected' => $m['expected'], 'got' => $amount];
    }

    /**
     * Match outgoing transakce na přijatou fakturu.
     * bank_transactions.matched_invoice_id slouží jen pro vystavené faktury,
     * pro přijaté používáme payment_matches table (N:N model).
     */
    private function matchPurchase(\PDO $pdo, int $supplierId, string $vs, float $absAmount, string $postedAt, int $transactionId, ?string $txCurrency = null): array
    {
        // 'paid' v setu: dovolíme navázat transakci i na ručně zaplacenou přijatou fakturu
        // (ať ve výpisu nevisí). Status/paid_at v tom případě nepřepisujeme.
        // Currency guard viz match() — bez něj by EUR výdaj napároval CZK přijatou.
        //
        // VS lookup: na rozdíl od vystavených faktur (kde klient platí naši `varsymbol`),
        // u přijatých platíme my dodavateli — do bank převodu typicky vepíšeme
        // **VS dodavatele** = `vendor_invoice_number`. Náš `purchase_invoices.varsymbol`
        // je interní PF-YYYYMM-NNNN, jen občas se s `vendor_invoice_number` shodují
        // (když user nepoužívá auto-counter). Hledáme proto OR na obojí — uživatel může
        // platit pod naším PF-... i pod původním číslem dodavatele.
        // Přesná shoda na náš VS i VS dodavatele + numerická shoda po normalizaci
        // (číslo dokladu s pomlčkou „PF-2026-0001" × jen-číslice z banky). Viz match().
        $vsDigits = VariableSymbolNormalizer::digits($vs);
        $sql = "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number,
                       COALESCE(pi.amount_to_pay, pi.total_with_vat, 0) AS amount_to_pay,
                       pi.exchange_rate, pi.status, cur.code AS currency
                  FROM purchase_invoices pi
             LEFT JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   AND (pi.varsymbol = ? OR pi.vendor_invoice_number = ?
                        OR (pi.varsymbol REGEXP '[1-9]'
                            AND CAST(REGEXP_REPLACE(pi.varsymbol, '[^0-9]', '') AS UNSIGNED) = CAST(? AS UNSIGNED))
                        OR (pi.vendor_invoice_number REGEXP '[1-9]'
                            AND CAST(REGEXP_REPLACE(pi.vendor_invoice_number, '[^0-9]', '') AS UNSIGNED) = CAST(? AS UNSIGNED)))
                   AND pi.status IN ('received', 'booked', 'paid')
                 LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$supplierId, $vs, $vs, $vsDigits, $vsDigits]);
        $pi = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pi) {
            return ['status' => 'unmatched', 'reason' => 'no_purchase_with_vs', 'tx_currency' => $txCurrency];
        }

        $m = $this->expectedMatch((float) $pi['amount_to_pay'], (string) ($pi['currency'] ?? self::LOCAL_CURRENCY), (float) ($pi['exchange_rate'] ?: 0), $txCurrency);
        if ($m === null) {
            return ['status' => 'unmatched', 'reason' => 'currency_mismatch_purchase',
                    'tx_currency' => $txCurrency, 'invoice_currency' => $pi['currency']];
        }

        $alreadyPaid = ($pi['status'] === 'paid');
        $diff = abs($absAmount - $m['expected']);
        if ($diff <= $m['exact']) {
            $pdo->beginTransaction();
            try {
                if (!$alreadyPaid) {
                    $pdo->prepare(
                        "UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ?"
                    )->execute([$postedAt, $pi['id']]);
                }
                // payment_matches je N:N — INSERT bezpečný i pro paid invoice.
                // (Pokud by user spustil rematch znovu, transakce je už auto_exact a do
                // rematch setu nespadne — duplikace tedy nehrozí.)
                $pdo->prepare(
                    "INSERT INTO payment_matches
                        (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, match_confidence)
                     VALUES (?, ?, ?, ?, 'auto', 95)"
                )->execute([$supplierId, $transactionId, $pi['id'], $absAmount]);
                $pdo->prepare(
                    "UPDATE bank_transactions
                        SET match_status = 'auto_exact', matched_at = NOW()
                      WHERE id = ?"
                )->execute([$transactionId]);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $result = ['status' => 'auto_exact', 'purchase_invoice_id' => (int) $pi['id'], 'varsymbol' => $vs];
            if ($alreadyPaid) {
                $result['already_paid'] = true;
            }
            return $result;
        }
        if ($diff <= $m['partial']) {
            // Partial: zaznam do payment_matches + status na tx, ať UI vidí link
            // (předtím tady byl jen `return` bez zápisu — transakce zůstávaly
            // unmatched, partial match se v UI nikdy nezobrazil).
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    "INSERT INTO payment_matches
                        (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, match_confidence)
                     VALUES (?, ?, ?, ?, 'auto', 70)"
                )->execute([$supplierId, $transactionId, $pi['id'], $absAmount]);
                $pdo->prepare(
                    "UPDATE bank_transactions
                        SET match_status = 'auto_partial', matched_at = NOW()
                      WHERE id = ?"
                )->execute([$transactionId]);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            return [
                'status' => 'auto_partial',
                'purchase_invoice_id' => (int) $pi['id'],
                'diff' => $diff,
                'expected' => (float) $pi['amount_to_pay'],
                'got' => $absAmount,
            ];
        }
        return ['status' => 'unmatched', 'reason' => 'amount_mismatch_purchase', 'expected' => $m['expected'], 'got' => $absAmount];
    }

    /**
     * Fuzzy párování ODCHOZÍ platby na přijatou fakturu — pro karetní platby (bez VS),
     * kde název protistrany ve výpisu (obchodník) bývá odlišný od jména dodavatele.
     * Pravidlo: shodná částka (v toleranci) + měna + status received/booked. Z těchto
     * kandidátů vybere ty s podobným názvem (sdílí významný token). Spáruje JEN když je
     * právě jeden takový — jinak je shoda nejednoznačná a necháme unmatched (radši ručně
     * než špatně). Confidence 60 + auto_partial = příznak ke kontrole.
     */
    private function matchPurchaseFuzzy(\PDO $pdo, int $supplierId, float $absAmount, string $cpName, string $postedAt, int $transactionId, ?string $txCurrency): array
    {
        // Měnu nefiltrujeme v SQL — částku porovnáváme přes expectedMatch (cizoměnová
        // faktura placená kartou z CZK účtu se přepočte kurzem faktury).
        $sql = "SELECT pi.id, COALESCE(pi.amount_to_pay, pi.total_with_vat, 0) AS amount_to_pay,
                       pi.exchange_rate, c.company_name AS vendor_name, cur.code AS currency
                  FROM purchase_invoices pi
                  JOIN clients c ON c.id = pi.vendor_id
             LEFT JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   AND pi.status IN ('received', 'booked')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$supplierId]);

        $similar = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $m = $this->expectedMatch((float) $r['amount_to_pay'], (string) ($r['currency'] ?? self::LOCAL_CURRENCY), (float) ($r['exchange_rate'] ?: 0), $txCurrency);
            if ($m === null || abs($absAmount - $m['expected']) > $m['exact']) {
                continue; // částka (po přepočtu) musí sedět
            }
            if ($this->nameSimilarity($cpName, (string) $r['vendor_name']) > 0.0) {
                $similar[] = $r;
            }
        }
        if (count($similar) !== 1) {
            return ['status' => 'unmatched', 'reason' => empty($similar) ? 'no_fuzzy_match' : 'ambiguous_fuzzy_match'];
        }
        $pi = $similar[0];

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ? AND status <> 'paid'")
                ->execute([$postedAt, $pi['id']]);
            $pdo->prepare(
                "INSERT INTO payment_matches
                    (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, match_confidence)
                 VALUES (?, ?, ?, ?, 'auto', 60)"
            )->execute([$supplierId, $transactionId, (int) $pi['id'], $absAmount]);
            $pdo->prepare("UPDATE bank_transactions SET match_status = 'auto_partial', matched_at = NOW() WHERE id = ?")
                ->execute([$transactionId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['status' => 'auto_partial', 'purchase_invoice_id' => (int) $pi['id'], 'fuzzy' => true];
    }

    /**
     * Podobnost dvou názvů firem (0..1) — Jaccard překryv normalizovaných tokenů.
     * Sdílený významný token (např. značka) → > 0.
     */
    private function nameSimilarity(string $a, string $b): float
    {
        $ta = $this->nameTokens($a);
        $tb = $this->nameTokens($b);
        if (!$ta || !$tb) return 0.0;
        $inter = array_intersect($ta, $tb);
        $union = array_unique(array_merge($ta, $tb));
        return count($union) > 0 ? count($inter) / count($union) : 0.0;
    }

    /**
     * Normalizace názvu na tokeny: velká písmena, bez diakritiky, jen alfanum, tokeny
     * délky >= 3, bez právních forem / kódů zemí / častých lokalit.
     *
     * @return list<string>
     */
    private function nameTokens(string $name): array
    {
        $s = mb_strtoupper($name, 'UTF-8');
        $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        $s = preg_replace('/[^A-Z0-9]+/', ' ', $s) ?? '';
        $stop = ['SRO', 'AS', 'INC', 'LTD', 'LLC', 'GMBH', 'VOS', 'SPOL', 'THE', 'AND',
                 'CZ', 'CZE', 'SK', 'SVK', 'DE', 'DEU', 'NL', 'NLD', 'USA', 'GBR', 'AT', 'AUT',
                 'PRAHA', 'PRAGUE', 'BRNO', 'PLZEN', 'OSTRAVA'];
        $tokens = [];
        foreach (preg_split('/\s+/', trim($s)) as $tok) {
            if (strlen($tok) < 3 || in_array($tok, $stop, true)) continue;
            $tokens[] = $tok;
        }
        return array_values(array_unique($tokens));
    }
}
