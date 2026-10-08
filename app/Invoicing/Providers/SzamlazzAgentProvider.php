<?php

namespace App\Invoicing\Providers;

use App\Invoicing\ApprovedTaxDecision;
use App\Invoicing\NormalizedInvoiceRequest;
use App\Invoicing\SyntheticInvoiceSemanticValidator;
use App\Invoicing\SyntheticSzamlazzTestContract;
use App\Invoicing\SyntheticSzamlazzTestGuard;
use App\Invoicing\SyntheticTestTaxSelection;
use DOMDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Mutating invoice submission adapter. It is never bound to the Phase 1 dry-run interface. */
final class SzamlazzAgentProvider implements InvoiceSubmissionProvider, SyntheticInvoiceTestProvider
{
    public function __construct(
        private readonly SzamlazzDryRunProvider $validator,
        private readonly SzamlazzResponseParser $parser,
        private readonly SyntheticSzamlazzTestGuard $syntheticTestGuard,
        private readonly SyntheticInvoiceSemanticValidator $syntheticSemanticValidator,
    ) {}

    public function submit(NormalizedInvoiceRequest $request): ProviderSubmissionResult
    {
        if (! $this->mutationsPermitted()) {
            return new ProviderSubmissionResult('blocked', errorCategory: 'provider_gates_closed');
        }

        if (! $request->taxDecision instanceof ApprovedTaxDecision) {
            return new ProviderSubmissionResult('blocked', errorCategory: 'production_tax_decision_required');
        }
        if (! $this->validInvoicePrefix($request->billingSettings['invoice_prefix'] ?? null)) {
            return new ProviderSubmissionResult('blocked', errorCategory: 'invoice_prefix_unconfigured');
        }

        return $this->sendInvoice($request, syntheticTest: false);
    }

    public function submitSyntheticTest(NormalizedInvoiceRequest $request): ProviderSubmissionResult
    {
        $xml = $this->syntheticXml($request);
        if ($xml === null) {
            return new ProviderSubmissionResult('blocked', errorCategory: 'synthetic_test_guard_failed');
        }

        return $this->sendInvoice($request, syntheticTest: true, preparedXml: $xml);
    }

    public function validateSyntheticTest(NormalizedInvoiceRequest $request): bool
    {
        return $this->syntheticXml($request) !== null;
    }

    private function syntheticXml(NormalizedInvoiceRequest $request): ?string
    {
        if (! $this->syntheticTestGuard->permits()
            || ! $request->taxDecision instanceof SyntheticTestTaxSelection
            || $request->currency !== SyntheticSzamlazzTestContract::CURRENCY
            || $request->amountMinor !== SyntheticSzamlazzTestContract::grossMinorUnits()
            || ! $this->validInvoicePrefix($request->billingSettings['invoice_prefix'] ?? null)
            || ($request->billingSettings['invoice_prefix'] ?? null) !== SyntheticSzamlazzTestContract::INVOICE_PREFIX
            || ! is_array($request->syntheticSnapshot)
            || $this->syntheticSemanticValidator->validateRequest($request, $request->syntheticSnapshot) !== []) {
            return null;
        }

        $key = $this->credential();
        if ($key === null) {
            return null;
        }
        $validation = $this->validator->validate($request);
        if (! $validation->valid || $validation->xml === null) {
            return null;
        }
        $xml = $this->authenticatedInvoiceXml($validation->xml, $key, $request->billingSettings, downloadPdf: true);
        if ($xml === null || $this->syntheticSemanticValidator->validateExactXml($request, $request->syntheticSnapshot, $xml) !== []) {
            return null;
        }

        return $xml;
    }

