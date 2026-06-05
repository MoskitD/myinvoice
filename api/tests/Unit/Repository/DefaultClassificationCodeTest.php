<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Repository;

use MyInvoice\Repository\PurchaseInvoiceRepository;
use PHPUnit\Framework\TestCase;

/**
 * PurchaseInvoiceRepository::defaultClassificationCode — auto-klasifikace
 * přijatých dokladů podle sazby + RC + země dodavatele, nově s parametrem
 * základní sazby z číselníku daňových konstant (dřív natvrdo 21).
 */
final class DefaultClassificationCodeTest extends TestCase
{
    public function testDomesticByRate(): void
    {
        $f = fn (float $r, bool $rc = false, ?string $iso = 'CZ') =>
            PurchaseInvoiceRepository::defaultClassificationCode($r, $rc, $iso);

        $this->assertSame('40', $f(21.0), 'tuzemská základní');
        $this->assertSame('41', $f(12.0), 'tuzemská snížená');
        $this->assertSame('41', $f(15.0), 'historická snížená 15');
        $this->assertSame('5',  $f(21.0, rc: true), 'tuzemský RC');
        $this->assertNull($f(0.0), 'nulová sazba CZ → user vybere');
        $this->assertNull($f(19.0), 'cizí sazba (např. DE 19 %) se nemapuje');
    }

    public function testForeignVendorZeroRate(): void
    {
        $f = fn (string $iso) =>
            PurchaseInvoiceRepository::defaultClassificationCode(0.0, false, $iso);

        $this->assertSame('24', $f('DE'), 'EU vendor 0 % → přijetí služby z EU');
        $this->assertSame('24', $f('IE'));
        $this->assertSame('25', $f('US'), '3. země 0 % → dovoz');
        $this->assertSame('25', $f('GB'));
    }

    public function testEuReverseChargeStandardRate(): void
    {
        $this->assertSame(
            '23',
            PurchaseInvoiceRepository::defaultClassificationCode(21.0, true, 'DE'),
            'EU vendor + RC + základní sazba → pořízení zboží z JČS'
        );
    }

    public function testStandardRateParameterFromTaxConstants(): void
    {
        // Hypotetická budoucí změna základní sazby na 22 % — klasifikace se musí
        // řídit parametrem (číselník daňových konstant), ne zadrátovanou 21.
        $f = fn (float $r, float $std) =>
            PurchaseInvoiceRepository::defaultClassificationCode($r, false, 'CZ', $std);

        $this->assertSame('40', $f(22.0, 22.0), 'nová základní 22 → tuzemská základní');
        $this->assertNull($f(21.0, 22.0), 'stará 21 už není základní ani snížená (16–21) → user vybere');
        $this->assertSame('41', $f(12.0, 22.0), 'snížená beze změny');
        // RC varianty respektují tentýž parametr
        $this->assertSame('5', PurchaseInvoiceRepository::defaultClassificationCode(22.0, true, 'CZ', 22.0));
        $this->assertSame('23', PurchaseInvoiceRepository::defaultClassificationCode(22.0, true, 'DE', 22.0));
    }
}
