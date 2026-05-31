<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Sjednocené seedování ruční rekapitulace DPH (§ 73 ZDPH) na přijaté faktuře
 * z rekapitulace UVEDENÉ NA DOKLADU dodavatele (ISDOC TaxTotal, Pohoda summary,
 * iDoklad per-line Prices, AI vat_recap).
 *
 * Proč: dopočet DPH zdola z řádků (base × sazba) se u vícesazbových dokladů
 * haléřově rozchází s rekapitulací, kterou dodavatel na dokladu skutečně uvádí.
 * Pro nárok na odpočet (§ 73 odst. 6) musí naše evidence sedět na doklad. Override
 * se zapeče do řádkových totálů (InvoiceMath::applyRateOverrides) — VatLedger pak
 * čte uložené per-řádkové hodnoty.
 *
 * Tolerance (per měna): CZK → 1 Kč, ostatní → 0,1 jednotky.
 *   - rozdíl ≤ tolerance         → override zapíšeme tiše (haléřový drift)
 *   - tolerance < rozdíl ≤ limit → override zapíšeme + varování (doklad má přednost)
 *   - rozdíl > limit             → override NEZAPÍŠEME, jen varování s konkrétními
 *                                  hodnotami (doklad vs dopočet) — „úplně mimo",
 *                                  typicky chyba parsování / špatný doklad.
 * limit = max(10× tolerance, 1 % základu dané sazby).
 *
 * Aplikuje se jen v režimu ZDOLA (v režimu shora celek sedí koeficientem) a na
 * běžné faktury (ne dobropisy — znaménka). Sazba 0 % / osvobozeno se ignoruje.
 */
