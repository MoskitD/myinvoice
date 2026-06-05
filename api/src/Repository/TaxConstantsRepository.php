<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\TaxConstants;

/**
 * Roční daňové konstanty: DB override (tabulka `tax_constants`, migrace 0079)
 * s fallbackem na ověřené defaulty v {@see TaxConstants}.
 *
 * Záměr: kód drží jediný ověřený zdroj (TaxConstants), admin může přes číselník
 * roční hodnoty přepsat bez nového release. Override = jeden řádek JSON na rok.
 */
final class TaxConstantsRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Efektivní konstanty pro rok: DB override má přednost per klíč, chybějící
     * klíče doplní default z kódu (override uložený starší verzí aplikace nezná
     * později přidané konstanty — bez merge by je "ztratil").
     * @return array<string,mixed>
     */
    public function forYear(int $year): array
    {
        $default = TaxConstants::forYear($year);
        $override = $this->override($year);
        return $override !== null ? array_replace($default, $override) : $default;
    }

    /** Limit KH pro rozdělení A.4/A.5 a B.2/B.3 (nad → jednotlivě, do → sumace). */
    public function khItemThreshold(int $year): float
    {
        return (float) $this->forYear($year)['kh_item_threshold'];
    }

    /** Základní sazba DPH (§ 47 ZDPH) pro rok — např. pro samovyměření RC. */
    public function vatRateStandard(int $year): float
    {
        return (float) $this->forYear($year)['vat_rate_standard'];
    }

    /**
     * Práh pro bucket "základní vs snížená sazba" (EPO formuláře mají právě dva
     * sloupce zakl_dane1/zakl_dane2) = střed mezi sazbami daného roku. Pro 21/12 %
     * je to 16,5 — řadí korektně i historickou sníženou 15 % (< 16,5 → snížená),
     * a na rozdíl od dřívějšího natvrdo 20,5 přežije změnu základní sazby.
     */
    public function vatBucketThreshold(int $year): float
    {
        $c = $this->forYear($year);
        return ((float) $c['vat_rate_standard'] + (float) $c['vat_rate_reduced']) / 2.0;
    }

    /**
     * DB override pro rok, nebo null když není.
     * @return array<string,mixed>|null
     */
    public function override(int $year): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT data FROM tax_constants WHERE year = ?');
        $stmt->execute([$year]);
        $json = $stmt->fetchColumn();
        if ($json === false || $json === null) {
            return null;
        }
        $data = json_decode((string) $json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Seznam roků pro editor: sjednocení defaultních roků a roků s DB override,
     * každý s efektivními daty a příznakem, zda jde o override.
     * @return list<array{year:int,is_override:bool,data:array<string,mixed>}>
     */
    public function listEffective(): array
    {
        $dbYears = array_map(
            'intval',
            $this->db->pdo()->query('SELECT year FROM tax_constants')->fetchAll(\PDO::FETCH_COLUMN)
        );
        $all = array_values(array_unique([...TaxConstants::availableYears(), ...$dbYears]));
        rsort($all);

        $out = [];
        foreach ($all as $year) {
            $override = $this->override($year);
            $out[] = [
                'year'        => $year,
                'is_override' => $override !== null,
                // Merge jako forYear() — starý override nesmí v editoru "ztratit"
                // později přidané konstanty.
                'data'        => $override !== null
                    ? array_replace(TaxConstants::forYear($year), $override)
                    : TaxConstants::forYear($year),
            ];
        }
        return $out;
    }

    /**
     * Uloží/přepíše override pro rok.
     * @param array<string,mixed> $data
     */
    public function upsert(int $year, array $data): void
    {
        $data['year'] = $year; // konzistence s klíčem
        $json = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->db->pdo()->prepare(
            'INSERT INTO tax_constants (year, data) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE data = VALUES(data)'
        )->execute([$year, $json]);
    }

    /** Smaže override (reset na default). Vrací true, pokud řádek existoval. */
    public function reset(int $year): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM tax_constants WHERE year = ?');
        $stmt->execute([$year]);
        return $stmt->rowCount() > 0;
    }
}
