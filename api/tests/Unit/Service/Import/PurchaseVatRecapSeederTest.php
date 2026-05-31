<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\PurchaseVatRecapSeeder;
use PHPUnit\Framework\TestCase;

/**
 * Čistá rozhodovací logika seederu (bez DB): tolerance, tři tiery zápisu/varování,
 * formátování varování. DB cesta (seed()) je pokryta integračně jinde.
 */
final class PurchaseVatRecapSeederTest extends TestCase
{
    public function testToleranceCzkIsOneCrown(): void
    {
        self::assertSame(1.0, PurchaseVatRecapSeeder::toleranceFor('CZK'));
        self::assertSame(1.0, PurchaseVatRecapSeeder::toleranceFor('czk'));
        self::assertSame(1.0, PurchaseVatRecapSeeder::toleranceFor(' CZK '));
    }

    public function testToleranceForeignIsTenthOfUnit(): void
    {
        self::assertSame(0.1, PurchaseVatRecapSeeder::toleranceFor('EUR'));
        self::assertSame(0.1, PurchaseVatRecapSeeder::toleranceFor('USD'));
        self::assertSame(0.1, PurchaseVatRecapSeeder::toleranceFor('GBP'));
    }

    public function testWithinToleranceWritesOverrideSilently(): void
    {
        $computed = ['21.00' => ['rate' => 21.0, 'base' => 1000.00, 'vat' => 210.00]];
        $doc      = ['21.00' => ['base' => 1000.50, 'vat' => 210.10]]; // maxDiff 0,5 ≤ 1 Kč

        $d = PurchaseVatRecapSeeder::decide($computed, $doc, 'CZK');

        self::assertCount(1, $d['overrides']);
        self::assertSame(21.0, $d['overrides'][0]['rate']);
        self::assertSame(1000.50, $d['overrides'][0]['base']);
        self::assertSame(210.10, $d['overrides'][0]['vat']);
        self::assertSame([], $d['warnings']);
    }

    public function testExactMatchProducesNoOverrideNoWarning(): void
    {
        $computed = ['21.00' => ['rate' => 21.0, 'base' => 1000.00, 'vat' => 210.00]];
        $doc      = ['21.00' => ['base' => 1000.00, 'vat' => 210.00]];

        $d = PurchaseVatRecapSeeder::decide($computed, $doc, 'CZK');

        self::assertSame([], $d['overrides']);
        self::assertSame([], $d['warnings']);
    }

    public function testMidRangeWritesOverrideAndWarns(): void
    {
        // base 1000 → hardLimit = max(10×1, 1 % z 1005) = 10,05; diff 5 ≤ 10,05 → write+warn
        $computed = ['21.00' => ['rate' => 21.0, 'base' => 1000.00, 'vat' => 210.00]];
        $doc      = ['21.00' => ['base' => 1005.00, 'vat' => 211.00]];

        $d = PurchaseVatRecapSeeder::decide($computed, $doc, 'CZK');

        self::assertCount(1, $d['overrides']);
        self::assertSame(1005.00, $d['overrides'][0]['base']);
        self::assertCount(1, $d['warnings']);
        self::assertTrue($d['warnings'][0]['written']);
        self::assertSame(21.0, $d['warnings'][0]['rate']);
    }

    public function testWildlyOffDoesNotWriteButWarns(): void
    {
        // base 1000 → hardLimit ≈ 11; diff 100 > 11 → „úplně mimo": nezapsat, jen varovat
        $computed = ['21.00' => ['rate' => 21.0, 'base' => 1000.00, 'vat' => 210.00]];
        $doc      = ['21.00' => ['base' => 1100.00, 'vat' => 231.00]];

        $d = PurchaseVatRecapSeeder::decide($computed, $doc, 'CZK');

        self::assertSame([], $d['overrides']);
        self::assertCount(1, $d['warnings']);
        self::assertFalse($d['warnings'][0]['written']);
        // varování musí nést konkrétní hodnoty z dokladu (co tam bylo)
        self::assertSame(['base' => 1100.00, 'vat' => 231.00], $d['warnings'][0]['doc']);
        self::assertSame(['base' => 1000.00, 'vat' => 210.00], $d['warnings'][0]['computed']);
    }