final class PurchaseVatRecapSeeder
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly PurchaseInvoiceCalculator $calc,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Naseeduje override a vrátí případný varovný text (rozdíl dokladu vs dopočtu
     * nad tolerancí). Text se ZÁMĚRNĚ jen vrací (nezapisuje do DB) — volající ho
     * zapíše přes {@see PurchaseInvoiceRepository::appendExtractionWarning()} ve
     * vhodný okamžik, aby ho nepřepsala jiná varování (např. AI mismatch hláška).
     *
     * @param array<string,array{base?:float,vat?:float}> $docByRate
     *        rateKey (number_format(rate,2,'.','')) => kladné base/vat z dokladu
     * @return string|null varovný text, nebo null (vše v toleranci / nic k porovnání)
     */
    public function seed(
        int $id,
        int $supplierId,
        array $docByRate,
        string $currencyCode,
        bool $isCredit,
        ?float $docTotalBase = null,
        ?float $docTotalWithVat = null,
    ): ?string {
        if ($isCredit) {
            return null; // dobropisy neseedujeme (znaménka)
        }
        $invoice = $this->repo->find($id, $supplierId);
        if ($invoice === null) {
            return null;
        }

        $computed = self::computedRecap($invoice);
        if ($computed === []) {
            return null;
        }

        $docByRate = self::normalizeDocRecap($docByRate);
        if ($docByRate === []) {
            $docByRate = self::singleRateFromTotals($computed, $docTotalBase, $docTotalWithVat);
        }
        if ($docByRate === []) {
            return null;
        }

        $decision = self::decide($computed, $docByRate, $currencyCode);

        if ($decision['overrides'] !== []) {
            try {
                $this->repo->setVatOverrides($id, $supplierId, $decision['overrides']);
                $this->calc->recompute($id);
                $this->logger->info('VatRecapSeeder: naseedován override rekapitulace DPH dle dokladu', [
                    'invoice_id' => $id,
                    'rates'      => array_column($decision['overrides'], 'rate'),
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('VatRecapSeeder: setVatOverrides selhalo', [
                    'invoice_id' => $id,
                    'error'      => $e->getMessage(),
                ]);
                return null;
            }
        }

        return self::formatWarning($decision['warnings']);
    }

    /**
     * Vypočtená rekapitulace per sazba z `vat_breakdown` faktury (po recompute).
     * Sazba 0 % se vynechá (není co srovnávat / override 0 % nedává smysl).
     *
     * @param array<string,mixed> $invoice
     * @return array<string,array{rate:float,base:float,vat:float}>
     */
    public static function computedRecap(array $invoice): array
    {
        $computed = [];
        foreach ($invoice['vat_breakdown'] ?? [] as $b) {
            $rate = (float) ($b['vat_rate'] ?? 0);
            if ($rate <= 0.0) {
                continue;
            }
            $computed[number_format($rate, 2, '.', '')] = [
                'rate' => $rate,
                'base' => (float) ($b['without_vat'] ?? 0),
                'vat'  => (float) ($b['vat'] ?? 0),
            ];
        }
        return $computed;
    }

    /**
     * Čistá rozhodovací logika (bez DB) — pro každou společnou sazbu rozhodne, zda
     * override zapsat a/nebo varovat. Viz tolerance v doc-comment třídy.
     *
     * @param array<string,array{rate:float,base:float,vat:float}> $computed
     * @param array<string,array{base:float,vat:float}> $docByRate
     * @return array{
     *     overrides: list<array{rate:float,base:float,vat:float}>,
     *     warnings:  list<array{rate:float,doc:array{base:float,vat:float},computed:array{base:float,vat:float},written:bool}>
     * }
     */
    public static function decide(array $computed, array $docByRate, string $currencyCode): array
    {
        $tol = self::toleranceFor($currencyCode);
        $overrides = [];
        $warnings = [];
        foreach ($docByRate as $key => $doc) {
            if (!isset($computed[$key])) {
                continue; // sazba z dokladu, která na faktuře není → ignoruj
            }
            $c = $computed[$key];
            $baseDiff = abs($c['base'] - $doc['base']);
            $vatDiff  = abs($c['vat'] - $doc['vat']);
            $maxDiff  = max($baseDiff, $vatDiff);
            if ($maxDiff <= 0.0) {
                continue; // přesná shoda → override = no-op
            }
            $hardLimit = max(10.0 * $tol, 0.01 * max($doc['base'], 0.0));
            if ($maxDiff <= $tol) {
                $overrides[] = ['rate' => $c['rate'], 'base' => $doc['base'], 'vat' => $doc['vat']];
            } elseif ($maxDiff <= $hardLimit) {
                $overrides[] = ['rate' => $c['rate'], 'base' => $doc['base'], 'vat' => $doc['vat']];
                $warnings[]  = ['rate' => $c['rate'], 'doc' => $doc, 'computed' => ['base' => $c['base'], 'vat' => $c['vat']], 'written' => true];
            } else {
                $warnings[] = ['rate' => $c['rate'], 'doc' => $doc, 'computed' => ['base' => $c['base'], 'vat' => $c['vat']], 'written' => false];
            }
        }
        return ['overrides' => $overrides, 'warnings' => $warnings];
    }

    /**
     * Sestaví lidsky čitelné varování z výstupu {@see decide()}.
     *
     * @param list<array{rate:float,doc:array{base:float,vat:float},computed:array{base:float,vat:float},written:bool}> $warnings
     */
    public static function formatWarning(array $warnings): ?string
    {
        if ($warnings === []) {
            return null;
        }
        $lines = [];
        foreach ($warnings as $w) {
            $tail = $w['written']
                ? 'zapsáno dle dokladu'
                : 'příliš velký rozdíl, ponechán dopočet z řádků';
            $lines[] = sprintf(
                '• Sazba %s %%: doklad základ %s / DPH %s vs dopočet základ %s / DPH %s — %s.',
                self::num($w['rate']),
                self::num($w['doc']['base']),
                self::num($w['doc']['vat']),
                self::num($w['computed']['base']),
                self::num($w['computed']['vat']),
                $tail,
            );
        }
        return "Rekapitulace DPH z dokladu se liší od dopočtu z řádků:\n" . implode("\n", $lines);
    }

    public static function toleranceFor(string $currencyCode): float
    {
        return strtoupper(trim($currencyCode)) === 'CZK' ? 1.0 : 0.1;
    }

    /**
     * @param array<string,array{base?:float|int,vat?:float|int}> $docByRate
     * @return array<string,array{base:float,vat:float}>
     */
    private static function normalizeDocRecap(array $docByRate): array
    {
        $out = [];
        foreach ($docByRate as $key => $r) {
            if (!is_array($r) || !isset($r['base'], $r['vat'])) {
                continue;
            }
            $out[(string) $key] = ['base' => abs((float) $r['base']), 'vat' => abs((float) $r['vat'])];
        }
        return $out;
    }

    /**
     * Jednosazbový doklad → odvoď rekapitulaci z celkových součtů.
     *
     * @param array<string,array{rate:float,base:float,vat:float}> $computed
     * @return array<string,array{base:float,vat:float}>
     */
    private static function singleRateFromTotals(array $computed, ?float $base, ?float $withVat): array
    {
        if (count($computed) !== 1 || $base === null || $withVat === null) {
            return [];
        }
        $base = abs($base);
        $withVat = abs($withVat);
        if ($base <= 0.0) {
            return [];
        }
        $vat = round($withVat - $base, 2);
        if ($vat < 0.0) {
            return [];
        }
        $key = (string) array_key_first($computed);
        return [$key => ['base' => $base, 'vat' => $vat]];
    }

    private static function num(float $v): string
    {
        return number_format($v, 2, ',', ' ');
    }
}
