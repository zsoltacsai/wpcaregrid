<?php

namespace Tests\Feature\Cloudflare;

use App\Cloudflare\CloudflareApiClient;
use App\Cloudflare\CloudflareDnsException;
use App\Cloudflare\CloudflareDnsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareDnsServiceTest extends TestCase
{
    private const ZONE_ID = '0123456789abcdef0123456789abcdef';

    private const RECORD_ID = 'abcdef0123456789abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.cloudflare.api_token' => 'test-token-never-real', 'cloudflare.allowed_zones' => ['wpcaregrid.com']]);
    }

    public function test_zone_resolution_and_record_reads_use_api_and_paginate(): void
    {
        $seenPages = [];
        Http::fake(function (Request $request) use (&$seenPages) {
            if (str_contains($request->url(), '/zones') && ! str_contains($request->url(), '/dns_records')) {
                return Http::response(['success' => true, 'result' => [['id' => self::ZONE_ID, 'name' => 'wpcaregrid.com']], 'result_info' => ['total_pages' => 1]]);
            }
            if (str_contains($request->url(), '/dns_records')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $seenPages[] = $query['page'] ?? null;

                return Http::response(['success' => true, 'result' => [['id' => self::RECORD_ID, 'type' => 'TXT', 'name' => 'wpcaregrid.com', 'content' => 'v=spf1 -all', 'ttl' => 1, 'proxied' => false]], 'result_info' => ['total_pages' => 2]]);
            }

            return Http::response(['success' => true, 'result' => ['status' => 'active']]);
        });

        $records = app(CloudflareDnsService::class)->listRecords('WPCareGrid.com.');

        $this->assertCount(2, $records);
        $this->assertSame(['1', '2'], $seenPages);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-token-never-real'));
    }

    public function test_unapproved_zone_and_forged_zone_name_are_rejected_before_mutation(): void
    {
        Http::fake();
        try {
            app(CloudflareDnsService::class)->planEnsure('example.net', ['type' => 'A', 'name' => 'a.example.net', 'content' => '192.0.2.1']);
            $this->fail('Unapproved zone should be rejected.');
        } catch (CloudflareDnsException $exception) {
            $this->assertSame('zone_not_allowed', $exception->category);
        }
        Http::assertNothingSent();
    }

    public function test_ensure_creates_updates_and_leaves_identical_records_unchanged(): void
    {
        $record = ['id' => self::RECORD_ID, 'type' => 'A', 'name' => 'test.wpcaregrid.com', 'content' => '192.0.2.2', 'ttl' => 300, 'proxied' => false, 'priority' => null, 'comment' => null];
        $mode = 'create';
        Http::fake(function (Request $request) use (&$record, &$mode) {
            $url = $request->url();
            if (str_contains($url, '/zones') && ! str_contains($url, '/dns_records')) {
                return Http::response(['success' => true, 'result' => [['id' => self::ZONE_ID, 'name' => 'wpcaregrid.com']], 'result_info' => ['total_pages' => 1]]);
            }
            if (str_contains($url, '/dns_records') && $request->method() === 'GET') {
                return Http::response(['success' => true, 'result' => $mode === 'create' ? [] : [$record], 'result_info' => ['total_pages' => 1]]);
            }
            if ($request->method() === 'POST') {
                $record = ['id' => self::RECORD_ID, ...$request->data()];

                return Http::response(['success' => true, 'result' => $record]);
            }
            if ($request->method() === 'PUT') {
                $record = ['id' => self::RECORD_ID, ...$request->data()];

                return Http::response(['success' => true, 'result' => $record]);
            }

            return Http::response(['success' => true, 'result' => []]);
        });

        $service = app(CloudflareDnsService::class);
        $input = ['type' => 'A', 'name' => 'test.wpcaregrid.com', 'content' => '192.0.2.1', 'ttl' => 300, 'proxied' => false, 'zone_id' => 'https://attacker.example/zone'];
        $createPlan = $service->planEnsure('wpcaregrid.com', $input);
        $this->assertSame('created', $createPlan['action']);
        $this->assertSame('created', $service->applyEnsure('wpcaregrid.com', $input, $createPlan)['action']);

        $mode = 'update';
        $updateInput = [...$input, 'content' => '192.0.2.3', 'record_id' => self::RECORD_ID];
        $updatePlan = $service->planEnsure('wpcaregrid.com', $updateInput);
        $this->assertSame('updated', $updatePlan['action']);
        $service->applyEnsure('wpcaregrid.com', $updateInput, $updatePlan);

        $unchangedPlan = $service->planEnsure('wpcaregrid.com', $updateInput);
        $this->assertSame('unchanged', $unchangedPlan['action']);
        $service->applyEnsure('wpcaregrid.com', $updateInput, $unchangedPlan);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/zones/'.self::ZONE_ID.'/dns_records'));
    }

    public function test_duplicate_matching_records_are_conflicts(): void
    {
        Http::fake(function (Request $request) {
            return (str_contains($request->url(), '/zones') && ! str_contains($request->url(), '/dns_records'))
                ? Http::response(['success' => true, 'result' => [['id' => self::ZONE_ID, 'name' => 'wpcaregrid.com']], 'result_info' => ['total_pages' => 1]])
                : Http::response(['success' => true, 'result' => [
                    ['id' => self::RECORD_ID, 'type' => 'A', 'name' => 'a.wpcaregrid.com', 'content' => '192.0.2.1', 'ttl' => 1, 'proxied' => false],
                    ['id' => 'bbbbbb0123456789abcdef0123456789', 'type' => 'A', 'name' => 'a.wpcaregrid.com', 'content' => '192.0.2.1', 'ttl' => 1, 'proxied' => false],
                ], 'result_info' => ['total_pages' => 1]]);
        });

        $this->expectException(CloudflareDnsException::class);
        app(CloudflareDnsService::class)->planEnsure('wpcaregrid.com', ['type' => 'A', 'name' => 'a.wpcaregrid.com', 'content' => '192.0.2.1']);
    }

    public function test_duplicate_cname_records_cannot_be_edited_one_at_a_time(): void
    {
        Http::fake(function (Request $request) {
            return (str_contains($request->url(), '/zones') && ! str_contains($request->url(), '/dns_records'))
                ? Http::response(['success' => true, 'result' => [['id' => self::ZONE_ID, 'name' => 'wpcaregrid.com']], 'result_info' => ['total_pages' => 1]])
                : Http::response(['success' => true, 'result' => [
                    ['id' => self::RECORD_ID, 'type' => 'CNAME', 'name' => 'alias.wpcaregrid.com', 'content' => 'old.wpcaregrid.com', 'ttl' => 1, 'proxied' => false],
                    ['id' => 'bbbbbb0123456789abcdef0123456789', 'type' => 'CNAME', 'name' => 'alias.wpcaregrid.com', 'content' => 'old.wpcaregrid.com', 'ttl' => 1, 'proxied' => false],
                ], 'result_info' => ['total_pages' => 1]]);
        });

        try {
            app(CloudflareDnsService::class)->planEnsure('wpcaregrid.com', [
                'type' => 'CNAME', 'name' => 'alias.wpcaregrid.com', 'content' => 'new.wpcaregrid.com', 'record_id' => self::RECORD_ID,
            ]);
            $this->fail('Duplicate CNAME records should require conflict resolution.');
        } catch (CloudflareDnsException $exception) {
            $this->assertSame('record_conflict', $exception->category);
        }
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_protected_names_cannot_be_created_or_changed_through_ensure(): void
    {
        $staging = ['id' => self::RECORD_ID, 'type' => 'A', 'name' => 'staging.wpcaregrid.com', 'content' => '192.0.2.10', 'ttl' => 1, 'proxied' => false];
        $mail = ['id' => 'bbbbbb0123456789abcdef0123456789', 'type' => 'A', 'name' => 'mail.wpcaregrid.com', 'content' => '192.0.2.20', 'ttl' => 1, 'proxied' => false];
        Http::fake(function (Request $request) use ($staging, $mail) {
            if (str_contains($request->url(), '/zones') && ! str_contains($request->url(), '/dns_records')) {
                return Http::response(['success' => true, 'result' => [['id' => self::ZONE_ID, 'name' => 'wpcaregrid.com']], 'result_info' => ['total_pages' => 1]]);
            }

            return Http::response(['success' => true, 'result' => [$staging, $mail], 'result_info' => ['total_pages' => 1]]);
        });

        foreach ([
            ['type' => 'A', 'name' => 'STAGING.WPCAREGRID.COM.', 'content' => '192.0.2.11', 'record_id' => self::RECORD_ID],
            ['type' => 'A', 'name' => 'mail.wpcaregrid.com', 'content' => '192.0.2.21'],
        ] as $input) {
            try {
                app(CloudflareDnsService::class)->planEnsure('wpcaregrid.com', $input);
                $this->fail('Protected record names must not be created or changed.');
            } catch (CloudflareDnsException $exception) {
                $this->assertSame('record_protected', $exception->category);
            }
        }

        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_ensure_preview_is_replanned_and_stale_state_is_not_applied(): void
    {
        $record = ['id' => self::RECORD_ID, 'type' => 'A', 'name' => 'test.wpcaregrid.com', 'content' => '192.0.2.1', 'ttl' => 1, 'proxied' => false];
        $changed = false;
        Http::fake(function (Request $request) use ($record, &$changed) {
            if (str_contains($request->url(), '/zones') && ! str_contains($request->url(), '/dns_records')) {
                return Http::response(['success' => true, 'result' => [['id' => self::ZONE_ID, 'name' => 'wpcaregrid.com']], 'result_info' => ['total_pages' => 1]]);
            }
            if ($request->method() === 'GET') {
                $live = $changed ? [...$record, 'content' => '192.0.2.9'] : $record;

                return Http::response(['success' => true, 'result' => [$live], 'result_info' => ['total_pages' => 1]]);
            }
            $changed = true;

            return Http::response(['success' => true, 'result' => []]);
        });

        $service = app(CloudflareDnsService::class);
        $input = ['type' => 'A', 'name' => 'test.wpcaregrid.com', 'content' => '192.0.2.2', 'record_id' => self::RECORD_ID];
        $plan = $service->planEnsure('wpcaregrid.com', $input);
        $changed = true;

        try {
            $service->applyEnsure('wpcaregrid.com', $input, $plan);
            $this->fail('A stale preview must not be applied.');
        } catch (CloudflareDnsException $exception) {
            $this->assertSame('record_conflict', $exception->category);
        }

        Http::assertNotSent(fn (Request $request) => in_array($request->method(), ['POST', 'PUT', 'DELETE'], true));
    }

    public function test_record_ids_are_resolved_from_the_approved_zone_not_trusted_from_input(): void
    {
        Http::fake(function (Request $request) {
            return (str_contains($request->url(), '/zones') && ! str_contains($request->url(), '/dns_records'))
                ? Http::response(['success' => true, 'result' => [['id' => self::ZONE_ID, 'name' => 'wpcaregrid.com']], 'result_info' => ['total_pages' => 1]])
                : Http::response(['success' => true, 'result' => [['id' => self::RECORD_ID, 'type' => 'A', 'name' => 'a.wpcaregrid.com', 'content' => '192.0.2.1', 'ttl' => 1, 'proxied' => false]], 'result_info' => ['total_pages' => 1]]);
        });

        try {
            app(CloudflareDnsService::class)->planDelete('wpcaregrid.com', 'foreign-zone-record-id');
            $this->fail('A record ID not returned for the resolved zone must be rejected.');
        } catch (CloudflareDnsException $exception) {
            $this->assertSame('record_not_found', $exception->category);
        }
        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
    }

    public function test_cloudflare_record_response_cannot_escape_the_resolved_zone(): void
    {
        Http::fake(function (Request $request) {
            return (str_contains($request->url(), '/zones') && ! str_contains($request->url(), '/dns_records'))
                ? Http::response(['success' => true, 'result' => [['id' => self::ZONE_ID, 'name' => 'wpcaregrid.com']], 'result_info' => ['total_pages' => 1]])
                : Http::response(['success' => true, 'result' => [['id' => self::RECORD_ID, 'type' => 'TXT', 'name' => 'attacker.example', 'content' => 'x', 'ttl' => 1, 'proxied' => false]], 'result_info' => ['total_pages' => 1]]);
        });

        try {
            app(CloudflareDnsService::class)->planDelete('wpcaregrid.com', self::RECORD_ID);
            $this->fail('An out-of-zone record returned by the provider must be rejected.');
        } catch (CloudflareDnsException $exception) {
            $this->assertSame('invalid_response', $exception->category);
        }
        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
    }

    public function test_api_requests_keep_a_fixed_https_host_and_do_not_follow_external_redirects(): void
    {
        Http::fake(fn () => Http::response('', 302, ['Location' => 'https://attacker.example/collect']));

        try {
            app(CloudflareApiClient::class)->records('../@attacker.example');
            $this->fail('A redirect response must be treated as an API error.');
        } catch (CloudflareDnsException $exception) {
            $this->assertSame('invalid_response', $exception->category);
        }

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => parse_url($request->url(), PHP_URL_SCHEME) === 'https'
            && parse_url($request->url(), PHP_URL_HOST) === 'api.cloudflare.com'
            && $request->hasHeader('Authorization', 'Bearer test-token-never-real'));
    }

    public function test_provider_errors_are_sanitized_and_mutations_are_not_retried(): void
    {
        $status = 404;
        Http::fake(function () use (&$status) {
            return Http::response(['success' => false, 'errors' => [['message' => 'test-token-never-real should not escape']], 'status' => $status], $status);
        });
        $client = app(CloudflareApiClient::class);

        foreach ([
            [404, fn () => $client->records(self::ZONE_ID), 'zone_not_found'],
            [409, fn () => $client->updateRecord(self::ZONE_ID, self::RECORD_ID, ['type' => 'A']), 'record_conflict'],
            [500, fn () => $client->updateRecord(self::ZONE_ID, self::RECORD_ID, ['type' => 'A']), 'unavailable'],
        ] as [$responseStatus, $operation, $category]) {
            $status = $responseStatus;
            try {
                $operation();
                $this->fail('Provider error should be mapped to a safe category.');
            } catch (CloudflareDnsException $exception) {
                $this->assertSame($category, $exception->category);
                $this->assertStringNotContainsString('test-token-never-real', $exception->getMessage());
            }
        }

        Http::assertSentCount(3);
    }

    public function test_connection_failure_and_malformed_or_missing_payloads_fail_closed(): void
    {
        $mode = 'connection';
        Http::fake(function () use (&$mode) {
            return match ($mode) {
                'connection' => throw new ConnectionException('synthetic transport detail'),
                'malformed' => Http::response('{ malformed', 200),
                default => Http::response(['success' => true], 200),
            };
        });
        try {
            app(CloudflareApiClient::class)->verifyToken();
            $this->fail('Connection failure should be sanitized.');
        } catch (CloudflareDnsException $exception) {
            $this->assertSame('unavailable', $exception->category);
            $this->assertStringNotContainsString('synthetic transport detail', $exception->getMessage());
        }

        $mode = 'malformed';
        try {
            app(CloudflareApiClient::class)->records(self::ZONE_ID);
            $this->fail('Malformed JSON should fail closed.');
        } catch (CloudflareDnsException $exception) {
            $this->assertSame('invalid_response', $exception->category);
        }

        $mode = 'missing';
        try {
            app(CloudflareApiClient::class)->records(self::ZONE_ID);
            $this->fail('A missing result must fail closed.');
        } catch (CloudflareDnsException $exception) {
            $this->assertSame('invalid_response', $exception->category);
        }
    }

    public function test_malformed_cloudflare_record_payload_is_rejected_without_exposing_provider_data(): void
    {
        Http::fake(function (Request $request) {
            return (str_contains($request->url(), '/zones') && ! str_contains($request->url(), '/dns_records'))
                ? Http::response(['success' => true, 'result' => [['id' => self::ZONE_ID, 'name' => 'wpcaregrid.com']], 'result_info' => ['total_pages' => 1]])
                : Http::response(['success' => true, 'result' => [['unexpected' => 'provider response']], 'result_info' => ['total_pages' => 1]]);
        });

        try {
            app(CloudflareDnsService::class)->listRecords('wpcaregrid.com');
            $this->fail('Malformed record payload should fail safely.');
        } catch (CloudflareDnsException $exception) {
            $this->assertSame('invalid_response', $exception->category);
            $this->assertStringNotContainsString('provider response', $exception->getMessage());
        }
    }

    public function test_protected_delete_is_blocked_and_allowed_delete_uses_exact_zone_record(): void
    {
        config(['cloudflare.protected_record_names' => []]);
        $record = ['id' => self::RECORD_ID, 'type' => 'TXT', 'name' => 'test.wpcaregrid.com', 'content' => 'proof', 'ttl' => 1, 'proxied' => false];
        Http::fake(function (Request $request) use ($record) {
            if (str_contains($request->url(), '/zones') && ! str_contains($request->url(), '/dns_records')) {
                return Http::response(['success' => true, 'result' => [['id' => self::ZONE_ID, 'name' => 'wpcaregrid.com']], 'result_info' => ['total_pages' => 1]]);
            }

            return Http::response(['success' => true, 'result' => [$record, [...$record, 'id' => 'bbbbbb0123456789abcdef0123456789', 'name' => 'wpcaregrid.com']], 'result_info' => ['total_pages' => 1]]);
        });
        $service = app(CloudflareDnsService::class);
        try {
            $service->planDelete('wpcaregrid.com', 'bbbbbb0123456789abcdef0123456789');
            $this->fail('Protected record should not be deletable.');
        } catch (CloudflareDnsException $exception) {
            $this->assertSame('record_protected', $exception->category);
        }
        $plan = $service->planDelete('wpcaregrid.com', self::RECORD_ID);
        $this->assertSame('deleted', $plan['action']);
    }

    public function test_delete_is_applied_only_for_the_replanned_record_in_the_approved_zone(): void
    {
        $record = ['id' => self::RECORD_ID, 'type' => 'TXT', 'name' => 'test.wpcaregrid.com', 'content' => 'verification', 'ttl' => 1, 'proxied' => false];
        Http::fake(function (Request $request) use ($record) {
            if (str_contains($request->url(), '/zones') && ! str_contains($request->url(), '/dns_records')) {
                return Http::response(['success' => true, 'result' => [['id' => self::ZONE_ID, 'name' => 'wpcaregrid.com']], 'result_info' => ['total_pages' => 1]]);
            }
            if (str_contains($request->url(), '/dns_records/') && $request->method() === 'DELETE') {
                return Http::response(['success' => true, 'result' => ['id' => self::RECORD_ID]]);
            }

            return Http::response(['success' => true, 'result' => [$record], 'result_info' => ['total_pages' => 1]]);
        });

        $service = app(CloudflareDnsService::class);
        $plan = $service->planDelete('wpcaregrid.com', self::RECORD_ID);
        $result = $service->applyDelete('wpcaregrid.com', self::RECORD_ID, $plan);

        $this->assertSame('deleted', $result['action']);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/dns_records/'.self::RECORD_ID));
    }

    public function test_cloudflare_authentication_and_permission_failures_are_safely_classified(): void
    {
        $status = 401;
        Http::fake(function () use (&$status) {
            return Http::response(['success' => false], $status);
        });

        foreach ([401 => 'authentication', 403 => 'permission', 429 => 'rate_limited', 503 => 'unavailable'] as $statusCode => $category) {
            $status = $statusCode;
            try {
                app(CloudflareDnsService::class)->diagnose();
                $this->fail('Cloudflare API failure should throw.');
            } catch (CloudflareDnsException $exception) {
                $this->assertSame($category, $exception->category);
                $this->assertStringNotContainsString('test-token-never-real', $exception->getMessage());
            }
        }
    }
}