    public function testForeignCurrencyUsesTenthTolerance(): void
    {
        // EUR: diff 0,05 ≤ 0,1 → tiše zapsat
        $computed = ['21.00' => ['rate' => 21.0, 'base' => 100.00, 'vat' => 21.00]];
        $doc      = ['21.00' => ['base' => 100.05, 'vat' => 21.00]];

        $d = PurchaseVatRecapSeeder::decide($computed, $doc, 'EUR');

        self::assertCount(1, $d['overrides']);
        self::assertSame([], $d['warnings']);
    }

    public function testForeignCurrencyMidRangeWarns(): void
    {
        // EUR base 100 → hardLimit = max(10×0,1=1, 1 % z 100,5 ≈ 1,005) = 1,005; diff 0,5 ≤ → write+warn
        $computed = ['21.00' => ['rate' => 21.0, 'base' => 100.00, 'vat' => 21.00]];
        $doc      = ['21.00' => ['base' => 100.50, 'vat' => 21.10]];

        $d = PurchaseVatRecapSeeder::decide($computed, $doc, 'EUR');

        self::assertCount(1, $d['overrides']);
        self::assertCount(1, $d['warnings']);
        self::assertTrue($d['warnings'][0]['written']);
    }

    public function testRateOnDocumentNotOnInvoiceIsIgnored(): void
    {
        $computed = ['21.00' => ['rate' => 21.0, 'base' => 1000.00, 'vat' => 210.00]];
        $doc      = [
            '21.00' => ['base' => 1000.00, 'vat' => 210.00],
            '12.00' => ['base' => 500.00, 'vat' => 60.00], // na faktuře není
        ];

        $d = PurchaseVatRecapSeeder::decide($computed, $doc, 'CZK');

        self::assertSame([], $d['overrides']);
        self::assertSame([], $d['warnings']);
    }

    public function testMultiRateMixedTiers(): void
    {
        $computed = [
            '21.00' => ['rate' => 21.0, 'base' => 1000.00, 'vat' => 210.00],
            '12.00' => ['rate' => 12.0, 'base' => 500.00, 'vat' => 60.00],
        ];
        $doc = [
            '21.00' => ['base' => 1000.40, 'vat' => 210.00], // ≤1 → tiše
            '12.00' => ['base' => 600.00,  'vat' => 72.00],  // diff 100 > hardLimit → nezapsat, varovat
        ];

        $d = PurchaseVatRecapSeeder::decide($computed, $doc, 'CZK');

        self::assertCount(1, $d['overrides']); // jen 21 %
        self::assertSame(21.0, $d['overrides'][0]['rate']);
        self::assertCount(1, $d['warnings']);  // jen 12 %
        self::assertSame(12.0, $d['warnings'][0]['rate']);
        self::assertFalse($d['warnings'][0]['written']);
    }

    public function testFormatWarningNullWhenEmpty(): void
    {
        self::assertNull(PurchaseVatRecapSeeder::formatWarning([]));
    }

    public function testFormatWarningContainsConcreteValuesAndStatus(): void
    {
        $warnings = [
            ['rate' => 21.0, 'doc' => ['base' => 1005.00, 'vat' => 211.00], 'computed' => ['base' => 1000.00, 'vat' => 210.00], 'written' => true],
            ['rate' => 12.0, 'doc' => ['base' => 600.00, 'vat' => 72.00], 'computed' => ['base' => 500.00, 'vat' => 60.00], 'written' => false],
        ];

        $text = PurchaseVatRecapSeeder::formatWarning($warnings);

        self::assertNotNull($text);
        self::assertStringContainsString('Sazba 21,00 %', $text);
        self::assertStringContainsString('1 005,00', $text);          // doklad
        self::assertStringContainsString('zapsáno dle dokladu', $text);
        self::assertStringContainsString('Sazba 12,00 %', $text);
        self::assertStringContainsString('600,00', $text);
        self::assertStringContainsString('ponechán dopočet', $text);
    }
}
