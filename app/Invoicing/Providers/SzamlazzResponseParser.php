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
        if ($invoiceNumber !== null && preg_match('/\A[A-Za-z0-9._\/-]{1,128}\z/D', $invoiceNumber) !== 1) {
            $invoiceNumber = null;
        }
        if ($errorCode !== null && preg_match('/\A[A-Za-z0-9_-]{1,32}\z/D', $errorCode) !== 1) {
            $errorCode = 'provider_reported_error';
        }

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
                    $successNode = $xpath->query('//*[local-name()="sikeres"]')->item(0);
                    if ($successNode && strtolower(trim($successNode->textContent)) === 'false') {
                        $errorCode ??= 'provider_reported_failure';
                    }
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }

        if ($invoiceNumber === null && preg_match('/\Axmlagentresponse=DONE;([A-Za-z0-9._\/-]{1,128})\s*\z/D', trim($body), $matches) === 1) {
            $invoiceNumber = $matches[1];
        }
        if ($invoiceNumber !== null && preg_match('/\A[A-Za-z0-9._\/-]{1,128}\z/D', $invoiceNumber) !== 1) {
            $invoiceNumber = null;
        }
        if ($errorCode !== null && preg_match('/\A[A-Za-z0-9_-]{1,32}\z/D', $errorCode) !== 1) {
            $errorCode = 'provider_reported_error';
        }
        $hasProviderError = $errorCode !== null || str_starts_with(trim($body), '[ERR]');
        if ($hasProviderError && $errorCode === null) {
            $errorCode = 'provider_reported_error';
        }

        return [
            // A provider number is persisted only after the submission workflow
            // has correlated it with this application's issuance attempt.
            'result' => $hasProviderError ? 'response_contains_error' : ($invoiceNumber !== null ? 'response_contains_invoice_number' : 'ambiguous_response'),
            'provider_invoice_id' => $invoiceNumber,
            'error_code' => $errorCode,
            'net_amount' => isset($normalized['szlahu_nettovegosszeg']) ? $normalized['szlahu_nettovegosszeg'] : null,
            'gross_amount' => isset($normalized['szlahu_bruttovegosszeg']) ? $normalized['szlahu_bruttovegosszeg'] : null,
        ];
    }
}
