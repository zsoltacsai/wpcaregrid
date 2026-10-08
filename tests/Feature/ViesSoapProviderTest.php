<?php

namespace Tests\Feature;

use App\Tax\Vat\VatProviderUnavailable;
use App\Tax\Vat\ViesSoapProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ViesSoapProviderTest extends TestCase
{
    public function test_it_sends_a_country_separated_soap_request_and_parses_validity_and_optional_evidence(): void
    {
        $endpoint = (string) config('tax.vies.endpoint');
        Http::fake([$endpoint => Http::response($this->response(true), 200)]);

        $result = app(ViesSoapProvider::class)->validate('HU', '12345678');

        $this->assertTrue($result->valid);
        $this->assertSame('2026-10-08+02:00', $result->requestDate);
        $this->assertSame('vies-ref-test', $result->referenceId);
        $this->assertSame('Example Kft', $result->name);
        Http::assertSent(function (Request $request) use ($endpoint) {
            return $request->url() === $endpoint
                && str_contains($request->body(), '<urn:countryCode>HU</urn:countryCode>')
                && str_contains($request->body(), '<urn:vatNumber>12345678</urn:vatNumber>');
        });
    }

    public function test_provider_false_is_a_valid_invalid_result_and_server_error_is_unavailable(): void
    {
        $endpoint = (string) config('tax.vies.endpoint');
        Http::fake([
            $endpoint => Http::sequence()
                ->push($this->response(false), 200)
                ->push('upstream unavailable', 503),
        ]);
        $this->assertFalse(app(ViesSoapProvider::class)->validate('HU', '12345678')->valid);

        config(['tax.vies.retries' => 0]);
        try {
            app(ViesSoapProvider::class)->validate('HU', '12345678');
            $this->fail('Provider failures must not be returned as an invalid VAT result.');
        } catch (VatProviderUnavailable) {
            $this->assertTrue(true);
        }
    }

    public function test_soap_fault_does_not_become_an_invalid_identifier(): void
    {
        $endpoint = (string) config('tax.vies.endpoint');
        $fault = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultstring>MS_UNAVAILABLE</faultstring></soap:Fault></soap:Body></soap:Envelope>';
        Http::fake([$endpoint => Http::response($fault, 200)]);

        $this->expectException(VatProviderUnavailable::class);
        app(ViesSoapProvider::class)->validate('HU', '12345678');
    }

    public function test_provider_metadata_is_bounded_to_the_evidence_column_sizes(): void
    {
        $endpoint = (string) config('tax.vies.endpoint');
        $xml = str_replace(
            '<requestIdentifier>vies-ref-test</requestIdentifier>',
            '<requestIdentifier>'.str_repeat('r', 300).'</requestIdentifier>',
            $this->response(true),
        );
        Http::fake([$endpoint => Http::response($xml, 200)]);

        $result = app(ViesSoapProvider::class)->validate('HU', '12345678');

        $this->assertSame(160, mb_strlen($result->referenceId));
        $this->assertLessThanOrEqual(64, mb_strlen($result->requestDate));
    }

    public function test_transient_provider_failures_retry_with_a_bound_and_end_as_unavailable(): void
    {
        $endpoint = (string) config('tax.vies.endpoint');
        config(['tax.vies.retries' => 1, 'tax.vies.retry_delay_ms' => 0]);
        Http::fake([
            $endpoint => Http::sequence()
                ->push('temporarily unavailable', 503)
                ->push('still unavailable', 503),
        ]);

        try {
            app(ViesSoapProvider::class)->validate('HU', '12345678');
            $this->fail('Retry exhaustion must remain unavailable, not invalid.');
        } catch (VatProviderUnavailable) {
            Http::assertSentCount(2);
        }
    }

    public function test_connection_timeout_retries_and_remains_unavailable(): void
    {
        $endpoint = (string) config('tax.vies.endpoint');
        config(['tax.vies.retries' => 1, 'tax.vies.retry_delay_ms' => 0]);
        $attempts = 0;
        Http::fake([$endpoint => static function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('Simulated VIES timeout.');
        }]);

        try {
            app(ViesSoapProvider::class)->validate('HU', '12345678');
            $this->fail('A VIES connection timeout must remain unavailable.');
        } catch (VatProviderUnavailable) {
            $this->assertSame(2, $attempts);
        }
    }

    private function response(bool $valid): string
    {
        $validText = $valid ? 'true' : 'false';

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <checkVatResponse xmlns="urn:ec.europa.eu:taxud:vies:services:checkVat:types">
      <countryCode>HU</countryCode>
      <vatNumber>12345678</vatNumber>
      <requestDate>2026-10-08+02:00</requestDate>
      <valid>{$validText}</valid>
      <name>Example Kft</name>
      <address>Example utca 1, Budapest</address>
      <requestIdentifier>vies-ref-test</requestIdentifier>
    </checkVatResponse>
  </soap:Body>
</soap:Envelope>
XML;
    }
}
