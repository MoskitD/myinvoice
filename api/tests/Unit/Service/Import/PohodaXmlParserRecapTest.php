<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\PohodaXmlParser;
use PHPUnit\Framework\TestCase;

/**
 * Čtení rekapitulace DPH po sazbách z Pohoda `<invoiceSummary>`.
 */
final class PohodaXmlParserRecapTest extends TestCase
{
    private const DAT = 'http://www.stormware.cz/schema/version_2/data.xsd';
    private const INV = 'http://www.stormware.cz/schema/version_2/invoice.xsd';
    private const TYP = 'http://www.stormware.cz/schema/version_2/type.xsd';

    private function parseFirst(string $xml): array
    {
        $res = (new PohodaXmlParser())->parse($xml);
        self::assertNotEmpty($res['invoices']);
        return $res['invoices'][0];
    }

    public function testParsesHomeCurrencyRecap(): void
    {
        $dat = self::DAT;
        $inv = self::INV;
        $typ = self::TYP;
        $xml = <<<XML
        <dat:dataPack xmlns:dat="$dat" xmlns:inv="$inv" xmlns:typ="$typ" ico="12345678">
          <dat:dataPackItem>
            <inv:invoice version="2.0">
              <inv:invoiceHeader>
                <inv:invoiceType>issuedInvoice</inv:invoiceType>
                <inv:symVar>2024001</inv:symVar>
                <inv:date>2024-01-15</inv:date>
              </inv:invoiceHeader>
              <inv:invoiceSummary>
                <inv:homeCurrency>
                  <typ:priceHigh>1000.00</typ:priceHigh>
                  <typ:priceHighVAT>210.00</typ:priceHighVAT>
                  <typ:priceLow>500.00</typ:priceLow>
                  <typ:priceLowVAT>60.00</typ:priceLowVAT>
                </inv:homeCurrency>
              </inv:invoiceSummary>
            </inv:invoice>
          </dat:dataPackItem>
        </dat:dataPack>
        XML;

        $recap = $this->parseFirst($xml)['vat_recap'];

        self::assertSame(['base' => 1000.00, 'vat' => 210.00], $recap['21.00']);
        self::assertSame(['base' => 500.00, 'vat' => 60.00], $recap['12.00']);
    }

    public function testForeignCurrencyRecapFromForeignBlock(): void
    {
        $dat = self::DAT;
        $inv = self::INV;
        $typ = self::TYP;
        $xml = <<<XML
        <dat:dataPack xmlns:dat="$dat" xmlns:inv="$inv" xmlns:typ="$typ" ico="12345678">
          <dat:dataPackItem>
            <inv:invoice version="2.0">
              <inv:invoiceHeader>
                <inv:invoiceType>issuedInvoice</inv:invoiceType>
                <inv:symVar>2024002</inv:symVar>
                <inv:date>2024-01-15</inv:date>
              </inv:invoiceHeader>
              <inv:invoiceSummary>
                <inv:foreignCurrency>
                  <typ:currency><typ:ids>EUR</typ:ids></typ:currency>
                  <typ:rate>25.00</typ:rate>
                  <typ:priceHigh>40.00</typ:priceHigh>
                  <typ:priceHighVAT>8.40</typ:priceHighVAT>
                </inv:foreignCurrency>
              </inv:invoiceSummary>
            </inv:invoice>
          </dat:dataPackItem>
        </dat:dataPack>
        XML;

        $invoice = $this->parseFirst($xml);

        self::assertSame('EUR', $invoice['currency']);
        self::assertSame(['base' => 40.00, 'vat' => 8.40], $invoice['vat_recap']['21.00']);
    }

    public function testMissingSummaryYieldsEmptyRecap(): void
    {
        $dat = self::DAT;
        $inv = self::INV;
        $typ = self::TYP;
        $xml = <<<XML
        <dat:dataPack xmlns:dat="$dat" xmlns:inv="$inv" xmlns:typ="$typ" ico="12345678">
          <dat:dataPackItem>
            <inv:invoice version="2.0">
              <inv:invoiceHeader>
                <inv:invoiceType>issuedInvoice</inv:invoiceType>
                <inv:symVar>2024003</inv:symVar>
                <inv:date>2024-01-15</inv:date>
              </inv:invoiceHeader>
            </inv:invoice>
          </dat:dataPackItem>
        </dat:dataPack>
        XML;

        self::assertSame([], $this->parseFirst($xml)['vat_recap']);
    }
}
