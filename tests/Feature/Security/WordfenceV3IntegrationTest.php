<?php

namespace Tests\Feature\Security;

use App\Security\Vulnerabilities\AtomicIndexPublisher;
use App\Security\Vulnerabilities\WordfenceFeed;
use App\Security\Vulnerabilities\WordfenceFeedAddressResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WordfenceV3IntegrationTest extends TestCase
{
    private string $directory;

    private string $feedPath;

    private string $indexPath;

    private string $endpoint = 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/scanner';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/wordfence-v3-'.bin2hex(random_bytes(6)));
        $this->feedPath = $this->directory.'/feed.json';
        $this->indexPath = WordfenceFeed::indexPathFor($this->feedPath);
        config([
            'security.vulnerabilities.source' => 'wordfence',
            'security.vulnerabilities.feed_path' => $this->feedPath,
            'security.vulnerabilities.feed_url' => $this->endpoint,
            'security.vulnerabilities.feed_key' => 'synthetic-api-key',
            'security.vulnerabilities.max_bytes' => 160 * 1024 * 1024,
            'security.vulnerabilities.max_index_bytes' => 128 * 1024 * 1024,
        ]);
        app()->instance(WordfenceFeedAddressResolver::class, new class extends WordfenceFeedAddressResolver
        {
            public function resolve(string $host): array
            {
                return ['8.8.8.8'];
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function record(string $id = '12345678-1234-4234-8234-123456789abc'): array
    {
        return [
            'id' => $id,
            'title' => 'Synthetic record {with braces} and "quoted" text',
            'software' => [[
                'type' => 'plugin', 'name' => 'Fixture Plugin', 'slug' => 'fixture-plugin',
                'affected_versions' => ['[1.0,2.0)' => ['from_version' => '1.0', 'from_inclusive' => true, 'to_version' => '2.0', 'to_inclusive' => false]],
                'patched' => true, 'patched_versions' => ['2.0'],
            ]],
            'informational' => false,
            'references' => ['https://www.wordfence.com/threat-intel/vulnerabilities/fixture-plugin-issue'],
            'published' => '2026-01-09 00:00:00',
            'copyrights' => ['defiant' => [
                'notice' => 'Copyright 2012-2026 Defiant Inc.',
                'license' => 'Synthetic license text.',
                'license_url' => 'https://www.wordfence.com/wti-community-edition-terms-and-conditions/',
            ]],
        ];
    }

    private function importJson(string $json): int
    {
        File::ensureDirectoryExists($this->directory);
        $input = $this->directory.'/input.json';
        file_put_contents($input, $json);

        return Artisan::call('security:vulnerabilities:sync', ['--file' => $input]);
    }

    private function fixtureJson(?array $record = null): string
    {
        $record ??= $this->record();

        return " \n".json_encode([$record['id'] => $record], JSON_THROW_ON_ERROR)." \n";
    }

    public function test_valid_v3_record_streaming_range_matching_and_attribution(): void
    {
        $this->assertSame(0, $this->importJson($this->fixtureJson()));
        $source = WordfenceFeed::fromConfig();
        $this->assertTrue($source->available());
        $this->assertCount(1, $source->affecting('plugin', 'fixture-plugin', '1.9.9'));
        $this->assertCount(0, $source->affecting('plugin', 'fixture-plugin', '2.0'));
        $this->assertNull($source->affecting('plugin', 'fixture-plugin', '1.9.9')[0]->cvssScore);
        $this->assertNull($source->affecting('plugin', 'fixture-plugin', '1.9.9')[0]->cve);
        $this->assertSame('https://www.wordfence.com/threat-intel/vulnerabilities/fixture-plugin-issue', $source->affecting('plugin', 'fixture-plugin', '1.9.9')[0]->reference);
    }

    public function test_empty_incompatible_and_oversized_records_fail_without_replacing_last_good_index(): void
    {
        $this->assertSame(0, $this->importJson($this->fixtureJson()));
        $knownGood = file_get_contents($this->indexPath);
        $bad = $this->record();
        unset($bad['informational']);
        $oversized = $this->record();
        $oversized['title'] = str_repeat('x', 2 * 1024 * 1024 + 1);

        foreach (['{}', '[]', 'null', '{"broken":', $this->fixtureJson($bad), $this->fixtureJson($oversized)] as $input) {
            $this->assertSame(1, $this->importJson($input));
            $this->assertSame($knownGood, file_get_contents($this->indexPath));
        }
        $manifest = json_decode($knownGood, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('wordfence-intelligence-v3-scanner', $manifest['format']);
        $this->assertNotEmpty($manifest['generation']);
    }

    public function test_download_is_pinned_bounded_and_uses_bearer_auth_without_following_redirects(): void
    {
        $valid = $this->fixtureJson();
        Http::fake([$this->endpoint => Http::response($valid, 200, ['Content-Type' => 'application/json; charset=utf-8'])]);
        $this->assertSame(0, Artisan::call('security:vulnerabilities:sync'));
        Http::assertSent(fn ($request) => $request->url() === $this->endpoint
            && $request->hasHeader('Authorization', 'Bearer synthetic-api-key'));
    }

    public function test_redirect_rate_limit_bad_type_and_interruption_preserve_previous_generation_and_do_not_log_secrets(): void
    {
        $this->assertSame(0, $this->importJson($this->fixtureJson()));
        $knownGood = file_get_contents($this->indexPath);
        $responses = [
            Http::response('', 302, ['Location' => 'https://127.0.0.1/']),
            Http::response('rate limited', 429, ['Content-Type' => 'application/json']),
            Http::response('bad authentication', 401, ['Content-Type' => 'application/json']),
            Http::response('<html/>', 200, ['Content-Type' => 'text/html']),
            Http::response('not-json', 200, ['Content-Type' => 'application/json']),
            Http::failedConnection('synthetic interruption'),
        ];
        foreach ($responses as $response) {
            Http::fake([$this->endpoint => $response]);
            $this->assertSame(1, Artisan::call('security:vulnerabilities:sync'));
            $this->assertSame($knownGood, file_get_contents($this->indexPath));
            $this->assertStringNotContainsString('synthetic-api-key', Artisan::output());
        }

        config(['security.vulnerabilities.max_bytes' => 64]);
        Http::fake([$this->endpoint => Http::response(str_repeat('x', 65), 200, ['Content-Type' => 'application/json'])]);
        $this->assertSame(1, Artisan::call('security:vulnerabilities:sync'));
        $this->assertSame($knownGood, file_get_contents($this->indexPath));
    }

    public function test_no_key_or_non_official_host_fails_before_any_request(): void
    {
        Http::fake();
        config(['security.vulnerabilities.feed_key' => null]);
        $this->assertSame(1, Artisan::call('security:vulnerabilities:sync'));
        config(['security.vulnerabilities.feed_key' => 'synthetic-api-key', 'security.vulnerabilities.feed_url' => 'https://not-wordfence.example/api/intelligence/v3/vulnerabilities/scanner']);
        $this->assertSame(1, Artisan::call('security:vulnerabilities:sync'));
        Http::assertNothingSent();
    }

    public function test_http_synchronization_lock_rejects_a_second_concurrent_refresh(): void
    {
        Http::fake();
        $lock = Cache::lock('security:vulnerabilities:sync', 3600);
        $this->assertTrue($lock->get());
        try {
            $this->assertSame(1, Artisan::call('security:vulnerabilities:sync'));
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_publisher_failure_preserves_the_active_manifest(): void
    {
        $this->assertSame(0, $this->importJson($this->fixtureJson()));
        $knownGood = file_get_contents($this->indexPath);
        app()->instance(AtomicIndexPublisher::class, new class extends AtomicIndexPublisher
        {
            public function publish(string $stagedIndex, string $activeIndex): void
            {
                throw new \RuntimeException('synthetic pre-rename failure');
            }
        });
        $next = $this->record('22345678-1234-4234-8234-123456789abc');
        $this->assertSame(1, $this->importJson($this->fixtureJson($next)));
        $this->assertSame($knownGood, file_get_contents($this->indexPath));
        $this->assertTrue(WordfenceFeed::fromConfig()->available());
    }

    public function test_older_refresh_generation_cannot_replace_a_newer_manifest(): void
    {
        $this->assertSame(0, $this->importJson($this->fixtureJson()));
        $knownGood = file_get_contents($this->indexPath);
        $olderFeed = $this->directory.'/older.json';
        file_put_contents($olderFeed, $this->fixtureJson($this->record('22345678-1234-4234-8234-123456789abc')));

        try {
            WordfenceFeed::import($olderFeed, $this->indexPath, null, null, 1);
            $this->fail('An older refresh must not replace a newer generation.');
        } catch (\RuntimeException) {
            $this->assertSame($knownGood, file_get_contents($this->indexPath));
        }
    }

    public function test_a_missing_group_file_invalidates_the_feed_instead_of_returning_no_matches(): void
    {
        $this->assertSame(0, $this->importJson($this->fixtureJson()));
        $manifest = json_decode(file_get_contents($this->indexPath), true, 8, JSON_THROW_ON_ERROR);
        $group = WordfenceFeed::dataRootFor($this->indexPath).'/'.$manifest['generation'].'/plugin/fixture-plugin.jsonl';
        File::delete($group);
        $source = WordfenceFeed::fromConfig();
        $this->assertFalse($source->available());
        $this->assertSame('VULNERABILITY_FEED_INVALID', $source->unavailableReason());
    }

    public function test_private_or_reserved_dns_answers_never_become_connection_targets(): void
    {
        $resolver = new class extends WordfenceFeedAddressResolver
        {
            protected function lookup(string $host): array
            {
                return [['ip' => '127.0.0.1'], ['ip' => '10.0.0.4'], ['ipv6' => '::1']];
            }
        };
        try {
            $resolver->resolve('www.wordfence.com');
            $this->fail('A private-only DNS result must be rejected.');
        } catch (\RuntimeException) {
            $this->assertTrue(true);
        }
    }
}
