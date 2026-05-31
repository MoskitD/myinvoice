<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\IsdocParser;
use PHPUnit\Framework\TestCase;

/**
 * Čtení rekapitulace DPH po sazbách z ISDOC `<TaxTotal>/<TaxSubTotal>`.
 */
final class IsdocParserTaxRecapTest extends TestCase
{
    private function parseFirst(string $xml): array
    {
        $res = (new IsdocParser())->parse($xml);
        self::assertNotEmpty($res['invoices']);
        return $res['invoices'][0];
    }

    public function testParsesMultiRateTaxRecap(): void
    {
        $xml = <<<XML
        <Invoice xmlns="http://isdoc.cz/namespace/2013" version="6.0.2">
          <DocumentType>1</DocumentType>
          <ID>2024001</ID>
          <IssueDate>2024-01-15</IssueDate>
          <LocalCurrencyCode>CZK</LocalCurrencyCode>
          <InvoiceLines/>
          <TaxTotal>
            <TaxSubTotal>
              <TaxableAmount>1000.00</TaxableAmount>
              <TaxAmount>210.00</TaxAmount>
              <TaxCategory><Percent>21</Percent></TaxCategory>
            </TaxSubTotal>
            <TaxSubTotal>
              <TaxableAmount>500.00</TaxableAmount>
              <TaxAmount>60.00</TaxAmount>
              <TaxCategory><Percent>12</Percent></TaxCategory>
            </TaxSubTotal>
            <TaxAmount>270.00</TaxAmount>
          </TaxTotal>
        </Invoice>
        XML;

        $recap = $this->parseFirst($xml)['vat_recap'];

        self::assertSame(['base' => 1000.00, 'vat' => 210.00], $recap['21.00']);
        self::assertSame(['base' => 500.00, 'vat' => 60.00], $recap['12.00']);
    }

    public function testZeroRateIsSkipped(): void
    {
        $xml = <<<XML
        <Invoice xmlns="http://isdoc.cz/namespace/2013" version="6.0.2">
          <ID>2024002</ID>
          <IssueDate>2024-01-15</IssueDate>
          <LocalCurrencyCode>CZK</LocalCurrencyCode>
          <InvoiceLines/>
          <TaxTotal>
            <TaxSubTotal>
              <TaxableAmount>800.00</TaxableAmount>
              <TaxAmount>0.00</TaxAmount>
              <TaxCategory><Percent>0</Percent></TaxCategory>
            </TaxSubTotal>
            <TaxAmount>0.00</TaxAmount>
          </TaxTotal>
        </Invoice>
        XML;

        self::assertSame([], $this->parseFirst($xml)['vat_recap']);
    }

    public function testForeignCurrencyPrefersCurrAmounts(): void
    {
        // *Curr je v měně faktury (EUR) — recap musí použít je, ne lokální CZK hodnoty.
        $xml = <<<XML
        <Invoice xmlns="http://isdoc.cz/namespace/2013" version="6.0.2">
          <ID>2024003</ID>
          <IssueDate>2024-01-15</IssueDate>
          <LocalCurrencyCode>CZK</LocalCurrencyCode>
          <ForeignCurrencyCode>EUR</ForeignCurrencyCode>
          <CurrRate>25.00</CurrRate>
          <InvoiceLines/>
          <TaxTotal>
            <TaxSubTotal>
              <TaxableAmountCurr>40.00</TaxableAmountCurr>
              <TaxableAmount>1000.00</TaxableAmount>
              <TaxAmountCurr>8.40</TaxAmountCurr>
              <TaxAmount>210.00</TaxAmount>
              <TaxCategory><Percent>21</Percent></TaxCategory>
            </TaxSubTotal>
            <TaxAmount>210.00</TaxAmount>
          </TaxTotal>
        </Invoice>
        XML;

        $recap = $this->parseFirst($xml)['vat_recap'];

        self::assertSame(['base' => 40.00, 'vat' => 8.40], $recap['21.00']);
    }

    public function testMissingTaxTotalYieldsEmptyRecap(): void
    {
        $xml = <<<XML
        <Invoice xmlns="http://isdoc.cz/namespace/2013" version="6.0.2">
          <ID>2024004</ID>
          <IssueDate>2024-01-15</IssueDate>
          <LocalCurrencyCode>CZK</LocalCurrencyCode>
          <InvoiceLines/>
        </Invoice>
        XML;

        self::assertSame([], $this->parseFirst($xml)['vat_recap']);
    }
}
