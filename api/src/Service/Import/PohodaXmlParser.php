<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * Parser Pohoda XML data package — extrahuje faktury do normalizovaného array.
 *
 * Vrací {supplier_ic, invoices[]} — supplier IČO je z dataPack@ico, faktury z dataPackItem.
 *
 * Mapuje zpět to, co PohodaXmlExporter zapisuje. Robustní vůči chybějícím elementům.
 *
 * Output shape per invoice:
 *   [
 *     'invoice_type'   => 'invoice'|'proforma'|'credit_note',
 *     'varsymbol'      => string,
 *     'issue_date'     => 'Y-m-d',
 *     'tax_date'       => 'Y-m-d'|null,
 *     'due_date'       => 'Y-m-d',
 *     'currency'       => 'CZK'|'EUR'|...,
 *     'exchange_rate'  => float|null,
 *     'reverse_charge' => bool,
 *     'note_above'     => string|null,
 *     'project_number' => string|null,   // z inv:numberOrder
 *     'client'         => [company_name, ic, dic, street, city, zip, country_iso2, email, phone],
 *     'items'          => [[description, quantity, unit, unit_price_without_vat, vat_rate], ...],
 *   ]
 */
final class PohodaXmlParser
{
    private const NS_DAT = 'http://www.stormware.cz/schema/version_2/data.xsd';
    private const NS_INV = 'http://www.stormware.cz/schema/version_2/invoice.xsd';
    private const NS_TYP = 'http://www.stormware.cz/schema/version_2/type.xsd';

    /**
     * @return array{supplier_ic:?string, invoices:list<array<string,mixed>>}
     */
    public function parse(string $xml): array
    {
        // XXE / billion-laughs hardening — viz IsdocParser.
        if (preg_match('/<!DOCTYPE/i', $xml)) {
            throw new \RuntimeException('Pohoda XML obsahuje DOCTYPE, což není povoleno.');
        }

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded || $dom->documentElement === null) {
            throw new \RuntimeException('Nelze parsovat Pohoda XML.');
        }

        $root = $dom->documentElement;
        if ($root->localName !== 'dataPack') {
            throw new \RuntimeException('Není Pohoda XML — root není dataPack.');
        }

        $supplierIc = $root->getAttribute('ico') ?: null;

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('dat', self::NS_DAT);
        $xpath->registerNamespace('inv', self::NS_INV);
        $xpath->registerNamespace('typ', self::NS_TYP);

        $invoices = [];
        /** @var \DOMElement $invEl */
        foreach ($xpath->query('//inv:invoice') ?: [] as $invEl) {
            try {
                $invoices[] = $this->parseInvoice($invEl, $xpath);
            } catch (\Throwable $e) {
                // Skip individual broken invoices — vyšší vrstva to dostane jako null v listu, řeší až InvoiceImportService.
                $invoices[] = ['__error' => $e->getMessage()];
            }
        }

