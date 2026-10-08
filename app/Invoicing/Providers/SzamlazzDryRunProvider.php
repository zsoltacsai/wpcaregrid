<?php

namespace App\Invoicing\Providers;

use App\Invoicing\ApprovedTaxDecision;
use App\Invoicing\InvoiceValidationResult;
use App\Invoicing\NormalizedInvoiceRequest;
use DOMDocument;

final class SzamlazzDryRunProvider implements InvoiceProvider
{
    public function __construct(private readonly SzamlazzResponseParser $parser) {}

    public function identifier(): string
    {
        return 'szamlazz_hu';
    }

    public function validate(NormalizedInvoiceRequest $request): InvoiceValidationResult
    {
        $errors = [];
        $currency = strtoupper($request->currency);
        $supported = ['HUF', 'EUR', 'USD', 'GBP'];

        if (! in_array($currency, $supported, true)) {
            $errors[] = 'unsupported_currency';
        }
        $line = $request->taxDecision->line;
        $lineShapeIsValid = isset($line['net'], $line['tax'], $line['gross'], $line['tax_code'])
            && is_int($line['net']) && is_int($line['tax']) && is_int($line['gross'])
            && is_string($line['tax_code']) && trim($line['tax_code']) !== '';
        if (! $lineShapeIsValid) {
            $errors[] = 'line_amounts_inconsistent';
        } elseif ($line['net'] < 0 || $line['tax'] < 0 || $line['gross'] < 0
            || $line['net'] > PHP_INT_MAX - $line['tax']
            || $line['gross'] !== $line['net'] + $line['tax']) {
            $errors[] = 'line_amounts_inconsistent';
        }
        if ($request->amountMinor < 0 || ! $lineShapeIsValid || $request->amountMinor !== $line['gross']) {
            $errors[] = 'invoice_total_mismatch';
        }
        if (! preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $request->externalReference)) {
            $errors[] = 'invalid_external_reference';
        }
        if (! in_array($request->taxDecision->language, ['hu', 'en', 'de', 'it', 'fr', 'ro', 'sk', 'hr', 'es', 'cz', 'pl', 'bg', 'nl', 'ru', 'si'], true)) {
            $errors[] = 'unsupported_language';
        }
        foreach (['issueDate' => 'issue_date', 'performanceDate' => 'performance_date', 'paymentDueDate' => 'payment_due_date'] as $field => $label) {
            if (! $this->validDate($request->{$field})) {
                $errors[] = 'invalid_'.$label;
            }
        }
        if ($request->paymentDate !== null && ! $this->validDate($request->paymentDate)) {
            $errors[] = 'invalid_payment_date';
        }
        foreach (['seller' => $request->seller, 'buyer' => $request->buyer] as $party => $fields) {
            foreach (['name', 'country', 'postal_code', 'city', 'address'] as $field) {
                if (! isset($fields[$field]) || trim($fields[$field]) === '') {
                    $errors[] = $party.'_missing_'.$field;
                }
            }
            if (isset($fields['country']) && preg_match('/^[A-Za-z]{2}$/', $fields['country']) !== 1) {
                $errors[] = $party.'_invalid_country';
            }
        }
        if (trim($request->paymentMethod) === '') {
            $errors[] = 'payment_method_missing';
        }
        if (trim($request->description) === '' || mb_strlen($request->description) > 200) {
            $errors[] = 'invalid_description';
        }
        if ($currency !== 'HUF') {
            if (! $request->taxDecision->exchangeRate || ! $request->taxDecision->exchangeRateBank) {
                $errors[] = 'foreign_currency_exchange_reference_missing';
            } elseif (preg_match('/^\d{1,12}(?:[.,]\d{1,8})?$/', $request->taxDecision->exchangeRate) !== 1
                || mb_strlen($request->taxDecision->exchangeRateBank) > 100) {
                $errors[] = 'invalid_foreign_currency_exchange_reference';
            }
        }

        if ($errors !== []) {
            return new InvoiceValidationResult(false, array_values(array_unique($errors)));
        }

        $xml = $this->xml($request);
        $xsd = config('szamlazz.xsd_path');
        if (! is_string($xsd) || ! is_file($xsd)) {
            return new InvoiceValidationResult(false, ['local_schema_unavailable']);
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->loadXML($xml, LIBXML_NONET) || ! $document->schemaValidate($xsd)) {
                return new InvoiceValidationResult(false, ['local_schema_validation_failed']);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new InvoiceValidationResult(true, [], $xml);
    }

    public function parseResponse(array $headers, string $body): array
    {
        return $this->parser->parse($headers, $body);
    }

