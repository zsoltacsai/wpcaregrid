<?php

namespace Tests\Feature;

use App\Invoicing\ApprovedTaxDecision;
use App\Invoicing\NormalizedInvoiceRequest;
use App\Invoicing\Providers\SzamlazzAgentProvider;
use App\Invoicing\Providers\SzamlazzDryRunProvider;
use App\Invoicing\Providers\SzamlazzResponseParser;
use App\Invoicing\SyntheticTestTaxSelection;
use App\Invoicing\SzamlazzPaymentMethodRegistry;
use App\Owner\IntegrationCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class SzamlazzAgentProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->app->detectEnvironment(fn () => 'testing');
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'szamlazz.provider_enabled' => true,
            'szamlazz.dry_run' => false,
            'szamlazz.issuance_enabled' => true,
            'szamlazz.permitted_environments' => ['testing'],
            'szamlazz.agent_api_key' => 'synthetic-agent-key',
            'szamlazz.request_timeout_seconds' => 5,
            'szamlazz.xsd_path' => resource_path('schemas/szamlazz-agent-xmlszamla.xsd'),
            'szamlazz.query_xsd_path' => resource_path('schemas/szamlazz-agent-xmlszamlaxml.xsd'),
        ]);
    }

    public function test_closed_gates_prevent_any_provider_http_request(): void
    {
        config(['szamlazz.provider_enabled' => false]);
        Http::preventStrayRequests();

        $result = app(SzamlazzAgentProvider::class)->submit($this->request());

        $this->assertSame('blocked', $result->outcome);
        $this->assertSame('provider_gates_closed', $result->errorCategory);
        Http::assertNothingSent();
    }

    public function test_fake_success_uses_https_multipart_and_parses_invoice_number_without_persisting_pdf_or_payload(): void
    {
        Http::preventStrayRequests();
        Http::fake(['www.szamlazz.hu/*' => Http::response('xmlagentresponse=DONE;WPCG-TEST-1', 200, [
            'Content-Type' => 'text/plain', 'szlahu_szamlaszam' => 'WPCG-TEST-1',
        ])]);

        $result = app(SzamlazzAgentProvider::class)->submit($this->request());

        $this->assertSame('issued', $result->outcome);
        $this->assertSame('WPCG-TEST-1', $result->invoiceNumber);
        $this->assertSame(['provider_invoice_id' => 'WPCG-TEST-1', 'error_code' => null, 'net_amount' => null, 'gross_amount' => null], $result->metadata);
        Http::assertSent(fn ($request) => $request->url() === 'https://www.szamlazz.hu/szamla/'
            && str_contains($request->body(), 'action-xmlagentxmlfile')
            && str_contains($request->body(), '<szamlaagentkulcs>synthetic-agent-key</szamlaagentkulcs>')
            && str_contains($request->body(), '<szamlaszamElotag>WPCG</szamlaszamElotag>')
            && str_contains($request->body(), '<sendEmail>true</sendEmail>'));
    }

    public function test_synthetic_test_submission_uses_separate_staging_guard_and_requests_pdf_without_customer_email(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config([
            'app.url' => 'https://staging.wpcaregrid.com',
            'szamlazz.synthetic_test.enabled' => true,
            'szamlazz.provider_enabled' => false,
            'szamlazz.dry_run' => true,
            'szamlazz.issuance_enabled' => false,
            'szamlazz.agent_api_key' => 'synthetic-agent-key',
        ]);
        app(IntegrationCredentials::class)->store('szamlazz_agent', 'synthetic-agent-key');
        Http::preventStrayRequests();
        Http::fake(['www.szamlazz.hu/*' => Http::response("%PDF-1.4\nTEST", 200, [
            'Content-Type' => 'application/pdf', 'szlahu_szamlaszam' => 'TEST-2026-1',
        ])]);

        $validation = app(SzamlazzDryRunProvider::class)->validate($this->syntheticRequest());
        $this->assertTrue($validation->valid, implode(',', $validation->errors));

        $result = app(SzamlazzAgentProvider::class)->submitSyntheticTest($this->syntheticRequest());

        $this->assertSame('issued', $result->outcome);
        $this->assertSame('TEST-2026-1', $result->invoiceNumber);
        $this->assertSame("%PDF-1.4\nTEST", $result->pdfContent);
        Http::assertSent(fn ($request) => $request->url() === 'https://www.szamlazz.hu/szamla/'
            && str_contains($request->body(), '<szamlaLetoltes>true</szamlaLetoltes>')
            && str_contains($request->body(), '<sendEmail>false</sendEmail>')
            && str_contains($request->body(), '<email>') === false
            && str_contains($request->body(), '<szamlaagentkulcs>synthetic-agent-key</szamlaagentkulcs>'));
    }

    public function test_synthetic_test_provider_rejects_wrong_host_and_wrong_test_data_without_http(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.url' => 'https://wpcaregrid.com', 'szamlazz.synthetic_test.enabled' => true]);
        app(IntegrationCredentials::class)->store('szamlazz_agent', 'synthetic-agent-key');
        Http::preventStrayRequests();

        $result = app(SzamlazzAgentProvider::class)->submitSyntheticTest($this->syntheticRequest());

        $this->assertSame('blocked', $result->outcome);
        $this->assertSame('synthetic_test_guard_failed', $result->errorCategory);
        Http::assertNothingSent();
    }

    public function test_pdf_response_is_not_saved_and_provider_errors_are_sanitized(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push('%PDF-synthetic-document', 200, ['Content-Type' => 'application/pdf', 'szlahu_szamlaszam' => 'WPCG-PDF-1'])
            ->push('[ERR] PRIVATE PROVIDER DETAIL', 200, ['Content-Type' => 'text/plain']);
        $pdf = app(SzamlazzAgentProvider::class)->submit($this->request());
        $this->assertSame('issued', $pdf->outcome);
        $this->assertArrayNotHasKey('pdf', $pdf->metadata);
        $this->assertArrayNotHasKey('body', $pdf->metadata);

        $error = app(SzamlazzAgentProvider::class)->submit($this->request());
        $this->assertSame('rejected', $error->outcome);
        $this->assertSame('provider_rejected', $error->errorCategory);
        $this->assertStringNotContainsString('PRIVATE PROVIDER DETAIL', json_encode($error, JSON_THROW_ON_ERROR));
    }

    public function test_malformed_and_timed_out_responses_remain_ambiguous_without_raw_exception_text(): void
    {
        Http::preventStrayRequests();
        Http::fake(['www.szamlazz.hu/*' => Http::response('<broken', 200, ['Content-Type' => 'application/xml'])]);
        $malformed = app(SzamlazzAgentProvider::class)->submit($this->request());
        $this->assertSame('ambiguous', $malformed->outcome);

        Http::fake(fn () => throw new ConnectionException('synthetic secret-shaped failure detail'));
        Log::spy();
        $timeout = app(SzamlazzAgentProvider::class)->submit($this->request());
        $this->assertSame('ambiguous', $timeout->outcome);
        $this->assertSame('provider_transport_ambiguous', $timeout->errorCategory);
        Log::shouldNotHaveReceived('error');
        $this->assertStringNotContainsString('synthetic secret-shaped failure detail', json_encode($timeout, JSON_THROW_ON_ERROR));
    }

    public function test_documented_read_only_external_reference_query_is_xsd_validated(): void
    {
        Http::preventStrayRequests();
        Http::fake(['www.szamlazz.hu/*' => Http::response('<xmlszamlaxml><szamlaszam>WPCG-TEST-2</szamlaszam></xmlszamlaxml>', 200, ['Content-Type' => 'application/xml'])]);

        $result = app(SzamlazzAgentProvider::class)->lookupByExternalReference('wpcg-invoice-123');

        $this->assertSame('found', $result->outcome);
        $this->assertSame('WPCG-TEST-2', $result->invoiceNumber);
        Http::assertSent(fn ($request) => str_contains($request->body(), 'action-szamla_agent_xml')
            && str_contains($request->body(), '<szamlaKulsoAzon>wpcg-invoice-123</szamlaKulsoAzon>')
            && str_contains($request->body(), 'xmlns="http://www.szamlazz.hu/xmlszamlaxml"'));
    }

    public function test_read_only_reconciliation_remains_available_when_invoice_write_gates_are_closed(): void
    {
        config(['szamlazz.provider_enabled' => false, 'szamlazz.dry_run' => true, 'szamlazz.issuance_enabled' => false]);
        Http::preventStrayRequests();
        Http::fake(['www.szamlazz.hu/*' => Http::response('<xmlszamlaxml><szamlaszam>WPCG-RECOVERED-1</szamlaszam></xmlszamlaxml>', 200, ['Content-Type' => 'application/xml'])]);

        $result = app(SzamlazzAgentProvider::class)->lookupByExternalReference('wpcg-invoice-123');

        $this->assertSame('found', $result->outcome);
        Http::assertSent(fn ($request) => str_contains($request->body(), 'action-szamla_agent_xml'));
    }

    public function test_non_success_http_status_with_an_invoice_number_is_still_ambiguous(): void
    {
        Http::preventStrayRequests();
        Http::fake(['www.szamlazz.hu/*' => Http::response('temporary failure', 503, ['szlahu_szamlaszam' => 'WPCG-UNKNOWN-1'])]);

        $result = app(SzamlazzAgentProvider::class)->submit($this->request());

        $this->assertSame('ambiguous', $result->outcome);
        $this->assertSame('provider_http_status_ambiguous', $result->errorCategory);
    }

    public function test_payment_method_labels_follow_invoice_language_and_unknown_labels_are_not_guessed(): void
    {
        $this->assertSame('Bank card', SzamlazzPaymentMethodRegistry::label('bank_card', 'en'));
        $this->assertSame('bankkártya', SzamlazzPaymentMethodRegistry::label('bank_card', 'hu'));
        $this->assertSame('Carte bancaire', SzamlazzPaymentMethodRegistry::label('bank_card', 'fr'));
        $this->assertNull(SzamlazzPaymentMethodRegistry::label('unrecognized', 'en'));
    }

    public function test_provider_invoice_identifiers_and_errors_are_sanitized(): void
    {
        $parsed = app(SzamlazzResponseParser::class)->parse([
            'szlahu_szamlaszam' => ['WPCG-%3Cscript%3E'],
            'szlahu_error_code' => ["bad\nprivate detail"],
        ], '');

        $this->assertNull($parsed['provider_invoice_id']);
        $this->assertSame('provider_reported_error', $parsed['error_code']);
        $this->assertSame('response_contains_error', $parsed['result']);
    }

    private function request(): NormalizedInvoiceRequest
    {
        return new NormalizedInvoiceRequest(
            'wpcg-invoice-1', 'EUR', 80100, '2026-10-08', '2026-10-08', '2026-10-16', '2026-10-08', 'bank transfer',
            ['name' => 'Synthetic Seller', 'country' => 'HU', 'postal_code' => '1000', 'city' => 'Budapest', 'address' => 'Test street 1'],
            ['name' => 'Synthetic Buyer', 'country' => 'DE', 'postal_code' => '10115', 'city' => 'Berlin', 'address' => 'Example street 1', 'email' => 'buyer@example.test'],
            'Managed WordPress maintenance',
            new ApprovedTaxDecision('test-tax-decision', 1, ['net' => 80100, 'tax' => 0, 'gross' => 80100, 'tax_code' => 'AAM'], 'en', '1.00000000', 'Test FX source'),
            ['invoice_language' => 'en', 'invoice_prefix' => 'WPCG', 'notify_customer' => true],
        );
    }

    private function syntheticRequest(): NormalizedInvoiceRequest
    {
        return new NormalizedInvoiceRequest(
            'wpcg-synthetic-test-1', 'HUF', 1000, '2026-10-08', '2026-10-08', '2026-10-08', null, 'bankkártya',
            ['name' => 'WP CareGrid Synthetic TEST Seller', 'country' => 'HU', 'postal_code' => '1111', 'city' => 'Budapest', 'address' => 'Synthetic Test Street 1'],
            ['name' => 'WP CareGrid Synthetic TEST Buyer', 'country' => 'HU', 'postal_code' => '1111', 'city' => 'Budapest', 'address' => 'Synthetic Test Street 1'],
            'WP CareGrid synthetic Szamlazz integration test',
            new SyntheticTestTaxSelection('synthetic-provider-code-selection', 1, ['net' => 1000, 'tax' => 0, 'gross' => 1000, 'tax_code' => 'AAM']),
            ['invoice_language' => 'hu', 'notify_customer' => false],
        );
    }
}
