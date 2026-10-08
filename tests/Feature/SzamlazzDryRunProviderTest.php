<?php

namespace Tests\Feature;

use App\Invoicing\ApprovedTaxDecision;
use App\Invoicing\NormalizedInvoiceRequest;
use App\Invoicing\Providers\InvoiceProvider;
use Tests\TestCase;

class SzamlazzDryRunProviderTest extends TestCase
{
    public function test_valid_payload_is_locally_schema_validated_and_xml_uses_documented_order(): void
    {
        $result = app(InvoiceProvider::class)->validate($this->request());

        $this->assertTrue($result->valid, implode(', ', $result->errors));
        $this->assertNotNull($result->xml);
        $this->assertStringContainsString('xmlns="http://www.szamlazz.hu/xmlszamla"', $result->xml);
        $this->assertLessThan(strpos($result->xml, '<irsz>'), strpos($result->xml, '<orszag>'));
        $this->assertStringNotContainsString('agent-secret', $result->xml);
    }

    public function test_inconsistent_amount_and_invalid_date_fail_closed(): void
    {
        $provider = app(InvoiceProvider::class);
        $badAmount = $provider->validate($this->request(amountMinor: 80101));
        $badDate = $provider->validate($this->request(issueDate: ''));

        $this->assertFalse($badAmount->valid);
        $this->assertContains('invoice_total_mismatch', $badAmount->errors);
        $this->assertFalse($badDate->valid);
        $this->assertContains('invalid_issue_date', $badDate->errors);
    }

    public function test_missing_local_schema_fails_closed(): void
    {
        config(['szamlazz.xsd_path' => sys_get_temp_dir().'/missing-szamlazz-schema.xsd']);

        $result = app(InvoiceProvider::class)->validate($this->request());

        $this->assertFalse($result->valid);
        $this->assertContains('local_schema_unavailable', $result->errors);
    }

    public function test_external_reference_preserves_opaque_case_and_rejects_missing_or_malformed_values(): void
    {
        $provider = app(InvoiceProvider::class);
        $stripeInvoiceId = 'in_1uO9bWIotueNMW7s0V17DUZ1';
        $mixedCaseReference = 'in_MiXeD-123:abc.42';

        $stripeIdResult = $provider->validate($this->request(externalReference: $stripeInvoiceId));
        $mixedCaseResult = $provider->validate($this->request(externalReference: $mixedCaseReference));
        $missingResult = $provider->validate($this->request(externalReference: ''));
        $malformedResult = $provider->validate($this->request(externalReference: 'in/invalid'));

        $this->assertTrue($stripeIdResult->valid, implode(', ', $stripeIdResult->errors));
        $this->assertStringContainsString($stripeInvoiceId, $stripeIdResult->xml);
        $this->assertTrue($mixedCaseResult->valid, implode(', ', $mixedCaseResult->errors));
        $this->assertStringContainsString($mixedCaseReference, $mixedCaseResult->xml);
        $this->assertContains('invalid_external_reference', $missingResult->errors);
        $this->assertContains('invalid_external_reference', $malformedResult->errors);
    }

    public function test_fixture_response_parser_never_claims_phase_one_provider_acceptance(): void
    {
        $result = app(InvoiceProvider::class)->parseResponse(['szlahu_szamlaszam' => 'TEST-123'], '');

        $this->assertSame('response_contains_invoice_number', $result['result']);
        $this->assertNotSame('provider_accepted', $result['result']);
    }

    public function test_configuration_is_fail_closed_and_provider_contract_has_no_submission_method(): void
    {
        $this->assertFalse((bool) config('szamlazz.provider_enabled'));
        $this->assertTrue((bool) config('szamlazz.dry_run'));
        $this->assertFalse((bool) config('szamlazz.issuance_enabled'));
        $this->assertFalse(method_exists(InvoiceProvider::class, 'submit'));
        $this->assertFalse(method_exists(InvoiceProvider::class, 'issue'));
    }

    private function request(?int $amountMinor = null, string $issueDate = '2026-10-08', string $performanceDate = '2026-10-08', string $paymentDueDate = '2026-10-08', string $externalReference = 'in_1UO9bWIotueNMW7s0V17DUZ1'): NormalizedInvoiceRequest
    {
        $tax = new ApprovedTaxDecision('test-only-reference', 1, [
            'net' => 80100,
            'tax' => 0,
            'gross' => 80100,
            'tax_code' => 'TEST-ONLY-NOT-AN-ACCOUNTANT-APPROVED-CODE',
        ], 'en', '1.000000', 'TEST BANK');

        return new NormalizedInvoiceRequest(
            $externalReference, 'EUR', $amountMinor ?? 80100, $issueDate, $performanceDate, $paymentDueDate, '2026-10-08', 'Card',
            ['name' => 'Test Seller', 'country' => 'HU', 'postal_code' => '0000', 'city' => 'Test', 'address' => 'Test 1'],
            ['name' => 'Test Buyer', 'country' => 'DE', 'postal_code' => '00000', 'city' => 'Test', 'address' => 'Test 2'],
            'Test service', $tax,
        );
    }
}
