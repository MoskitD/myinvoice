<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\StatementImporter;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Určení měny GPC výpisu při importu (#109 follow-up, reálný Fio EUR výpis):
 *
 * Fio dle své specifikace GPC plní pole měny v 075 záznamu KONSTANTNĚ "0203"
 * (CZK) — i u EUR účtu. Dřívější pořadí detekce (per-tx currency → lookup účtu)
 * proto u Fio EUR výpisu „uspělo" s CZK: výpis se zobrazil v Kč a currency guard
 * v matcheru zahodil všechny EUR faktury. Měna REGISTROVANÉHO účtu (GPC výpis je
 * vždy z jednoho účtu = jedna měna) je nově autoritativní; per-tx kód zůstává
 * fallback pro neregistrované účty (CREDITAS/KB ho plní reálně).
 *
 * Soft-skip pokud chybí cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class StatementImporterCurrencyTest extends TestCase
{
    private Connection $db;
    private StatementImporter $importer;
    private int $supplierId = 0;

    /** @var int[] */
    private array $currencyIds = [];
    /** @var int[] */
    private array $statementIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection (CI runner skipne).');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->importer = $container->get(StatementImporter::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $this->supplierId = (int) ($this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ($this->statementIds as $id) {
            $pdo->prepare('DELETE FROM bank_transactions WHERE statement_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$id]);
        }
        foreach ($this->currencyIds as $id) {
            $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testFioEurStatementUsesRegisteredAccountCurrencyDespiteCzkTxField(): void
    {
        // EUR účet registrovaný domácím číslem; Fio v 075 hlásí "00203" (CZK).
        $account = '9990562236';
        $this->registerCurrency('EUR', accountNumber: $account, bankCode: '2010');

        $r = $this->import($this->gpc($account, txCurrency: '00203'));

        $this->assertStatementCurrency($r['statement_id'], 'EUR',
            'měna registrovaného účtu musí přebít Fio konstantu 0203');
        $this->assertTransactionCurrencies($r['statement_id'], 'EUR',
            'per-tx CZK z Fio výpisu by rozbilo currency guard v matcheru');
    }

    public function testFioEurStatementMatchesAccountRegisteredByIbanOnly(): void
    {
        // EUR účet evidovaný JEN IBANem (typické pro cizoměnové účty — #109).
        $account = '9990562237';
        $this->registerCurrency('EUR', iban: 'CZ65 2010 0000 0099 9056 2237');

        $r = $this->import($this->gpc($account, txCurrency: '00203'));

        $this->assertStatementCurrency($r['statement_id'], 'EUR',
            'lookup musí najít účet i přes domácí část IBANu');
        $this->assertTransactionCurrencies($r['statement_id'], 'EUR');
    }

    public function testUnregisteredAccountFallsBackToPerTxCurrency(): void
    {
        // Neregistrovaný účet + banka plnící reálný kód (CREDITAS 00978) —
        // původní fallback chování musí zůstat (EUR výpis nesmí spadnout na NULL/CZK).
        $account = '9990562238';

        $r = $this->import($this->gpc($account, txCurrency: '00978'));

        $this->assertStatementCurrency($r['statement_id'], 'EUR',
            'bez registrace účtu rozhoduje per-tx kód (Creditas case)');
        $this->assertTransactionCurrencies($r['statement_id'], 'EUR');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function registerCurrency(
        string $code,
        ?string $accountNumber = null,
        ?string $bankCode = null,
        ?string $iban = null,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                 account_number, bank_code, iban)
             VALUES (?, ?, ?, ?, ?, ?, 2, 0, 0, ?, ?, ?)'
        )->execute([
            $this->supplierId, $code, "TEST {$code} #109", $code, $code, $code,
            $accountNumber, $bankCode, $iban !== null ? str_replace(' ', '', $iban) : null,
        ]);
        $this->currencyIds[] = (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array{statement_id:int, transactions:int} */
    private function import(string $content): array
    {
        $r = $this->importer->import($content, 'TEST-109.gpc', null);
        $this->assertFalse($r['duplicate'], 'testovací GPC nesmí být dedupnuté');
        $this->statementIds[] = $r['statement_id'];
        return $r;
    }

    private function assertStatementCurrency(int $statementId, string $expected, string $message = ''): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT currency FROM bank_statements WHERE id = ?');
        $stmt->execute([$statementId]);
        $this->assertSame($expected, (string) $stmt->fetchColumn(), $message);
    }

    private function assertTransactionCurrencies(int $statementId, string $expected, string $message = ''): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT DISTINCT currency FROM bank_transactions WHERE statement_id = ?');
        $stmt->execute([$statementId]);
        $this->assertSame([$expected], $stmt->fetchAll(PDO::FETCH_COLUMN), $message);
    }

    /**
     * Minimální validní GPC (074 header + 2× 075 transakce) se zadaným per-tx
     * kódem měny — layout přesně dle GpcParser (fixed-width, viz reálný Fio výpis).
     */
    private function gpc(string $account, string $txCurrency): string
    {
        $acc16 = str_pad($account, 16, '0', STR_PAD_LEFT);
        $header = '074' . $acc16
            . str_pad('TEST UCET 109', 20)                    // account name (20)
            . '010326'                                         // old balance date
            . str_pad('1337', 14, '0', STR_PAD_LEFT) . '+'     // old balance
            . str_pad('133700', 14, '0', STR_PAD_LEFT) . '+'   // new balance
            . str_pad('0', 14, '0', STR_PAD_LEFT) . '+'        // debit total
            . str_pad('132363', 14, '0', STR_PAD_LEFT) . '+'   // credit total
            . '003'                                            // statement number
            . '310326'                                         // statement date
            . 'FIO';

        $tx = fn (string $doc, string $amountCents, string $code, string $name) => '075' . $acc16
            . str_pad('', 16, '0')                             // counterparty account
            . str_pad($doc, 13, '0', STR_PAD_LEFT)             // doc number
            . str_pad($amountCents, 12, '0', STR_PAD_LEFT)     // amount (haléře/centy)
            . $code                                            // 1=debit, 2=credit
            . str_pad('', 10, '0')                             // VS
            . '00'                                             // filler
            . '0000'                                           // counterparty bank code
            . '0000'                                           // KS
            . str_pad('', 10, '0')                             // SS
            . '120326'                                         // value date
            . str_pad($name, 20)                               // client name (20)
            . $txCurrency                                      // currency (5) — testovaný vstup
            . '120326';                                        // posting date

        return $header . "\r\n"
            . $tx('10000000001', '92518', '2', 'PRICHOZI TEST EUR') . "\r\n"
            . $tx('10000000002', '39845', '1', 'Platba prevodem') . "\r\n";
    }
}
