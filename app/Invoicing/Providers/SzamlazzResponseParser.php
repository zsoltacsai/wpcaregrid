<?php

namespace App\Invoicing\Providers;

use DOMDocument;
use DOMXPath;

/** Safe allow-listed parser for stored/documented fixtures; never retains provider bodies. */
final class SzamlazzResponseParser
{
    public function parse(array $headers, string $body): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = is_array($value) ? (string) end($value) : (string) $value;
        }

        $errorCode = $normalized['szlahu_error_code'] ?? null;
        $invoiceNumber = $normalized['szlahu_szamlaszam'] ?? null;
        $invoiceNumber = $invoiceNumber !== null ? rawurldecode($invoiceNumber) : null;

        // Version 2 XML responses may carry document metadata; parse only named fields.
        if ($body !== '' && str_contains($body, '<')) {
            $document = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            try {
                if ($document->loadXML($body, LIBXML_NONET | LIBXML_NOBLANKS)) {
                    $xpath = new DOMXPath($document);
                    $numberNode = $xpath->query('//*[local-name()="szamlaszam"]')->item(0);
                    if ($numberNode && $numberNode->textContent !== '') {
                        $invoiceNumber ??= $numberNode->textContent;
                    }
                    $errorNode = $xpath->query('//*[local-name()="hibakod"]')->item(0);
                    $errorCode ??= $errorNode?->textContent ?: null;
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }

        return [
            // Phase 1 parses captured fixtures only; a parsed invoice number is
            // not evidence that this application issued a live invoice.
            'result' => $errorCode !== null ? 'response_contains_error' : ($invoiceNumber !== null ? 'response_contains_invoice_number' : 'ambiguous_response'),
            'provider_invoice_id' => $invoiceNumber,
            'error_code' => $errorCode,
            'net_amount' => isset($normalized['szlahu_nettovegosszeg']) ? $normalized['szlahu_nettovegosszeg'] : null,
            'gross_amount' => isset($normalized['szlahu_bruttovegosszeg']) ? $normalized['szlahu_bruttovegosszeg'] : null,
        ];
    }
}