        return ['supplier_ic' => $supplierIc, 'invoices' => $invoices];
    }

    /**
     * @return array<string,mixed>
     */
    private function parseInvoice(\DOMElement $invEl, \DOMXPath $xpath): array
    {
        $hdr = $xpath->query('inv:invoiceHeader', $invEl)->item(0);
        if (!$hdr instanceof \DOMElement) {
            throw new \RuntimeException('Chybí invoiceHeader.');
        }

        $typeRaw = $this->text($xpath, 'inv:invoiceType', $hdr);
        $invoiceType = match ($typeRaw) {
            'issuedAdvanceInvoice' => 'proforma',
            'issuedCreditNotice'   => 'credit_note',
            default                => 'invoice',
        };

        $varsymbol = $this->text($xpath, 'inv:symVar', $hdr)
            ?: $this->text($xpath, 'inv:number/typ:numberRequested', $hdr);
        if ($varsymbol === '') {
            throw new \RuntimeException('Chybí varsymbol (symVar / number).');
        }

        $issueDate = $this->text($xpath, 'inv:date', $hdr);
        $taxDate   = $this->text($xpath, 'inv:dateTax', $hdr) ?: null;
        $dueDate   = $this->text($xpath, 'inv:dateDue', $hdr) ?: $issueDate;

        // Reverse charge (přenesená daň. povinnost) = Pohoda <inv:classificationVAT> kód PDP
        // (Pohoda: PN*, náš export: PNAR). <inv:isExecuted> je posting příznak („zlikvidováno"),
        // NE reverse charge (issue #41). textContent zahrne i vnořené <typ:ids>.
        $vatClass = strtoupper(trim($this->text($xpath, 'inv:classificationVAT', $hdr)));
        $reverseCharge = str_starts_with($vatClass, 'PN');
        $noteAbove = $this->text($xpath, 'inv:text', $hdr) ?: null;
        // Pohoda může mít inv:numberOrder (číslo objednávky odběratele) nebo inv:contract/typ:ids
        $projectNumber = $this->text($xpath, 'inv:numberOrder', $hdr) ?: null;

        // Klient: inv:partnerIdentity/typ:address
        $addressNode = $xpath->query('inv:partnerIdentity/typ:address', $hdr)->item(0);
        $client = $addressNode instanceof \DOMElement ? $this->parseAddress($xpath, $addressNode) : [];

        // Currency — z první foreignCurrency v summary (pokud existuje), jinak CZK
        $currency = 'CZK';
        $rate = null;
        $foreignCur = $xpath->query('inv:invoiceSummary/inv:foreignCurrency/typ:currency/typ:ids', $invEl)->item(0);
        if ($foreignCur instanceof \DOMElement) {
            $currency = strtoupper(trim($foreignCur->textContent));
            $rateEl = $xpath->query('inv:invoiceSummary/inv:foreignCurrency/typ:rate', $invEl)->item(0);
            if ($rateEl instanceof \DOMElement) {
                $rate = (float) $rateEl->textContent;
            }
        }

        // Items
        $items = [];
        foreach ($xpath->query('inv:invoiceDetail/inv:invoiceItem', $invEl) ?: [] as $itemEl) {
            if (!$itemEl instanceof \DOMElement) continue;
            $items[] = $this->parseItem($xpath, $itemEl, $currency !== 'CZK');
        }

        return [
            'invoice_type'   => $invoiceType,
            'varsymbol'      => $varsymbol,
            'issue_date'     => $issueDate,
            'tax_date'       => $taxDate,
            'due_date'       => $dueDate,
            'currency'       => $currency,
            'exchange_rate'  => $rate,
            'reverse_charge' => $reverseCharge,
            'note_above'     => $noteAbove,
            'project_number' => $projectNumber,
            'client'         => $client,
            'items'          => $items,
            // Rekapitulace DPH po sazbách z <invoiceSummary> — pro seed override.
            'vat_recap'      => $this->parseSummaryRecap($xpath, $invEl, $currency),
        ];
    }

    /**
     * Rekapitulace DPH po sazbách z `<invoiceSummary>/<homeCurrency|foreignCurrency>`.
     *
     * Pohoda summary nese základ + DPH per sazbová „přihrádka" (high/low/3). Mapování
     * na procenta drží stejné jako {@see parseItem()} (high=21, low=12, low2/3=10).
     * U cizoměnové faktury čte `foreignCurrency` (částky v měně faktury). Vrací
     * rateKey (`number_format(rate,2,'.','')`) => kladné `{base, vat}`.
     *
     * @return array<string,array{base:float,vat:float}>
     */
    private function parseSummaryRecap(\DOMXPath $xpath, \DOMElement $invEl, string $currency): array
    {
        $block = $currency !== 'CZK' ? 'inv:foreignCurrency' : 'inv:homeCurrency';
        $sum = $xpath->query("inv:invoiceSummary/$block", $invEl)->item(0);
        if (!$sum instanceof \DOMElement) {
            return [];
        }
        $buckets = ['High' => 21.0, 'Low' => 12.0, '3' => 10.0];
        $out = [];
        foreach ($buckets as $suffix => $rate) {
            $base = $this->text($xpath, "typ:price{$suffix}", $sum);
            $vat  = $this->text($xpath, "typ:price{$suffix}VAT", $sum);
            if ($base === '' && $vat === '') {
                continue;
            }
            $baseF = abs((float) ($base !== '' ? $base : '0'));
            $vatF  = abs((float) ($vat !== '' ? $vat : '0'));
            if ($baseF <= 0.0 && $vatF <= 0.0) {
                continue;
            }
            $out[number_format($rate, 2, '.', '')] = ['base' => $baseF, 'vat' => $vatF];
        }
        return $out;
    }

    /**
     * @return array<string,?string>
     */
    private function parseAddress(\DOMXPath $xpath, \DOMElement $addr): array
    {
        return [
            'company_name' => $this->text($xpath, 'typ:company', $addr) ?: null,
            'ic'           => $this->text($xpath, 'typ:ico',     $addr) ?: null,
            'dic'          => $this->text($xpath, 'typ:dic',     $addr) ?: null,
            'street'       => $this->text($xpath, 'typ:street',  $addr) ?: null,
            'city'         => $this->text($xpath, 'typ:city',    $addr) ?: null,
            'zip'          => $this->text($xpath, 'typ:zip',     $addr) ?: null,
            'country_iso2' => strtoupper($this->text($xpath, 'typ:country/typ:ids', $addr)) ?: null,
            'email'        => $this->text($xpath, 'typ:email',   $addr) ?: null,
            'phone'        => $this->text($xpath, 'typ:phone',   $addr) ?: null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function parseItem(\DOMXPath $xpath, \DOMElement $itemEl, bool $foreign): array
    {
        $blockName = $foreign ? 'inv:foreignCurrency' : 'inv:homeCurrency';
        $rateCode = $this->text($xpath, 'inv:rateVAT', $itemEl);
        $vatRate = match ($rateCode) {
            'high' => 21.0,
            'low'  => 12.0,
            'low2' => 10.0,
            default => 0.0,
        };

        $unitPrice = (float) ($this->text($xpath, "$blockName/typ:unitPrice", $itemEl) ?: '0');

        return [
            'description'            => $this->text($xpath, 'inv:text', $itemEl),
            'quantity'               => (float) ($this->text($xpath, 'inv:quantity', $itemEl) ?: '1'),
            'unit'                   => $this->text($xpath, 'inv:unit', $itemEl) ?: 'ks',
            'unit_price_without_vat' => $unitPrice,
            'vat_rate'               => $vatRate,
        ];
    }

    private function text(\DOMXPath $xpath, string $expr, \DOMNode $context): string
    {
        $node = $xpath->query($expr, $context)->item(0);
        return $node ? trim($node->textContent) : '';
    }
}
