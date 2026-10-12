<?php

namespace Tests\Feature\Monitoring;

use App\Connector\HealthStatus;
use App\Models\MonitoringResult;
use App\Models\Website;
use App\Monitoring\MonitoringEvidence;
use App\Monitoring\PublicAddressLookup;
use App\Monitoring\PublicOrigin;
use App\Monitoring\WebsiteProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WebsiteProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dns_pinned_probe_does_not_select_guzzles_stream_handler(): void
    {
        $this->assertTrue(function_exists('curl_init'));
        $origin = PublicOrigin::resolve('https://example.com', new class implements PublicAddressLookup
        {
            public function addresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        $probe = app(WebsiteProbe::class);
        $requestOptions = (new \ReflectionMethod($probe, 'pinnedRequestOptions'))->invoke($probe, $origin, true);

        $this->assertArrayNotHasKey('stream', $requestOptions);
        $this->assertArrayHasKey('curl', $requestOptions);
        $this->assertSame(['example.com:443:93.184.216.34'], $requestOptions['curl'][CURLOPT_RESOLVE]);
        $this->assertTrue($requestOptions['curl'][CURLOPT_CERTINFO]);
    }

    public function test_large_response_limit_marks_intentional_abort_as_truncation(): void
    {
        $probe = app(WebsiteProbe::class);
        $truncated = false;
        $callback = (new \ReflectionMethod($probe, 'progressCallback'))->invokeArgs($probe, [&$truncated]);

        $callback(100000, 65536);
        $this->assertFalse($truncated);

        try {
            $callback(100000, 65537);
            $this->fail('Expected the bounded response callback to abort an oversized body.');
        } catch (\DomainException $exception) {
            $this->assertSame('monitoring.response_too_large', $exception->getMessage());
        }

        $this->assertTrue($truncated);
        MonitoringEvidence::assertSafe(['response_truncated' => $truncated]);
    }

    public function test_redirect_dns_rebinding_to_private_ip_is_blocked_before_followup_request(): void
    {
        $website = Website::factory()->create(['url' => 'https://example.com']);
        $lookup = new class implements PublicAddressLookup
        {
            private int $calls = 0;

            public function addresses(string $host): array
            {
                $this->calls++;

                return $this->calls === 1 ? ['93.184.216.34'] : ['127.0.0.1'];
            }
        };
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response('', 302, ['Location' => 'https://example.com/next']));

        app()->instance(PublicAddressLookup::class, $lookup);
        app(WebsiteProbe::class)->run($website);

        Http::assertSentCount(1);
        $result = MonitoringResult::query()->where('check_id', 'website.http')->firstOrFail();
        $this->assertSame(HealthStatus::Attention, $result->state);
        $this->assertSame(1, $result->consecutive_failures);
        $this->assertSame('http.redirect_failure', $result->reason_code);
    }

    public function test_http_probe_keeps_failure_candidate_attention_until_confirmation(): void
    {
        $website = Website::factory()->create(['url' => 'http://example.com']);
        app()->instance(PublicAddressLookup::class, new class implements PublicAddressLookup
        {
            public function addresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response('', 503));

        app(WebsiteProbe::class)->run($website);

        $result = MonitoringResult::query()->where('check_id', 'website.http')->firstOrFail();
        $this->assertSame(HealthStatus::Attention, $result->state);
        $this->assertSame(1, $result->consecutive_failures);
        $this->assertSame('http.bad_status', $result->reason_code);
        $this->assertSame(503, $result->evidence['http_status']);
        $this->assertFalse($result->evidence['response_truncated']);
        $this->assertSame(now()->addMinutes(5)->timestamp, $result->next_check_at?->timestamp);
    }
}
