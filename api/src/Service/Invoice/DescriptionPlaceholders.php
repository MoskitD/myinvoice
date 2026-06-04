<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use DateTimeImmutable;

/**
 * Placeholdery v textech pravidelné fakturace (#108) — popisy položek a poznámky.
 *
 * Vyhodnocují se při generování faktury ze šablony vůči referenčnímu datu
 * (DUZP faktury; u proformy datum vystavení — stejná logika jako
 * {@see MonthSynchronizer}). Šablonová hodnota se nikdy nemění, do faktury
 * jde vyhodnocený snapshot.
 *
 * Podporované tokeny (STRIKTNĚ velká písmena; pro ref. datum 15. 5. 2026):
 *   {YYYY} {YY}            2026, 26          — rok; offset po letech: {YYYY+1} → 2027
 *   {M} {MM}               5, 05             — měsíc; offset po MĚSÍCÍCH vč. přetečení
 *                                              roku: {MM+8} → 01
 *   {MMMM}                 květen / May      — název měsíce dle jazyka faktury (cs/en),
 *                                              offset po měsících: {MMMM+1} → červen
 *   {Q}                    2                 — čtvrtletí 1-4; offset po čtvrtletích
 *   {D} {DD}               15, 15            — den; offset po dnech
 *   {DATE}                 15. 5. 2026       — celé ref. datum, formát dle jazyka
 *                                              (cs „j. n. Y", en „M j, Y" — stejné jako PDF)
 *   {DATE+1Y-1D}           14. 5. 2027       — datová aritmetika: kombinace ±N D/M/Y,
 *                                              vyhodnocuje se zleva doprava
 *
 * Nerozpoznané tokeny ({FOO}, {yyyy}, …) zůstávají netknuté → žádné escapování
 * není potřeba a stávající šablony fungují beze změny.
 */
final class DescriptionPlaceholders
{
    /** Měsíce česky — PHP format('F') je jen anglicky a intl nemusí být k dispozici. */
    private const MONTHS_CS = [
        1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen',
        'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec',
    ];

    public static function apply(string $text, DateTimeImmutable $ref, string $lang = 'cs'): string
    {
        if (!str_contains($text, '{')) {
            return $text;
        }

        // Den v měsíci může při posunu po měsících přetéct (31. 5. +1M → 1. 7. v PHP);
        // pro tokeny roku/měsíce/čtvrtletí na dni nezáleží → kotvíme na 1. den měsíce.
        $monthAnchor = $ref->setDate((int) $ref->format('Y'), (int) $ref->format('n'), 1);

        $result = preg_replace_callback(
            '/\{(YYYY|YY|MMMM|MM|M|Q|DD|D)([+-]\d{1,3})?\}|\{DATE((?:[+-]\d{1,3}[DMY])*)\}/',
            static function (array $m) use ($ref, $monthAnchor, $lang): string {
                // {DATE…} větev — token group 1 je prázdný string.
                if (($m[1] ?? '') === '') {
                    return self::formatDate(self::shiftDate($ref, $m[3] ?? ''), $lang);
                }
                $offset = (int) ($m[2] ?? 0);
                return match ($m[1]) {
                    'YYYY' => $monthAnchor->modify(sprintf('%+d years', $offset))->format('Y'),
                    'YY'   => $monthAnchor->modify(sprintf('%+d years', $offset))->format('y'),
                    'MMMM' => self::monthName($monthAnchor->modify(sprintf('%+d months', $offset)), $lang),
                    'MM'   => $monthAnchor->modify(sprintf('%+d months', $offset))->format('m'),
                    'M'    => $monthAnchor->modify(sprintf('%+d months', $offset))->format('n'),
                    'Q'    => (string) (intdiv((int) $monthAnchor->modify(sprintf('%+d months', $offset * 3))->format('n') - 1, 3) + 1),
                    'DD'   => $ref->modify(sprintf('%+d days', $offset))->format('d'),
                    'D'    => $ref->modify(sprintf('%+d days', $offset))->format('j'),
                    default => $m[0],
                };
            },
            $text,
        );

        return $result ?? $text;
    }

    /** Datová aritmetika pro {DATE…}: sekvence ±N[DMY] aplikovaná zleva doprava. */
    private static function shiftDate(DateTimeImmutable $ref, string $ops): DateTimeImmutable
    {
        if ($ops === '') {
            return $ref;
        }
        preg_match_all('/([+-]\d{1,3})([DMY])/', $ops, $all, PREG_SET_ORDER);
        foreach ($all as [, $n, $unit]) {
            $ref = $ref->modify(sprintf('%+d %s', (int) $n, match ($unit) {
                'D' => 'days', 'M' => 'months', default => 'years',
            }));
        }
        return $ref;
    }

    private static function formatDate(DateTimeImmutable $d, string $lang): string
    {
        // Stejné formáty jako InvoicePdfRenderer / WorkReportPdfRenderer.
        return $d->format($lang === 'en' ? 'M j, Y' : 'j. n. Y');
    }

    private static function monthName(DateTimeImmutable $d, string $lang): string
    {
        $n = (int) $d->format('n');
        return $lang === 'en' ? $d->format('F') : self::MONTHS_CS[$n];
    }
}
