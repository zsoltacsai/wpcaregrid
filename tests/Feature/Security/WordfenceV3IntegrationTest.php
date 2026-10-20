<?php

namespace Tests\Feature\Security;

use App\Console\Commands\Security\SyncVulnerabilities;
use App\Security\Vulnerabilities\AtomicIndexPublisher;
use App\Security\Vulnerabilities\WordfenceFeed;
use App\Security\Vulnerabilities\WordfenceFeedAddressResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
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
        Cache::forget('security:vulnerabilities:wordfence:cooldown_until');
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
            'references' => [
                'https://www.cve.org/CVERecord?id=CVE-2026-0001',
                'http://www.wordfence.com/threat-intel/vulnerabilities/fixture-plugin-issue',
            ],
            'published' => '2026-01-09 00:00:00',
            'copyrights' => ['defiant' => [
                'notice' => 'Copyright 2012-2026 Defiant Inc.',
                'license' => 'Synthetic license text.',
                'license_url' => 'https://www.wordfence.com/wti-community-edition-terms-and-conditions/',
            ], 'mitre' => [
                'notice' => 'Copyright 1999-2026 The MITRE Corporation',
                'license' => 'Synthetic MITRE license text.',
                'license_url' => 'https://www.cve.org/Legal/TermsOfUse',
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
        $matches = $source->affecting('plugin', 'fixture-plugin', '1.9.9');
        $this->assertCount(1, $matches);
        $this->assertCount(0, $source->affecting('plugin', 'fixture-plugin', '2.0'));
        $this->assertCount(0, $source->affecting('plugin', 'not-in-feed', '1.0'));
        $this->assertTrue($source->available(), 'An inventory slug absent from the feed is a valid no-match, not index corruption.');
        $this->assertNull($source->affecting('plugin', 'fixture-plugin', '1.9.9')[0]->cvssScore);
        $this->assertNull($source->affecting('plugin', 'fixture-plugin', '1.9.9')[0]->cve);
        $this->assertSame('https://www.wordfence.com/threat-intel/vulnerabilities/fixture-plugin-issue', $matches[0]->reference);
        $this->assertSame('Copyright 2012-2026 Defiant Inc.', $matches[0]->copyrightNotice);
        $this->assertSame('Copyright 1999-2026 The MITRE Corporation', $matches[0]->mitreNotice);
    }

    public function test_official_schema_fixture_accepts_numeric_object_keys_nulls_and_string_versions(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/wordfence-v3-scanner-numeric-versions.json'));
        $this->assertNotFalse($fixture);
        $this->assertSame(0, $this->importJson($fixture));

        $source = WordfenceFeed::fromConfig();
        $this->assertTrue($source->available());
        $this->assertCount(1, $source->affecting('plugin', 'numeric-version-fixture', '0'));
        $this->assertCount(1, $source->affecting('plugin', 'numeric-version-fixture', 'v1.5'));
        $this->assertSame(
            'https://www.wordfence.com/threat-intel/vulnerabilities/synthetic-schema-fixture',
            $source->affecting('plugin', 'numeric-version-fixture', '0')[0]->reference,
        );
        $this->assertNull($source->affecting('plugin', 'numeric-version-fixture', '0')[0]->copyrightNotice);
    }

    public function test_schema_subcode_is_safe_and_failed_import_keeps_the_last_good_index(): void
    {
        $this->assertSame(0, $this->importJson($this->fixtureJson()));
        $knownGood = file_get_contents($this->indexPath);
        $fixture = file_get_contents(base_path('tests/Fixtures/wordfence-v3-invalid-range.json'));
        $this->assertNotFalse($fixture);
        Log::spy();

        $this->assertSame(1, $this->importJson($fixture));
        $this->assertSame($knownGood, file_get_contents($this->indexPath));
        Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message, array $context): bool => $message === 'Wordfence vulnerability feed synchronization failed.'
            && $context === [
                'failure_class' => 'feed_schema_invalid',
                'failure_subcode' => 'software.affected_version_range_fields',
            ]
        );
        $this->assertStringNotContainsString('Synthetic invalid range fixture', Artisan::output());
    }

    public function test_version_tokens_with_spaces_prefixes_suffixes_and_prereleases_use_wordpress_comparison_semantics(): void
    {
        $record = $this->record();
        $record['software'][0]['affected_versions'] = [
            'beta prerelease interval' => [
                'from_version' => '1.0-beta.1', 'from_inclusive' => true,
                'to_version' => '1.0-rc.1', 'to_inclusive' => false,
            ],
            'prefixed release interval' => [
                'from_version' => 'v1.0', 'from_inclusive' => true,
                'to_version' => 'v2.0', 'to_inclusive' => true,
            ],
            'spaced vendor build interval' => [
                'from_version' => 'Version 1.0 build 1', 'from_inclusive' => true,
                'to_version' => 'Version 1.0 build 3', 'to_inclusive' => true,
            ],
        ];
        $record['software'][0]['patched_versions'] = ['v2.0 stable'];

        $this->assertSame(0, $this->importJson($this->fixtureJson($record)));
        $source = WordfenceFeed::fromConfig();
        $this->assertCount(1, $source->affecting('plugin', 'fixture-plugin', '1.0-beta.2'));
        $this->assertCount(0, $source->affecting('plugin', 'fixture-plugin', '1.0'));
        $this->assertCount(1, $source->affecting('plugin', 'fixture-plugin', 'v1.5'));
        $this->assertCount(0, $source->affecting('plugin', 'fixture-plugin', 'v2.1'));
        $this->assertCount(1, $source->affecting('plugin', 'fixture-plugin', 'Version 1.0 build 2'));

        $this->assertTrue(WordfenceFeed::inRange('1.0-beta.2', [
            'from' => '1.0-beta.1', 'from_inclusive' => true,
            'to' => '1.0-rc.1', 'to_inclusive' => false,
        ]));
        $this->assertFalse(WordfenceFeed::inRange('1.0', [
            'from' => '1.0-beta.1', 'from_inclusive' => true,
            'to' => '1.0-rc.1', 'to_inclusive' => false,
        ]));
    }

    public function test_control_character_and_unbounded_version_tokens_fail_safely_without_replacing_the_index(): void
    {
        $this->assertSame(0, $this->importJson($this->fixtureJson()));
        $knownGood = file_get_contents($this->indexPath);
        Log::spy();

        foreach (["1.0\u{0001}beta", str_repeat('v', 129)] as $version) {
            $record = $this->record();
            $record['software'][0]['affected_versions'] = [
                'synthetic range' => [
                    'from_version' => $version, 'from_inclusive' => true,
                    'to_version' => '2.0', 'to_inclusive' => true,
                ],
            ];

            $this->assertSame(1, $this->importJson($this->fixtureJson($record)));
            $this->assertSame($knownGood, file_get_contents($this->indexPath));
            $this->assertStringNotContainsString($version, Artisan::output());
        }

        Log::shouldHaveReceived('warning')->twice()->withArgs(fn (string $message, array $context): bool => $message === 'Wordfence vulnerability feed synchronization failed.'
            && $context === ['failure_class' => 'feed_schema_invalid', 'failure_subcode' => 'software.version_token']
        );
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

    public function test_real_guzzle_aborts_an_oversized_transfer_and_discards_staging_without_replacing_the_index(): void
    {
        $this->assertSame(0, $this->importJson($this->fixtureJson()));
        $knownGoodIndex = file_get_contents($this->indexPath);
        $filesBefore = $this->relativeFiles($this->directory);

        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertIsResource($listener, $errorMessage);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);

        $tempDirectory = sys_get_temp_dir();
        $router = tempnam($tempDirectory, 'wpcg-large-router-');
        $this->assertNotFalse($router);
        $this->assertTrue(copy(base_path('tests/Fixtures/wordfence-http-router.php'), $router));
        $process = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", basename($router)], $tempDirectory);
        $process->start();

        try {
            $deadline = microtime(true) + 5;
            do {
                $connection = @stream_socket_client("tcp://127.0.0.1:{$port}", $errorCode, $errorMessage, 0.1);
                if (is_resource($connection)) {
                    fclose($connection);
                    break;
                }
                usleep(20_000);
            } while (! $process->isTerminated() && microtime(true) < $deadline);
            $this->assertFalse($process->isTerminated(), 'The local oversized-response server failed to start.');

            $method = new \ReflectionMethod(SyncVulnerabilities::class, 'stageWordfenceFeed');
            $failure = null;
            try {
                $method->invoke(
                    new SyncVulnerabilities,
                    "http://www.wordfence.com:{$port}/oversized",
                    'synthetic-test-token',
                    ["www.wordfence.com:{$port}:127.0.0.1"],
                    $this->directory,
                    1024,
                );
            } catch (\Throwable $exception) {
                $failure = $exception;
            }

            $this->assertNotNull($failure, 'The oversized transfer must fail before import.');
            $failureMessages = '';
            for ($current = $failure; $current !== null; $current = $current->getPrevious()) {
                $failureMessages .= ' '.$current->getMessage();
            }
            $this->assertStringContainsString('aborted by the progress callback', strtolower($failureMessages));
            $this->assertSame($knownGoodIndex, file_get_contents($this->indexPath));
            $this->assertSame($filesBefore, $this->relativeFiles($this->directory), 'The failed staging download must leave no temporary file.');
        } finally {
            $process->stop();
            @unlink($router);
        }
    }

    /** @return list<string> */
    private function relativeFiles(string $directory): array
    {
        $files = array_map(static fn ($file): string => $file->getRelativePathname(), File::allFiles($directory));
        sort($files);

        return $files;
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
            Cache::forget('security:vulnerabilities:wordfence:cooldown_until');
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

    public function test_rate_limit_persists_a_manual_sync_cooldown_and_logs_only_a_safe_classification(): void
    {
        $this->assertSame(0, $this->importJson($this->fixtureJson()));
        $knownGood = file_get_contents($this->indexPath);
        Log::spy();
        Http::fake([$this->endpoint => Http::response(
            'provider body containing synthetic-api-key and Bearer synthetic-api-key',
            429,
            ['Retry-After' => '600', 'Content-Type' => 'application/json'],
        )]);

        $this->assertSame(1, Artisan::call('security:vulnerabilities:sync'));
        $cooldownUntil = Cache::get('security:vulnerabilities:wordfence:cooldown_until');
        $this->assertGreaterThanOrEqual(now()->getTimestamp() + 1799, $cooldownUntil);
        $this->assertSame($knownGood, file_get_contents($this->indexPath));
        $this->assertStringNotContainsString('synthetic-api-key', Artisan::output());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Wordfence vulnerability feed synchronization failed.'
            && $context === ['failure_class' => 'rate_limited']
        )->once();

        $this->assertSame(1, Artisan::call('security:vulnerabilities:sync'));
        Http::assertSentCount(1);
        $this->assertSame($knownGood, file_get_contents($this->indexPath));
        $this->assertStringNotContainsString('synthetic-api-key', Artisan::output());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Wordfence vulnerability feed synchronization deferred.'
            && ($context['failure_class'] ?? null) === 'rate_limit_cooldown_active'
            && array_keys($context) === ['failure_class', 'retry_after_seconds']
        )->once();
    }

    public function test_feed_json_and_schema_failures_have_safe_classes_and_preserve_the_active_index(): void
    {
        $this->assertSame(0, $this->importJson($this->fixtureJson()));
        $knownGood = file_get_contents($this->indexPath);
        Log::spy();

        $this->assertSame(1, $this->importJson('{"broken":'));
        $this->assertSame($knownGood, file_get_contents($this->indexPath));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Wordfence vulnerability feed synchronization failed.'
            && $context === ['failure_class' => 'feed_json_invalid']
        )->once();

        $invalid = $this->record();
        unset($invalid['informational']);
        $this->assertSame(1, $this->importJson($this->fixtureJson($invalid)));
        $this->assertSame($knownGood, file_get_contents($this->indexPath));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Wordfence vulnerability feed synchronization failed.'
            && $context === [
                'failure_class' => 'feed_schema_invalid',
                'failure_subcode' => 'record.informational_type',
            ]
        )->once();
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