    private function sendInvoice(NormalizedInvoiceRequest $request, bool $syntheticTest, ?string $preparedXml = null): ProviderSubmissionResult
    {
        $key = $this->credential();
        if ($key === null) {
            return new ProviderSubmissionResult('blocked', errorCategory: 'credential_unavailable');
        }
        $validation = $preparedXml === null ? $this->validator->validate($request) : null;
        if ($preparedXml === null && (! $validation?->valid || $validation->xml === null)) {
            return new ProviderSubmissionResult('rejected', errorCategory: 'local_validation_failed');
        }
        $xml = $preparedXml ?? $this->authenticatedInvoiceXml($validation->xml, $key, $request->billingSettings, $syntheticTest);
        if ($xml === null) {
            return new ProviderSubmissionResult('rejected', errorCategory: 'local_xml_preparation_failed');
        }

        try {
            $response = $this->request('action-xmlagentxmlfile', $xml);
        } catch (ConnectionException) {
            return new ProviderSubmissionResult('ambiguous', errorCategory: 'provider_transport_ambiguous');
        } catch (\Throwable) {
            // Never include exception text: HTTP/client errors may contain request data.
            return new ProviderSubmissionResult('ambiguous', errorCategory: 'provider_transport_ambiguous');
        }

        $parsed = $this->parser->parse($response->headers(), (string) $response->body());
        if ($response->successful() && is_string($parsed['provider_invoice_id'] ?? null) && $parsed['provider_invoice_id'] !== '' && empty($parsed['error_code'])) {
            $body = (string) $response->body();
            $pdf = $syntheticTest && str_starts_with($body, '%PDF-') && strlen($body) <= 10_485_760 ? $body : null;

            return new ProviderSubmissionResult('issued', $parsed['provider_invoice_id'], metadata: $this->metadata($parsed), pdfContent: $pdf);
        }
        if (! empty($parsed['error_code']) || ($parsed['result'] ?? null) === 'response_contains_error') {
            return new ProviderSubmissionResult('rejected', errorCategory: 'provider_rejected', metadata: $this->metadata($parsed));
        }
        if (! $response->successful()) {
            return new ProviderSubmissionResult('ambiguous', errorCategory: 'provider_http_status_ambiguous', metadata: $this->metadata($parsed));
        }

        return new ProviderSubmissionResult('ambiguous', errorCategory: 'provider_response_ambiguous', metadata: $this->metadata($parsed));
    }

    public function lookupSyntheticTestByExternalReference(string $externalReference): ProviderLookupResult
    {
        if (! $this->syntheticTestGuard->permits()
            || ! preg_match('/\Awpcg-synthetic-test-[0-9]{1,12}\z/D', $externalReference)) {
            return new ProviderLookupResult('blocked');
        }

        return $this->lookup($externalReference, syntheticTest: true);
    }

    public function lookupByExternalReference(string $externalReference): ProviderLookupResult
    {
        return $this->lookup($externalReference, syntheticTest: false);
    }

    private function lookup(string $externalReference, bool $syntheticTest): ProviderLookupResult
    {
        if ($syntheticTest ? ! $this->syntheticTestGuard->permits() : ! $this->lookupPermitted()) {
            return new ProviderLookupResult('blocked');
        }
        if (preg_match('/\A[A-Za-z0-9_.:-]{1,80}\z/D', $externalReference) !== 1) {
            return new ProviderLookupResult('invalid_reference');
        }
        $key = $this->credential();
        if ($key === null) {
            return new ProviderLookupResult('blocked');
        }

        $xml = $this->queryXml($key, $externalReference);
        if ($xml === null) {
            return new ProviderLookupResult('unresolved');
        }
        try {
            $response = $this->request('action-szamla_agent_xml', $xml);
        } catch (\Throwable) {
            return new ProviderLookupResult('ambiguous');
        }
        $parsed = $this->parser->parse($response->headers(), (string) $response->body());
        if (is_string($parsed['provider_invoice_id'] ?? null) && $parsed['provider_invoice_id'] !== '') {
            return new ProviderLookupResult('found', $parsed['provider_invoice_id'], $this->metadata($parsed));
        }

        // An error/no-document response is not treated as proof that invoice creation never happened.
        return new ProviderLookupResult('unresolved', metadata: $this->metadata($parsed));
    }

    private function mutationsPermitted(): bool
    {
        $environments = config('szamlazz.permitted_environments', []);

        return (bool) config('szamlazz.provider_enabled')
            && ! (bool) config('szamlazz.dry_run')
            && (bool) config('szamlazz.issuance_enabled')
            && is_array($environments)
            && in_array(app()->environment(), $environments, true);
    }

    /** Read-only reconciliation remains possible after write gates are closed. */
    private function lookupPermitted(): bool
    {
        $environments = config('szamlazz.permitted_environments', []);

        return is_array($environments) && in_array(app()->environment(), $environments, true);
    }

