<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Repository;

use MyInvoice\Repository\InvoiceRepository;
use PHPUnit\Framework\TestCase;

/**
 * Pure-function test auto-klasifikace prodejních položek pro DPH přiznání / KH
 * (InvoiceRepository::defaultSaleClassificationCode). Pokrývá tuzemsko vs.
 * reverse charge do zahraničí (EU služby / vývoz mimo EU).
 */
final class SaleClassificationCodeTest extends TestCase
{
    public function testDomesticStandardRate(): void
    {
        // Tuzemská základní sazba → '1' (ř. 1 přiznání, KH A.4/A.5).
        self::assertSame('1', InvoiceRepository::defaultSaleClassificationCode(21.0, false, 'CZ'));
    }

    public function testDomesticReducedRate(): void
    {
        // Tuzemská snížená sazba (12/15 %) → '2'.
        self::assertSame('2', InvoiceRepository::defaultSaleClassificationCode(12.0, false, 'CZ'));
    }

    public function testDomesticZeroRate(): void
    {
        // Tuzemsko, nulová/osvobozená sazba → '3'.
        self::assertSame('3', InvoiceRepository::defaultSaleClassificationCode(0.0, false, 'CZ'));
    }

    public function testReverseChargeToEuZeroRateGoesToEuServices(): void
    {
        // Reverse charge DO ZAHRANIČÍ (EU), nulová sazba → '22' (poskytnutí služeb do EU).
        self::assertSame('22', InvoiceRepository::defaultSaleClassificationCode(0.0, true, 'DE'));
        self::assertSame('22', InvoiceRepository::defaultSaleClassificationCode(0.0, true, 'SK'));
    }

    public function testReverseChargeOutsideEuZeroRateGoesToExport(): void
    {
        // Reverse charge / dodání MIMO EU, nulová sazba → '26' (vývoz).
        self::assertSame('26', InvoiceRepository::defaultSaleClassificationCode(0.0, true, 'US'));
        self::assertSame('26', InvoiceRepository::defaultSaleClassificationCode(0.0, false, 'CH'));
    }

    public function testDomesticReverseChargeZeroRateStaysDomestic(): void
    {
        // Reverse charge V ČR (tuzemský přenos, §92a) — rate 0, klient CZ → tuzemský
        // kód '3' (není to zahraniční plnění). Reverse charge sám o sobě kód nemění,
        // rozhoduje země + sazba.
        self::assertSame('3', InvoiceRepository::defaultSaleClassificationCode(0.0, true, 'CZ'));
    }

    public function testForeignCustomerWithCzechRateUsesDomesticBucket(): void
    {
        // B2C cizinec s českou DPH sazbou (ne nulová) → klasifikuje se jako tuzemská sazba.
        self::assertSame('1', InvoiceRepository::defaultSaleClassificationCode(21.0, false, 'DE'));
    }

    public function testNullCountryDefaultsToDomestic(): void
    {
        self::assertSame('1', InvoiceRepository::defaultSaleClassificationCode(21.0, false, null));
    }
}
