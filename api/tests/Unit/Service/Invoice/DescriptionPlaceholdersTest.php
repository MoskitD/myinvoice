<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\DescriptionPlaceholders;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Placeholdery v pravidelné fakturaci (#108). Referenční datum 15. 5. 2026
 * (DUZP; u proformy issue_date) — viz RecurringInvoiceGenerator.
 */
final class DescriptionPlaceholdersTest extends TestCase
{
    private static function ref(string $date = '2026-05-15'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    #[DataProvider('placeholderCases')]
    public function testApply(string $template, string $expected): void
    {
        self::assertSame($expected, DescriptionPlaceholders::apply($template, self::ref(), 'cs'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function placeholderCases(): iterable
    {
        // Rok
        yield 'YYYY'        => ['{YYYY}', '2026'];
        yield 'YY'          => ['{YY}', '26'];
        yield 'YYYY+1'      => ['{YYYY+1}', '2027'];
        yield 'YYYY-1'      => ['{YYYY-1}', '2025'];
        yield 'YY+1'        => ['{YY+1}', '27'];
        yield 'sezona'      => ['sezóna {YY}/{YY+1}', 'sezóna 26/27'];

        // Měsíc (offset po měsících, vč. přetečení roku)
        yield 'M'           => ['{M}', '5'];
        yield 'MM'          => ['{MM}', '05'];
        yield 'M+1'         => ['{M+1}', '6'];
        yield 'MM+8 rollover' => ['{MM+8}', '01'];
        yield 'MM-5 rollover' => ['{MM-5}', '12'];
        yield 'mesic/rok'   => ['{MM}/{YYYY}', '05/2026'];

        // Název měsíce (cs)
        yield 'MMMM'        => ['{MMMM}', 'květen'];
        yield 'MMMM+1'      => ['{MMMM+1}', 'červen'];
        yield 'MMMM+8'      => ['{MMMM+8}', 'leden'];

        // Čtvrtletí (offset po čtvrtletích)
        yield 'Q'           => ['{Q}', '2'];
        yield 'Q+1'         => ['{Q+1}', '3'];
        yield 'Q+3 rollover' => ['{Q+3}', '1'];
        yield 'Q-2'         => ['{Q-2}', '4'];

        // Den (offset po dnech)
        yield 'D'           => ['{D}', '15'];
        yield 'DD'          => ['{DD}', '15'];
        yield 'D+20'        => ['{D+20}', '4'];

        // Celé datum + aritmetika
        yield 'DATE'        => ['{DATE}', '15. 5. 2026'];
        yield 'DATE+1Y'     => ['{DATE+1Y}', '15. 5. 2027'];
        yield 'DATE+1Y-1D'  => ['{DATE+1Y-1D}', '14. 5. 2027'];
        yield 'DATE+14D'    => ['{DATE+14D}', '29. 5. 2026'];
        yield 'DATE+2M'     => ['{DATE+2M}', '15. 7. 2026'];
        yield 'domena use case' => [
            'Prodloužení domény example.cz na období {DATE} - {DATE+1Y-1D}',
            'Prodloužení domény example.cz na období 15. 5. 2026 - 14. 5. 2027',
        ];

        // Neznámé tokeny a běžný text zůstávají netknuté (zpětná kompatibilita)
        yield 'unknown token'   => ['{FOO} {PERIOD_START}', '{FOO} {PERIOD_START}'];
        yield 'lowercase'       => ['{yyyy} {date}', '{yyyy} {date}'];
        yield 'plain text'      => ['Hosting 05/2026', 'Hosting 05/2026'];
        yield 'empty braces'    => ['{}', '{}'];
        yield 'offset bez čísla' => ['{YYYY+}', '{YYYY+}'];
    }

    public function testEnglishLocaleFormats(): void
    {
        self::assertSame('May', DescriptionPlaceholders::apply('{MMMM}', self::ref(), 'en'));
        self::assertSame('May 15, 2026', DescriptionPlaceholders::apply('{DATE}', self::ref(), 'en'));
        self::assertSame('Jun', substr(DescriptionPlaceholders::apply('{DATE+1M}', self::ref(), 'en'), 0, 3));
    }

    public function testMonthOverflowAnchoredToFirstDay(): void
    {
        // 31. 5. +1 měsíc by v PHP dalo 1. 7. — pro tokeny měsíce kotvíme na 1. den,
        // takže {MM+1} z 31. 5. je červen, ne červenec.
        self::assertSame('06', DescriptionPlaceholders::apply('{MM+1}', self::ref('2026-05-31'), 'cs'));
        self::assertSame('únor', DescriptionPlaceholders::apply('{MMMM+1}', self::ref('2026-01-31'), 'cs'));
    }

    public function testDecemberYearRollover(): void
    {
        self::assertSame('1/2027', DescriptionPlaceholders::apply('{M+1}/{YYYY+1}', self::ref('2026-12-10'), 'cs'));
    }

    public function testIdempotentWithMonthSynchronizerOutput(): void
    {
        // Výstup placeholderů ("05/2026") následně projde MonthSynchronizer::syncTo
        // ke stejnému datu — nesmí se změnit.
        $evaluated = DescriptionPlaceholders::apply('Hosting {MM}/{YYYY}', self::ref(), 'cs');
        self::assertSame('Hosting 05/2026', $evaluated);
        self::assertSame(
            $evaluated,
            \MyInvoice\Service\Invoice\MonthSynchronizer::syncTo($evaluated, self::ref()),
        );
    }
}