    private function credential(): ?string
    {
        $value = config('szamlazz.agent_api_key');

        return is_string($value) && $value !== '' && strtolower($value) === $value ? $value : null;
    }

    private function validInvoicePrefix(mixed $prefix): bool
    {
        return is_string($prefix) && preg_match('/\A[A-Z0-9]{1,5}\z/D', $prefix) === 1;
    }

    private function authenticatedInvoiceXml(string $xml, string $key, array $settings, bool $downloadPdf = false): ?string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
                return null;
            }
            $xpath = new \DOMXPath($document);
            $node = $xpath->query('//*[local-name()="szamlaagentkulcs"]')->item(0);
            if (! $node) {
                return null;
            }
            $node->textContent = $key;
            $prefix = $settings['invoice_prefix'] ?? null;
            if (is_string($prefix) && $prefix !== '') {
                $prefixNode = $xpath->query('//*[local-name()="szamlaszamElotag"]')->item(0);
                if ($prefixNode) {
                    $prefixNode->textContent = $prefix;
                }
            }
            if ($downloadPdf) {
                $pdfNode = $xpath->query('//*[local-name()="szamlaLetoltes"]')->item(0);
                if ($pdfNode) {
                    $pdfNode->textContent = 'true';
                }
            }
            $language = $settings['invoice_language'] ?? null;
            $languageNode = $xpath->query('//*[local-name()="szamlaNyelve"]')->item(0);
            if ($languageNode && is_string($language)) {
                $languageNode->textContent = $language;
            }
            $sendEmail = $settings['notify_customer'] ?? false;
            $sendEmailNode = $xpath->query('//*[local-name()="sendEmail"]')->item(0);
            if ($sendEmailNode) {
                $sendEmailNode->textContent = $sendEmail ? 'true' : 'false';
            }

            return $document->saveXML() ?: null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function queryXml(string $key, string $externalReference): ?string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $root = $document->createElementNS('http://www.szamlazz.hu/xmlszamlaxml', 'xmlszamlaxml');
        $root->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:schemaLocation', 'http://www.szamlazz.hu/xmlszamlaxml https://www.szamlazz.hu/szamla/docs/xsds/agentxml/xmlszamlaxml.xsd');
        $document->appendChild($root);
        // Keep element order aligned with the provider's published XSD sequence.
        foreach (['szamlaagentkulcs' => $key, 'pdf' => 'false', 'szamlaKulsoAzon' => $externalReference] as $name => $value) {
            $node = $document->createElementNS('http://www.szamlazz.hu/xmlszamlaxml', $name);
            $node->appendChild($document->createTextNode($value));
            $root->appendChild($node);
        }

        $xml = $document->saveXML();
        $xsd = config('szamlazz.query_xsd_path');
        if (! $xml || ! is_string($xsd) || ! is_file($xsd)) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->schemaValidate($xsd)) {
                return null;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $xml;
    }

    private function request(string $field, string $xml): Response
    {
        $timeout = max(2, min(20, (int) config('szamlazz.request_timeout_seconds', 10)));

        return Http::timeout($timeout)->connectTimeout(min($timeout, 5))
            ->withOptions(['allow_redirects' => false])
            ->accept('application/pdf, application/xml, text/xml')
            ->attach($field, $xml, 'request.xml')
            ->post('https://www.szamlazz.hu/szamla/', []);
    }

    /** @return array<string, int|string|null> */
    private function metadata(array $parsed): array
    {
        return [
            'provider_invoice_id' => is_string($parsed['provider_invoice_id'] ?? null) ? mb_substr($parsed['provider_invoice_id'], 0, 128) : null,
            'error_code' => is_scalar($parsed['error_code'] ?? null) ? mb_substr((string) $parsed['error_code'], 0, 32) : null,
            'net_amount' => is_numeric($parsed['net_amount'] ?? null) ? mb_substr((string) $parsed['net_amount'], 0, 32) : null,
            'gross_amount' => is_numeric($parsed['gross_amount'] ?? null) ? mb_substr((string) $parsed['gross_amount'], 0, 32) : null,
        ];
    }
}
