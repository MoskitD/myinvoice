<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Generuje var. symbol (číslo faktury).
 *
 * Resolver template per (supplier, type) — supplier.{type}_number_format
 * má prioritu, fallback na cfg.varsymbol.templates.{type}. Period scope
 * (year/month/none) řídí, kdy se counter resetuje (supplier.invoice_number_period;
 * legacy default 'month' pro zpětnou kompatibilitu).
 *
 * Counter se atomicky inkrementuje v `invoice_counters` per (supplier_id, invoice_type, period).
 *
 * Placeholdery v template:
 *   {YYYY} = 4-digit year      ("2026")
 *   {YY}   = 2-digit year      ("26")
 *   {MM}   = 2-digit month     ("04")
 *   {C+}   = counter, padding podle počtu C ({CCC} → 3 znaky 001..999)
 *
 * Příklady:
 *   "JD{YYYY}-{CC}"      → "JD2026-02"      (period=year)
 *   "{YYYY}{MM}{CCC}"    → "202604001"      (period=month, default)
 *   "9{YY}{MM}{CCC}"     → "92604001"       (proforma, prefix 9)
 *   "F-{YYYY}/{CCCCCC}"  → "F-2026/000042"
 *
 * Prefix se píše rovnou do template stringu (žádný separátní `prefix` field).
 */
final class VarsymbolGenerator
{
    private const SUPPORTED_TYPES = ['invoice', 'proforma', 'credit_note'];
    private const VALID_PERIODS   = ['year', 'month', 'none'];
    private const DEFAULT_PERIOD  = 'month';

    public function __construct(
        private readonly Config $config,
        private readonly Connection $db,
    ) {}

    /**
     * Atomicky vygeneruje další var. symbol pro daný typ a datum.
     *
     * Pokud má faktura už ručně zadaný varsymbol (override), volající ho použije přímo
     * a tuto metodu nezavolá — viz IssueInvoiceAction.
     *
     * @throws \InvalidArgumentException pokud typ nemá template ani v supplier ani v cfg
     */
    public function next(int $supplierId, string $invoiceType, ?\DateTimeInterface $for = null): string
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException("Neplatný supplier_id: {$supplierId}");
        }
        if (!in_array($invoiceType, self::SUPPORTED_TYPES, true)) {
            throw new \InvalidArgumentException("Nepodporovaný typ pro varsymbol: {$invoiceType}");
        }

        [$template, $period] = $this->resolveTemplateAndPeriod($supplierId, $invoiceType);
        if ($template === '') {
            throw new \InvalidArgumentException(
                "Chybí template pro {$invoiceType}: nastav v Systém → Dodavatelé → Číslování faktur,"
                . " nebo doplň cfg.varsymbol.templates.{$invoiceType}."
            );
        }

        $for       = $for ?? new \DateTimeImmutable('today');
        $periodKey = $this->makePeriodKey($period, $for);
        $next      = $this->incrementCounter($supplierId, $invoiceType, $periodKey);

        return $this->render($template, $for, $next);
    }

    /**
     * Vrátí, jaký bude další varsymbol BEZ inkrementu (pro náhled v UI).
     */
    public function preview(int $supplierId, string $invoiceType, ?\DateTimeInterface $for = null): string
    {
        if ($supplierId <= 0) return '';
        if (!in_array($invoiceType, self::SUPPORTED_TYPES, true)) return '';

        [$template, $period] = $this->resolveTemplateAndPeriod($supplierId, $invoiceType);
        if ($template === '') return '';

        $for       = $for ?? new \DateTimeImmutable('today');
        $periodKey = $this->makePeriodKey($period, $for);

        $stmt = $this->db->pdo()->prepare(
            'SELECT last_number FROM invoice_counters WHERE supplier_id = ? AND invoice_type = ? AND period = ?'
        );
        $stmt->execute([$supplierId, $invoiceType, $periodKey]);
        $current = (int) ($stmt->fetchColumn() ?: 0);

        return $this->render($template, $for, $current + 1);
    }

    public function render(string $template, \DateTimeInterface $date, int $counter): string
    {
        $vars = [
            '{YYYY}' => $date->format('Y'),
            '{YY}'   => $date->format('y'),
            '{MM}'   => $date->format('m'),
        ];
        $rendered = strtr($template, $vars);

        // Counter: matchuj sekvenci {CC...} pro variabilní padding ({C}, {CC}, {CCCCCC}, ...)
        $rendered = preg_replace_callback('/\{(C+)\}/', function ($m) use ($counter) {
            $len = strlen($m[1]);
            return str_pad((string) $counter, $len, '0', STR_PAD_LEFT);
        }, $rendered) ?? $rendered;

        return $rendered;
    }

    /**
     * @return array{0: string, 1: string} [template, period]
     */
    private function resolveTemplateAndPeriod(int $supplierId, string $invoiceType): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT invoice_number_format, proforma_number_format, credit_note_number_format,
                    invoice_number_period
               FROM supplier WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $perSupplierColumn = match ($invoiceType) {
            'invoice'     => 'invoice_number_format',
            'proforma'    => 'proforma_number_format',
            'credit_note' => 'credit_note_number_format',
        };
        $supplierTemplate = trim((string) ($row[$perSupplierColumn] ?? ''));

        // Per-supplier override má přednost; pokud je prázdný, fallback na global cfg.
        $template = $supplierTemplate !== ''
            ? $supplierTemplate
            : (string) $this->config->get("varsymbol.templates.{$invoiceType}", '');

        $period = (string) ($row['invoice_number_period'] ?? self::DEFAULT_PERIOD);
        if (!in_array($period, self::VALID_PERIODS, true)) {
            $period = self::DEFAULT_PERIOD;
        }

        return [$template, $period];
    }

    /**
     * Klíč scope pro invoice_counters.period:
     *   year  → "2026"
     *   month → "202604"   (zpětně kompatibilní s legacy CHAR(6))
     *   none  → "ALL"      (jediný globální counter pro daný supplier+type)
     */
    private function makePeriodKey(string $period, \DateTimeInterface $for): string
    {
        return match ($period) {
            'year'  => $for->format('Y'),
            'none'  => 'ALL',
            default => $for->format('Ym'),
        };
    }

    private function incrementCounter(int $supplierId, string $invoiceType, string $periodKey): int
    {
        $pdo = $this->db->pdo();

        // Atomický INSERT/UPDATE — single statement, žádná race condition
        $stmt = $pdo->prepare(
            'INSERT INTO invoice_counters (supplier_id, invoice_type, period, last_number)
             VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE last_number = last_number + 1'
        );
        $stmt->execute([$supplierId, $invoiceType, $periodKey]);

        $stmt = $pdo->prepare(
            'SELECT last_number FROM invoice_counters WHERE supplier_id = ? AND invoice_type = ? AND period = ?'
        );
        $stmt->execute([$supplierId, $invoiceType, $periodKey]);
        return (int) $stmt->fetchColumn();
    }
}