    private function xml(NormalizedInvoiceRequest $request): string
    {
        $currency = strtoupper($request->currency);
        $minorUnits = (int) config("pricing.currencies.{$currency}.minor_units", 2);
        $amount = fn (int $minor): string => $this->decimal($minor, $minorUnits);
        $issueDate = (new \DateTimeImmutable($request->issueDate))->format('Y-m-d');
        $performanceDate = (new \DateTimeImmutable($request->performanceDate))->format('Y-m-d');
        $paymentDueDate = (new \DateTimeImmutable($request->paymentDueDate))->format('Y-m-d');
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $root = $xml->createElementNS('http://www.szamlazz.hu/xmlszamla', 'xmlszamla');
        $root->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:schemaLocation', 'http://www.szamlazz.hu/xmlszamla https://www.szamlazz.hu/szamla/docs/xsds/agent/xmlszamla.xsd');
        $xml->appendChild($root);

        $settings = $this->node($xml, $root, 'beallitasok');
        $this->node($xml, $settings, 'szamlaagentkulcs', '');
        $this->node($xml, $settings, 'eszamla', 'false');
        $this->node($xml, $settings, 'szamlaLetoltes', 'false');
        $this->node($xml, $settings, 'szamlaKulsoAzon', $request->externalReference);

        $header = $this->node($xml, $root, 'fejlec');
        $this->node($xml, $header, 'keltDatum', $issueDate);
        $this->node($xml, $header, 'teljesitesDatum', $performanceDate);
        $this->node($xml, $header, 'fizetesiHataridoDatum', $paymentDueDate);
        $this->node($xml, $header, 'fizmod', $request->paymentMethod);
        $this->node($xml, $header, 'penznem', $currency);
        $this->node($xml, $header, 'szamlaNyelve', $request->taxDecision->language);
        $this->node($xml, $header, 'megjegyzes', '');
        $exchangeRateBank = $request->taxDecision instanceof ApprovedTaxDecision ? $request->taxDecision->exchangeRateBank : null;
        $exchangeRate = $request->taxDecision instanceof ApprovedTaxDecision ? $request->taxDecision->exchangeRate : null;
        if ($exchangeRateBank !== null && $exchangeRateBank !== '') {
            $this->node($xml, $header, 'arfolyamBank', $exchangeRateBank);
        }
        if ($exchangeRate !== null && $exchangeRate !== '') {
            $this->node($xml, $header, 'arfolyam', $exchangeRate);
        }
        $this->node($xml, $header, 'rendelesSzam', $request->externalReference);
        $this->node($xml, $header, 'dijbekeroSzamlaszam', '');
        $this->node($xml, $header, 'elolegszamla', 'false');
        $this->node($xml, $header, 'vegszamla', 'false');
        $this->node($xml, $header, 'helyesbitoszamla', 'false');
        $this->node($xml, $header, 'helyesbitettSzamlaszam', '');
        $this->node($xml, $header, 'dijbekero', 'false');
        $this->node($xml, $header, 'szamlaszamElotag', '');
        $this->node($xml, $header, 'simpleItems', 'false');

        $seller = $this->node($xml, $root, 'elado');
        foreach (['bank', 'bankszamlaszam', 'emailReplyto', 'emailTargy', 'emailSzoveg'] as $field) {
            $this->node($xml, $seller, $field, $request->seller[$field] ?? '');
        }
        $buyer = $this->node($xml, $root, 'vevo');
        $this->node($xml, $buyer, 'nev', $request->buyer['name']);
        $this->node($xml, $buyer, 'orszag', $request->buyer['country']);
        foreach (['postal_code' => 'irsz', 'city' => 'telepules', 'address' => 'cim', 'email' => 'email'] as $field => $tag) {
            if (isset($request->buyer[$field]) && $request->buyer[$field] !== '') {
                $this->node($xml, $buyer, $tag, $request->buyer[$field]);
            }
        }
        $this->node($xml, $buyer, 'sendEmail', 'false');
        $this->node($xml, $buyer, 'adoszam', '');
        $this->node($xml, $buyer, 'adoszamEU', '');

        $items = $this->node($xml, $root, 'tetelek');
        $item = $this->node($xml, $items, 'tetel');
        $this->node($xml, $item, 'megnevezes', $request->description);
        $this->node($xml, $item, 'mennyiseg', '1');
        $this->node($xml, $item, 'mennyisegiEgyseg', 'db');
        $this->node($xml, $item, 'nettoEgysegar', $amount($request->taxDecision->line['net']));
        $this->node($xml, $item, 'afakulcs', $request->taxDecision->line['tax_code']);
        $this->node($xml, $item, 'nettoErtek', $amount($request->taxDecision->line['net']));
        $this->node($xml, $item, 'afaErtek', $amount($request->taxDecision->line['tax']));
        $this->node($xml, $item, 'bruttoErtek', $amount($request->taxDecision->line['gross']));

        return $xml->saveXML() ?: '';
    }

    private function decimal(int $minor, int $scale): string
    {
        $factor = 10 ** $scale;
        $whole = intdiv($minor, $factor);
        if ($scale === 0) {
            return (string) $whole;
        }

        return $whole.'.'.str_pad((string) ($minor % $factor), $scale, '0', STR_PAD_LEFT);
    }

    private function node(DOMDocument $document, \DOMNode $parent, string $name, string $value = ''): \DOMElement
    {
        $element = $document->createElement($name);
        $element->appendChild($document->createTextNode($value));
        $parent->appendChild($element);

        return $element;
    }

    private function validDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }
}
