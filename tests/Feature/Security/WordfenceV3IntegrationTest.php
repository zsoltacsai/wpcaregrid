<?php

namespace Tests\Feature\Security;

use App\Console\Commands\Security\SyncVulnerabilities;
use App\Models\Website;
use App\Models\WebsiteInventory;
use App\Security\Vulnerabilities\AtomicIndexPublisher;
use App\Security\Vulnerabilities\VulnerabilityScanner;
use App\Security\Vulnerabilities\WordfenceFeed;
use App\Security\Vulnerabilities\WordfenceIndexAccess;
use App\Security\Vulnerabilities\WordfenceFeedAddressResolver;
use App\Security\Vulnerabilities\WordfenceFeedProfiler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WordfenceV3IntegrationTest extends TestCase
{
    private static ?string $unrelatedSyntheticReport = null;

    private static ?string $unrelatedSyntheticDirectory = null;

    private string $directory;

    private string $feedPath;

    private string $indexPath;

    private string $profileStoragePath;

    private string $endpoint = 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/scanner';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/wordfence-v3-'.bin2hex(random_bytes(6)));
        $this->profileStoragePath = $this->directory.'/private';
        File::ensureDirectoryExists($this->profileStoragePath);
        app()->instance(WordfenceFeedProfiler::class, new class($this->profileStoragePath) extends WordfenceFeedProfiler
        {
            public function __construct(private string $privateStoragePath) {}

            protected function privateStorageDirectory(): string
            {
                return $this->privateStoragePath;
            }
        });
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
        // This directory is unique to this test. Never sweep the shared production report directory.
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        try {
            if (self::$unrelatedSyntheticReport !== null) {
                self::assertFileExists(self::$unrelatedSyntheticReport, 'Test cleanup must leave an unrelated synthetic report untouched.');
                self::assertSame('another-process-report', file_get_contents(self::$unrelatedSyntheticReport));
            }
        } finally {
            if (self::$unrelatedSyntheticDirectory !== null && is_dir(self::$unrelatedSyntheticDirectory) && ! is_link(self::$unrelatedSyntheticDirectory)) {
                File::deleteDirectory(self::$unrelatedSyntheticDirectory);
            }
            parent::tearDownAfterClass();
        }
    }

    private function authorizeSyntheticStagingContext(): void
    {
        config([
            'app.deployment_environment' => 'staging',
            'app.deployment_hostname' => 'stg1.wpcaregrid.com',
            'app.url' => 'https://staging.wpcaregrid.com',
        ]);
    }

    /** @return list<string> */
    private function newProfileFiles(): array
    {
        return glob($this->profileStoragePath.'/wordfence-profiles/*') ?: [];
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

    public function test_safe_provider_core_slug_is_canonicalized_and_unsafe_slug_preserves_last_good_index(): void
    {
        $record = $this->record();
        $record['software'] = [[
            'type' => 'core',
            'name' => 'WordPress',
            'slug' => 'wordpress-core',
            'affected_versions' => ['6.8 - 6.9' => [
                'from_version' => '6.8', 'from_inclusive' => true,
                'to_version' => '6.9', 'to_inclusive' => true,
            ]],
            'patched' => true,
            'patched_versions' => ['6.9.1'],
        ]];

        $this->assertSame(0, $this->importJson($this->fixtureJson($record)));
        $source = WordfenceFeed::fromConfig();
        $this->assertTrue($source->available());
        $this->assertCount(1, $source->affecting('core', 'wordpress', '6.8.2'));
        $manifest = json_decode((string) file_get_contents($this->indexPath), true, 8, JSON_THROW_ON_ERROR);
        $this->assertFileExists(WordfenceFeed::dataRootFor($this->indexPath).'/'.$manifest['generation'].'/core/wordpress.jsonl');

        $knownGood = file_get_contents($this->indexPath);
        Log::spy();
        $record['software'][0]['slug'] = "bad\nslug";

        $this->assertSame(1, $this->importJson($this->fixtureJson($record)));
        $this->assertSame($knownGood, file_get_contents($this->indexPath));
        $this->assertStringNotContainsString("bad\nslug", Artisan::output());
        Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message, array $context): bool => $message === 'Wordfence vulnerability feed synchronization failed.'
            && $context === ['failure_class' => 'feed_schema_invalid', 'failure_subcode' => 'software.slug_format']
        );
    }

    public function test_bounded_provider_identifier_is_indexed_without_becoming_a_filesystem_path(): void
    {
        $providerSlug = '../vendor/plugin.v2 beta';
        $record = $this->record();
        $record['software'][0]['slug'] = $providerSlug;

        $this->assertSame(0, $this->importJson($this->fixtureJson($record)));
        $source = WordfenceFeed::fromConfig();
        $this->assertTrue($source->available());
        $this->assertCount(1, $source->affecting('plugin', $providerSlug, '1.9.9'));

        $manifest = json_decode((string) file_get_contents($this->indexPath), true, 8, JSON_THROW_ON_ERROR);
        $generation = WordfenceFeed::dataRootFor($this->indexPath).'/'.$manifest['generation'];
        $expectedFile = $generation.'/plugin/wfi-'.hash('sha256', $providerSlug).'.jsonl';
        $this->assertFileExists($expectedFile);
        $this->assertSame(realpath($generation.'/plugin'), realpath(dirname($expectedFile)));
        $this->assertFileDoesNotExist($generation.'/vendor/plugin.v2 beta.jsonl');
    }

    public function test_opaque_filename_collision_fails_without_replacing_last_good_index(): void
    {
        $this->assertSame(0, $this->importJson($this->fixtureJson()));
        $knownGood = file_get_contents($this->indexPath);
        $record = $this->record();
        $opaqueSlug = '/opaque-plugin';
        $record['software'][0]['slug'] = $opaqueSlug;
        $canonicalCollision = 'wfi-'.hash('sha256', $opaqueSlug);
        $record['software'][] = array_replace($record['software'][0], ['slug' => $canonicalCollision]);

        Log::spy();
        $this->assertSame(1, $this->importJson($this->fixtureJson($record)));
        $this->assertSame($knownGood, file_get_contents($this->indexPath));
        Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message, array $context): bool => $message === 'Wordfence vulnerability feed synchronization failed.'
            && $context === ['failure_class' => 'feed_schema_invalid', 'failure_subcode' => 'software.slug_collision']
        );
    }

    public function test_canonical_direct_slug_index_layout_remains_compatible(): void
    {
        $this->assertSame(0, $this->importJson($this->fixtureJson()));
        $manifest = json_decode((string) file_get_contents($this->indexPath), true, 8, JSON_THROW_ON_ERROR);
        $directory = WordfenceFeed::dataRootFor($this->indexPath).'/'.$manifest['generation'].'/plugin';
        $this->assertFileExists($directory.'/fixture-plugin.jsonl');

        $source = WordfenceFeed::fromConfig();
        $this->assertTrue($source->available());
        $this->assertCount(1, $source->affecting('plugin', 'fixture-plugin', '1.9.9'));
    }

    public function test_profiling_is_off_by_default_and_ordinary_sync_has_no_profile_side_effect(): void
    {
        Http::fake([$this->endpoint => Http::response($this->fixtureJson(), 200, ['Content-Type' => 'application/json'])]);

        $this->assertSame(0, Artisan::call('security:vulnerabilities:sync'));
        Http::assertSentCount(1);
        $this->assertSame([], $this->newProfileFiles());
        $this->assertSame([], glob($this->directory.'/wpcg-feed-*') ?: []);
    }

    public function test_non_staging_profile_request_is_rejected_before_any_http_request(): void
    {
        Http::fake();
        Log::spy();

        $this->assertSame(1, Artisan::call('security:vulnerabilities:sync', ['--profile-feed' => true]));
        Http::assertNothingSent();
        $this->assertSame([], $this->newProfileFiles());
        Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message, array $context): bool => $message === 'Wordfence feed profiling was refused.'
            && $context === ['failure_class' => 'feed_profile_context_rejected']
        );
    }

    public function test_one_shot_staging_profile_uses_only_the_local_staged_feed_and_keeps_import_success(): void
    {
        $this->authorizeSyntheticStagingContext();
        Http::fake([$this->endpoint => Http::response($this->fixtureJson(), 200, ['Content-Type' => 'application/json'])]);

        $this->assertSame(0, Artisan::call('security:vulnerabilities:sync', ['--profile-feed' => true]));
        Http::assertSentCount(1);
        $reports = $this->newProfileFiles();
        $this->assertCount(1, $reports);
        $this->assertStringStartsWith($this->profileStoragePath.'/wordfence-profiles/', $reports[0]);
        $contents = file_get_contents($reports[0]);
        $this->assertIsString($contents);
        $this->assertStringContainsString('Top-level vulnerability records: 1', $contents);
        $this->assertStringNotContainsString('12345678-1234-4234-8234-123456789abc', $contents);
        $this->assertStringNotContainsString('Synthetic record', $contents);
        $this->assertStringNotContainsString('1.0', $contents);
        $this->assertStringNotContainsString('synthetic-api-key', $contents);
        $this->assertSame([], glob($this->directory.'/wpcg-feed-*') ?: []);
        $this->assertTrue(WordfenceFeed::fromConfig()->available());

        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame(0700, fileperms(dirname($reports[0])) & 0777);
            $this->assertSame(0600, fileperms($reports[0]) & 0777);
        }
    }

    public function test_test_cleanup_preserves_an_unrelated_report_in_isolated_synthetic_storage(): void
    {
        $syntheticOtherProcessDirectory = storage_path('framework/testing/wordfence-unrelated-report-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($syntheticOtherProcessDirectory);
        $report = $syntheticOtherProcessDirectory.'/another-process-report.txt';
        $this->assertSame(strlen('another-process-report'), file_put_contents($report, 'another-process-report', LOCK_EX));
        self::$unrelatedSyntheticReport = $report;
        self::$unrelatedSyntheticDirectory = $syntheticOtherProcessDirectory;

        $this->authorizeSyntheticStagingContext();
        Http::fake([$this->endpoint => Http::response($this->fixtureJson(), 200, ['Content-Type' => 'application/json'])]);

        $this->assertSame(0, Artisan::call('security:vulnerabilities:sync', ['--profile-feed' => true]));
        $this->assertFileExists($report);
        $this->assertCount(1, $this->newProfileFiles());
    }

    public function test_profiling_failure_is_best_effort_and_does_not_change_import_or_cleanup(): void
    {
        $this->authorizeSyntheticStagingContext();
        Http::fake([$this->endpoint => Http::response($this->fixtureJson(), 200, ['Content-Type' => 'application/json'])]);
        app()->instance(WordfenceFeedProfiler::class, new class extends WordfenceFeedProfiler
        {
            public function profile(string $feedPath, int $maximumBytes): ?string
            {
                return 'process_timeout';
            }
        });
        Log::spy();

        $this->assertSame(0, Artisan::call('security:vulnerabilities:sync', ['--profile-feed' => true]));
        $this->assertTrue(WordfenceFeed::fromConfig()->available());
        $this->assertSame([], $this->newProfileFiles());
        $this->assertSame([], glob($this->directory.'/wpcg-feed-*') ?: []);
        Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message, array $context): bool => $message === 'Wordfence feed profiling failed.'
            && $context === ['failure_class' => 'feed_profile_failed', 'failure_subcode' => 'process_timeout']
        );
    }

    public function test_invalid_json_profile_failure_does_not_change_import_failure_or_leave_download(): void
    {
        $this->authorizeSyntheticStagingContext();
        $secretVersion = 'private-synthetic-version-token';
        Http::fake([$this->endpoint => Http::response('{"broken":"'.$secretVersion.'"', 200, ['Content-Type' => 'application/json'])]);
        Log::spy();

        $this->assertSame(1, Artisan::call('security:vulnerabilities:sync', ['--profile-feed' => true]));
        $this->assertSame([], $this->newProfileFiles());
        $this->assertSame([], glob($this->directory.'/wpcg-feed-*') ?: []);
        $this->assertStringNotContainsString($secretVersion, Artisan::output());
        Log::shouldHaveReceived('warning')->withArgs(static fn (string $message, array $context): bool => $message === 'Wordfence feed profiling failed.'
            && $context === ['failure_class' => 'feed_profile_failed', 'failure_subcode' => 'process_failed']
        )->once();
        Log::shouldHaveReceived('warning')->withArgs(static fn (string $message, array $context): bool => $message === 'Wordfence vulnerability feed synchronization failed.'
            && $context === ['failure_class' => 'feed_json_invalid']
        )->once();
    }

    public function test_malformed_record_profile_contains_only_aggregates_and_import_remains_fail_closed(): void
    {
        $this->authorizeSyntheticStagingContext();
        $id = '92345678-1234-4234-8234-123456789abc';
        $secretVersion = 'private-synthetic-version-token';
        $body = json_encode([$id => ['software' => $secretVersion]], JSON_THROW_ON_ERROR);
        Http::fake([$this->endpoint => Http::response($body, 200, ['Content-Type' => 'application/json'])]);

        $this->assertSame(1, Artisan::call('security:vulnerabilities:sync', ['--profile-feed' => true]));
        $reports = $this->newProfileFiles();
        $this->assertCount(1, $reports, 'Profiling records schema shape before the importer rejects the record.');
        $contents = file_get_contents($reports[0]);
        $this->assertIsString($contents);
        $this->assertStringNotContainsString($id, $contents);
        $this->assertStringNotContainsString($secretVersion, $contents);
        $this->assertSame([], glob($this->directory.'/wpcg-feed-*') ?: []);
    }

    public function test_profiler_enforces_input_output_and_time_limits(): void
    {
        $profileDir = $this->directory.'/profile-test';
        File::ensureDirectoryExists($profileDir);
        $input = $profileDir.'/input.json';
        file_put_contents($input, '{"a":1}');
        $script = $profileDir.'/fake-profiler.php';
        $profiler = new class($script, $this->profileStoragePath) extends WordfenceFeedProfiler
        {
            public function __construct(private string $script, private string $privateStoragePath) {}

            protected function profilerScriptPath(): string
            {
                return $this->script;
            }

            protected function privateStorageDirectory(): string
            {
                return $this->privateStoragePath;
            }
        };

        $this->assertSame('input_limit', $profiler->profile($input, 2));

        file_put_contents($script, '<?php echo str_repeat("x", 40000);');
        $this->assertSame('output_limit', $profiler->profile($input, 100));
        $this->assertSame([], $this->newProfileFiles());

        config(['security.vulnerabilities.profile_timeout_seconds' => 1]);
        file_put_contents($script, '<?php sleep(5); echo "finished";');
        $this->assertSame('process_timeout', $profiler->profile($input, 100));
        $this->assertSame([], $this->newProfileFiles());
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
            'label' => 'synthetic prerelease interval',
            'from' => '1.0-beta.1', 'from_inclusive' => true,
            'to' => '1.0-rc.1', 'to_inclusive' => false,
        ]));
        $this->assertFalse(WordfenceFeed::inRange('1.0', [
            'label' => 'synthetic prerelease interval',
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

    public function test_scanner_reports_supported_unusual_versions_and_keeps_invalid_or_wildcard_versions_partial(): void
    {
        $record = $this->record();
        $makeSoftware = static fn (string $slug, string $from, string $to): array => [
            'type' => 'plugin', 'name' => $slug, 'slug' => $slug,
            'affected_versions' => ['fixture range' => [
                'from_version' => $from, 'from_inclusive' => true,
                'to_version' => $to, 'to_inclusive' => true,
            ]],
            'patched' => false, 'patched_versions' => [],
        ];
        $record['software'] = [
            $makeSoftware('spaced-plugin', 'Version 1.0 build 1', 'Version 1.0 build 3'),
            $makeSoftware('prefixed-plugin', 'v1.0', 'v2.0'),
            $makeSoftware('prerelease-plugin', '1.0-beta.1', '1.0-rc.1'),
        ];

        $this->assertSame(0, $this->importJson($this->fixtureJson($record)));
        $website = new Website;
        $website->setRelation('inventory', new WebsiteInventory([
            'core' => ['version' => '6.9'],
            'plugins' => [
                ['slug' => 'spaced-plugin', 'name' => 'Spaced plugin', 'version' => 'Version 1.0 build 2'],
                ['slug' => 'prefixed-plugin', 'name' => 'Prefixed plugin', 'version' => 'v1.5'],
                ['slug' => 'prerelease-plugin', 'name' => 'Prerelease plugin', 'version' => '1.0-beta.2'],
                ['slug' => 'invalid-plugin', 'name' => 'Invalid plugin', 'version' => "1.0\u{0001}beta"],
                ['slug' => 'wildcard-plugin', 'name' => 'Wildcard plugin', 'version' => '*'],
                ['slug' => 'unknown-plugin', 'name' => 'Unknown plugin', 'version' => 'unknown'],
            ],
            'themes' => [],
        ]));

        $result = (new VulnerabilityScanner(WordfenceFeed::fromConfig()))->scan($website);

        $this->assertSame('partial', $result['status']);
        $this->assertSame('COMPONENT_VERSION_UNAVAILABLE', $result['reason']);
        $this->assertSame(4, $result['checked']);
        $this->assertSame(3, $result['skipped']);
        $this->assertCount(3, $result['findings']);
        $this->assertFalse(WordfenceFeed::isSafeVersionToken('*', allowWildcard: false));
        $this->assertTrue(WordfenceFeed::isSafeVersionToken('*', allowWildcard: true));
    }

    public function test_malformed_index_range_makes_scanner_coverage_unavailable_instead_of_clean(): void
    {
        $this->assertSame(0, $this->importJson($this->fixtureJson()));
        $manifest = json_decode((string) file_get_contents($this->indexPath), true, 8, JSON_THROW_ON_ERROR);
        $groupFile = WordfenceFeed::dataRootFor($this->indexPath).'/'.$manifest['generation'].'/plugin/fixture-plugin.jsonl';
        $entry = json_decode((string) file_get_contents($groupFile), true, 16, JSON_THROW_ON_ERROR);
        $entry['ranges'][0]['from'] = "1.0\u{0001}unsupported";
        file_put_contents($groupFile, json_encode($entry, JSON_THROW_ON_ERROR)."\n");

        $website = new Website;
        $website->setRelation('inventory', new WebsiteInventory([
            'core' => ['version' => '6.9'],
            'plugins' => [['slug' => 'fixture-plugin', 'name' => 'Fixture', 'version' => '1.5']],
            'themes' => [],
        ]));
        $result = (new VulnerabilityScanner(WordfenceFeed::fromConfig()))->scan($website);

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('VULNERABILITY_FEED_INVALID', $result['reason']);
        $this->assertSame([], $result['findings']);
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

    public function test_index_permission_failure_preserves_the_last_known_good_generation(): void
    {
        $this->assertSame(0, $this->importJson($this->fixtureJson()));
        $knownGood = file_get_contents($this->indexPath);
        app()->instance(WordfenceIndexAccess::class, new class extends WordfenceIndexAccess
        {
            public function allowPhpReadOnGeneration(string $path): void
            {
                throw new \RuntimeException('synthetic ACL failure');
            }
        });

        $next = $this->record('22345678-1234-4234-8234-123456789abc');
        $this->assertSame(1, $this->importJson($this->fixtureJson($next)));
        $this->assertSame($knownGood, file_get_contents($this->indexPath));
        $this->assertTrue(WordfenceFeed::fromConfig()->available());
    }

    public function test_imported_wordfence_index_grants_php_fpm_read_only_access_on_linux(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! is_executable('/usr/bin/setfacl') || ! is_executable('/usr/bin/getfacl')
            || ! function_exists('posix_geteuid') || posix_geteuid() === 0 || ! function_exists('posix_getpwnam') || posix_getpwnam('www-data') === false) {
            $this->markTestSkipped('The Linux ACL integration prerequisites are unavailable.');
        }

        $this->assertSame(0, $this->importJson($this->fixtureJson()));
        $manifest = json_decode((string) file_get_contents($this->indexPath), true, 8, JSON_THROW_ON_ERROR);
        $generation = WordfenceFeed::dataRootFor($this->indexPath).'/'.$manifest['generation'];

        $this->assertSame(['group::---', 'mask::r--', 'other::---', 'user::rw-', 'user:www-data:r--'], $this->aclEntries($this->indexPath));
        $this->assertSame(['group::---', 'mask::r-x', 'other::---', 'user::rwx', 'user:www-data:r-x'], $this->aclEntries($generation));
        $this->assertSame(['group::---', 'mask::r--', 'other::---', 'user::rw-', 'user:www-data:r--'], $this->aclEntries($generation.'/catalog.json'));
        $this->assertSame(['group::---', 'mask::r--', 'other::---', 'user::rw-', 'user:www-data:r--'], $this->aclEntries($generation.'/plugin/fixture-plugin.jsonl'));
    }

    /** @return list<string> */
    private function aclEntries(string $path): array
    {
        $process = new Process(['/usr/bin/getfacl', '-cp', $path]);
        $process->mustRun();
        $entries = array_values(array_filter(array_map('trim', explode("\n", $process->getOutput()))));
        sort($entries);

        return $entries;
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
