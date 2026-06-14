<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Export;

use MyInvoice\Service\Export\PurchaseInvoiceExportService;
use PHPUnit\Framework\TestCase;

/**
 * Guard proti regresi „nulový summary + UNX klasifikace" u exportu přijatých faktur.
 *
 * PurchaseInvoiceRepository::buildVatBreakdown vrací klíče `vat_rate`/`without_vat`/
 * `with_vat`, ALE exportéry (PohodaXmlExporter::bucketsFromBreakdown + classifyVat,
 * IsdocExporter::TaxTotal) čtou kanonické `rate`/`base`/`vat`. Bez přemapování
 * vycházely buckety i maxRate jako 0 → prázdný souhrn a chybná klasifikace DPH.
 */
final class PurchaseInvoiceVatBreakdownTest extends TestCase
{
    public function testRepositoryKeysAreMappedToCanonicalShape(): void
    {
        // Přesný tvar z PurchaseInvoiceRepository::buildVatBreakdown.
        $repoShape = [
            ['vat_rate' => 21.0, 'without_vat' => 2520.0, 'vat' => 529.2, 'with_vat' => 3049.2],
            ['vat_rate' => 12.0, 'without_vat' => 1000.0, 'vat' => 120.0, 'with_vat' => 1120.0],
        ];

        self::assertSame([
            ['rate' => 21.0, 'base' => 2520.0, 'vat' => 529.2],
            ['rate' => 12.0, 'base' => 1000.0, 'vat' => 120.0],
        ], PurchaseInvoiceExportService::normalizeVatBreakdown($repoShape));
    }

    public function testAlreadyCanonicalShapeIsPreserved(): void
    {
        // Tvar z InvoiceRepository (vydané) projde beze změny.
        $canonical = [['rate' => 21.0, 'base' => 100.0, 'vat' => 21.0]];
        self::assertSame($canonical, PurchaseInvoiceExportService::normalizeVatBreakdown($canonical));
    }

    public function testEmptyBreakdownStaysEmpty(): void
    {
        self::assertSame([], PurchaseInvoiceExportService::normalizeVatBreakdown([]));
    }
}
