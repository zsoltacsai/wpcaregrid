<?php

namespace Tests\Feature\Platform;

use App\Models\PlatformAdvisory;
use App\Models\PlatformComponent;
use App\Models\PlatformUpdateCheck;
use App\Platform\Updates\ComponentInventory;
use App\Platform\Updates\OsvClient;
use App\Platform\Updates\PlatformUpdateScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Phase B: inventory from verified sources, advisory processing, fail-closed and idempotent checks. */
class PlatformUpdatesBackendTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/wpcg-pu-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/wordpress/wp-caregrid-connector', 0777, true);
        file_put_contents($this->dir.'/composer.lock', json_encode([
            'packages' => [['name' => 'acme/vulnerable', 'version' => 'v1.2.0'], ['name' => 'acme/fine', 'version' => '2.0.0'], ['name' => 'bad name!', 'version' => '1.0.0'], ['name' => 'acme/noversion', 'version' => 'dev-main']],
            'packages-dev' => [['name' => 'acme/dev', 'version' => '3.1.0']],
        ]));
        file_put_contents($this->dir.'/package-lock.json', json_encode(['packages' => [
            '' => ['name' => 'root'],
            'node_modules/left-pad' => ['version' => '1.3.0', 'dev' => true],
            'node_modules/a/node_modules/left-pad' => ['version' => '1.0.0'],
        ]]));
        file_put_contents($this->dir.'/wordpress/wp-caregrid-connector/wp-caregrid-connector.php', "<?php\n/**\n * Version:           0.4.8\n */\n");
        file_put_contents($this->dir.'/os-release', "NAME=\"Ubuntu\"\nVERSION_ID=\"26.04\"\n");
        app()->instance(ComponentInventory::class, new ComponentInventory($this->dir, $this->dir.'/os-release'));
        config(['platform_updates.enabled' => true, 'platform_updates.min_interval_seconds' => 0, 'app.deployment_environment' => 'testing']);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        foreach (['/composer.lock', '/package-lock.json', '/os-release', '/wordpress/wp-caregrid-connector/wp-caregrid-connector.php'] as $file) {
            @unlink($this->dir.$file);
        }
        @rmdir($this->dir.'/wordpress/wp-caregrid-connector');
        @rmdir($this->dir.'/wordpress');
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @param array<string, list<string>> $hits "name@version" => advisory ids */
    private function osv(array $hits = [], ?array $detail = null): void
    {
        Http::swap(new Factory);   // a new fake replaces the previous one (the first match wins otherwise)
        Http::fake([
            'api.osv.dev/v1/querybatch' => function ($request) use ($hits) {
                $results = [];
                foreach ($request['queries'] as $query) {
                    $ids = $hits[$query['package']['name'].'@'.$query['version']] ?? [];
                    $results[] = $ids === [] ? (object) [] : ['vulns' => array_map(fn ($id) => ['id' => $id, 'modified' => '2026-01-01T00:00:00Z'], $ids)];
                }

                return Http::response(['results' => $results], 200);
            },
            'api.osv.dev/v1/vulns/*' => fn ($request) => Http::response($detail ?? [
                'id' => basename($request->url()), 'aliases' => ['CVE-2026-0001'], 'summary' => 'Remote code execution',
                'published' => '2026-02-03T04:05:06Z', 'database_specific' => ['severity' => 'CRITICAL'],
                'affected' => [['package' => ['ecosystem' => 'Packagist', 'name' => 'acme/vulnerable'], 'ranges' => [['type' => 'ECOSYSTEM', 'events' => [['introduced' => '0'], ['fixed' => '1.2.5']]]]]],
            ], 200),
        ]);
    }

    public function test_inventory_reads_only_verified_sources_and_marks_the_unreadable_as_unknown(): void
    {
        $keys = collect(app(ComponentInventory::class)->collect())->keyBy('key');

        $this->assertSame('1.2.0', $keys['composer:acme/vulnerable']->version);
        $this->assertNull($keys['composer:acme/noversion']->version, 'dev-main is not a verified release version');
        $this->assertArrayNotHasKey('composer:bad name!', $keys->all(), 'a malformed name is ignored');
        $this->assertTrue($keys['composer:acme/dev']->developmentOnly);
        $this->assertSame(['1.3.0', '1.0.0'], [$keys['npm:left-pad']->version, $keys['npm:left-pad@1.0.0']->version], 'a nested copy is its own component');
        $this->assertSame('0.4.8', $keys['connector:wp-caregrid-connector']->version);
        $this->assertSame('26.04', $keys['runtime:os']->version);
        $this->assertSame(PHP_VERSION, $keys['runtime:php']->version);
        $this->assertNull($keys['runtime:php']->ecosystem, 'runtime components have no advisory source yet: UNVERIFIED, never clean');

        unlink($this->dir.'/composer.lock');
        $this->assertNull(collect(app(ComponentInventory::class)->collect())->firstWhere('key', 'composer:lock')->version);
    }

    public function test_a_vulnerable_version_is_recorded_with_proven_details_and_a_proven_fix_only(): void
    {
        $this->osv(['acme/vulnerable@1.2.0' => ['GHSA-aaaa-bbbb-cccc']]);

        $check = app(PlatformUpdateScanner::class)->run('manual');

        $this->assertSame('succeeded', $check->status);
        $advisory = PlatformAdvisory::firstOrFail();
        $this->assertSame(['GHSA-aaaa-bbbb-cccc', 'critical', '1.2.0', '1.2.5', PlatformAdvisory::OPEN, 'osv'], [$advisory->advisory_id, $advisory->severity, $advisory->affected_version, $advisory->fixed_version, $advisory->state, $advisory->source]);
        $this->assertSame(['CVE-2026-0001'], $advisory->aliases);
        $this->assertSame('https://osv.dev/vulnerability/GHSA-aaaa-bbbb-cccc', $advisory->source_url);
        $this->assertSame(1, $check->advisories_critical);
        $this->assertSame('CHECKED', PlatformComponent::where('component_key', 'composer:acme/vulnerable')->value('advisory_state'));
    }

    public function test_an_unaffected_version_has_no_advisory_and_a_runtime_component_stays_unverified(): void
    {
        $this->osv();

        app(PlatformUpdateScanner::class)->run('manual');

        $this->assertSame(0, PlatformAdvisory::count());
        $this->assertSame('CHECKED', PlatformComponent::where('component_key', 'composer:acme/fine')->value('advisory_state'));
        $this->assertSame('UNVERIFIED', PlatformComponent::where('component_key', 'runtime:php')->value('advisory_state'));
        $unknown = PlatformComponent::where('component_key', 'composer:acme/noversion')->first();
        $this->assertSame(['UNKNOWN', 'UNKNOWN', null], [$unknown->version_state, $unknown->advisory_state, $unknown->installed_version]);
        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'querybatch') || collect($request['queries'])->doesntContain(fn ($q) => $q['package']['name'] === 'acme/noversion'));
    }

    public function test_a_source_timeout_fails_the_check_and_resolves_and_creates_nothing(): void
    {
        $this->osv(['acme/vulnerable@1.2.0' => ['GHSA-aaaa-bbbb-cccc']]);
        app(PlatformUpdateScanner::class)->run('manual');
        Http::swap(new Factory);
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $check = app(PlatformUpdateScanner::class)->run('manual');

        $this->assertSame(['failed', 'osv_unreachable'], [$check->status, $check->reason_code]);
        $this->assertSame(PlatformAdvisory::OPEN, PlatformAdvisory::firstOrFail()->state, 'a broken source never looks like no advisories');
        $this->assertSame(1, PlatformAdvisory::count());
    }

    public function test_malformed_advisory_data_is_refused_not_trusted(): void
    {
        $cases = [
            fn () => Http::fake(['api.osv.dev/v1/querybatch' => Http::response('<html>', 200)]),
            fn () => Http::fake(['api.osv.dev/v1/querybatch' => Http::response(['results' => []], 200)]),
            fn () => Http::fake(['api.osv.dev/v1/querybatch' => fn ($r) => Http::response(['results' => array_map(fn () => ['vulns' => [['id' => '../../etc/passwd']]], $r['queries'])], 200)]),
            fn () => Http::fake(['api.osv.dev/v1/querybatch' => Http::response('x', 429)]),
            fn () => Http::fake(['api.osv.dev/v1/querybatch' => Http::response('x', 302, ['Location' => 'https://evil.example/'])]),
        ];
        foreach ($cases as $index => $setup) {
            PlatformUpdateCheck::query()->delete();
            $setup();
            $check = app(PlatformUpdateScanner::class)->run('manual');
            $this->assertSame('failed', $check->status, "case $index");
            $this->assertSame(0, PlatformAdvisory::count());
        }
    }

    public function test_a_failing_detail_lookup_gives_a_partial_check_with_unknown_severity(): void
    {
        $this->osv(['acme/vulnerable@1.2.0' => ['GHSA-aaaa-bbbb-cccc']], detail: ['id' => 'GHSA-other-other-other']);

        $check = app(PlatformUpdateScanner::class)->run('manual');

        $this->assertSame(['partial', 'osv_advisory_mismatch'], [$check->status, $check->reason_code]);
        $advisory = PlatformAdvisory::firstOrFail();
        $this->assertSame(['unknown', null, PlatformAdvisory::OPEN], [$advisory->severity, $advisory->fixed_version, $advisory->state]);
    }

    public function test_repeating_a_check_is_idempotent_and_resolves_a_fixed_advisory(): void
    {
        $this->osv(['acme/vulnerable@1.2.0' => ['GHSA-aaaa-bbbb-cccc']]);
        app(PlatformUpdateScanner::class)->run('manual');
        app(PlatformUpdateScanner::class)->run('manual');
        $this->assertSame([1, 1], [PlatformAdvisory::count(), PlatformComponent::where('component_key', 'composer:acme/vulnerable')->count()]);

        $this->osv();   // the source no longer reports it
        app(PlatformUpdateScanner::class)->run('manual');

        $this->assertSame(PlatformAdvisory::RESOLVED, PlatformAdvisory::firstOrFail()->state);
    }

    public function test_a_component_that_disappears_is_marked_absent_and_its_advisories_resolved(): void
    {
        $this->osv(['acme/vulnerable@1.2.0' => ['GHSA-aaaa-bbbb-cccc']]);
        app(PlatformUpdateScanner::class)->run('manual');
        file_put_contents($this->dir.'/composer.lock', json_encode(['packages' => [['name' => 'acme/fine', 'version' => '2.0.0']]]));

        app(PlatformUpdateScanner::class)->run('manual');

        $this->assertFalse((bool) PlatformComponent::where('component_key', 'composer:acme/vulnerable')->value('present'));
        $this->assertSame(PlatformAdvisory::RESOLVED, PlatformAdvisory::firstOrFail()->state);
    }

    public function test_an_unreadable_lock_file_does_not_make_components_disappear(): void
    {
        $this->osv();
        app(PlatformUpdateScanner::class)->run('manual');
        unlink($this->dir.'/composer.lock');

        app(PlatformUpdateScanner::class)->run('manual');

        $this->assertTrue((bool) PlatformComponent::where('component_key', 'composer:acme/fine')->value('present'));
    }

    public function test_checks_cannot_overlap_or_repeat_within_the_minimum_interval(): void
    {
        $this->osv();
        $lock = Cache::lock(PlatformUpdateScanner::LOCK, 60);
        $this->assertTrue($lock->get());
        $this->assertSame('check_already_running', app(PlatformUpdateScanner::class)->run('manual')->reason_code);
        $lock->release();

        config(['platform_updates.min_interval_seconds' => 300]);
        app(PlatformUpdateScanner::class)->run('manual');
        $this->assertSame('checked_recently', app(PlatformUpdateScanner::class)->run('manual')->reason_code);
    }

    public function test_staging_next_without_the_external_services_opt_in_makes_no_outbound_request(): void
    {
        Http::fake();
        config(['app.deployment_environment' => 'staging_next', 'marketing_release.staging_active_features.external_services' => false]);

        $check = app(PlatformUpdateScanner::class)->run('manual');

        $this->assertSame(['refused', 'staging_external_contact_closed'], [$check->status, $check->reason_code]);
        Http::assertNothingSent();
        $this->artisan('platform:updates:check')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_the_command_is_disabled_by_default_and_reports_a_fixed_result_line(): void
    {
        config(['platform_updates.enabled' => false]);
        $this->artisan('platform:updates:check')->expectsOutput('PLATFORM_UPDATE_CHECK=REFUSED;CODE=disabled')->assertFailed();

        config(['platform_updates.enabled' => true]);
        $this->osv();
        $this->artisan('platform:updates:check')->assertSuccessful();
    }

    public function test_only_a_fixed_version_of_a_version_range_containing_the_installed_version_is_proven(): void
    {
        $affected = [[
            'package' => ['ecosystem' => 'Packagist', 'name' => 'acme/vulnerable'],
            'ranges' => [
                ['type' => 'GIT', 'repo' => 'https://example.invalid/x', 'events' => [['introduced' => '0'], ['fixed' => '1234abcd1234abcd1234abcd1234abcd1234abcd']]],
                ['type' => 'ECOSYSTEM', 'events' => [['introduced' => '0'], ['fixed' => '1.1.9']]],        // does not contain 1.2.0 any more
                ['type' => 'ECOSYSTEM', 'events' => [['introduced' => '1.2.0'], ['fixed' => '1.2.7']]],    // contains 1.2.0
                ['type' => 'ECOSYSTEM', 'events' => [['introduced' => '2.0.0'], ['fixed' => '2.0.4']]],    // a later series
            ],
        ]];
        $this->osv(['acme/vulnerable@1.2.0' => ['GHSA-aaaa-bbbb-cccc']], detail: ['id' => 'GHSA-aaaa-bbbb-cccc', 'database_specific' => ['severity' => 'HIGH'], 'affected' => $affected]);

        app(PlatformUpdateScanner::class)->run('manual');

        $this->assertSame('1.2.7', PlatformAdvisory::firstOrFail()->fixed_version);
    }

    public function test_a_git_range_alone_never_produces_a_fix(): void
    {
        $affected = [['package' => ['ecosystem' => 'Packagist', 'name' => 'acme/vulnerable'], 'ranges' => [['type' => 'GIT', 'events' => [['introduced' => '0'], ['fixed' => '1234abcd1234abcd']]]]]];
        $this->osv(['acme/vulnerable@1.2.0' => ['GHSA-aaaa-bbbb-cccc']], detail: ['id' => 'GHSA-aaaa-bbbb-cccc', 'affected' => $affected]);

        app(PlatformUpdateScanner::class)->run('manual');

        $this->assertNull(PlatformAdvisory::firstOrFail()->fixed_version);
    }

    public function test_a_withdrawn_advisory_is_never_open(): void
    {
        $this->osv(['acme/vulnerable@1.2.0' => ['GHSA-aaaa-bbbb-cccc']], detail: ['id' => 'GHSA-aaaa-bbbb-cccc', 'withdrawn' => '2026-03-01T00:00:00Z', 'database_specific' => ['severity' => 'CRITICAL']]);

        $check = app(PlatformUpdateScanner::class)->run('manual');

        $this->assertSame(0, PlatformAdvisory::query()->where('state', 'open')->count());
        $this->assertSame(0, $check->advisories_critical);
    }

    public function test_a_withdrawn_advisory_does_not_flip_back_to_open_on_the_next_run(): void
    {
        $hit = ['acme/vulnerable@1.2.0' => ['GHSA-aaaa-bbbb-cccc']];
        $this->osv($hit);
        app(PlatformUpdateScanner::class)->run('manual');
        $this->assertSame(1, PlatformAdvisory::where('state', 'open')->count());

        $this->osv($hit, ['id' => 'GHSA-aaaa-bbbb-cccc', 'withdrawn' => '2026-03-01T00:00:00Z']);   // the publisher withdraws it, OSV still lists it
        $this->travel(25)->hours();   // advisories are re-read from the source once a day
        foreach ([1, 2, 3] as $run) {
            $check = app(PlatformUpdateScanner::class)->run('manual');
            $this->assertSame(0, PlatformAdvisory::where('state', 'open')->count(), "run $run: {$check->status} {$check->reason_code}");
        }
    }

    public function test_a_failed_reread_never_downgrades_a_known_advisory(): void
    {
        $hit = ['acme/vulnerable@1.2.0' => ['GHSA-aaaa-bbbb-cccc']];
        $this->osv($hit);
        app(PlatformUpdateScanner::class)->run('manual');
        $before = PlatformAdvisory::firstOrFail();
        $this->assertSame(['critical', '1.2.5'], [$before->severity, $before->fixed_version]);

        $this->travel(25)->hours();
        Http::swap(new Factory);
        Http::fake([
            'api.osv.dev/v1/querybatch' => fn ($r) => Http::response(['results' => array_map(fn ($q) => $q['package']['name'] === 'acme/vulnerable' ? ['vulns' => [['id' => 'GHSA-aaaa-bbbb-cccc']]] : (object) [], $r['queries'])], 200),
            'api.osv.dev/v1/vulns/*' => Http::response('x', 503),
        ]);
        $check = app(PlatformUpdateScanner::class)->run('manual');

        $after = PlatformAdvisory::firstOrFail();
        $this->assertSame(['critical', '1.2.5', 'open', 1], [$after->severity, $after->fixed_version, $after->state, $check->advisories_critical]);
    }

    /** Rewrites the installed version of acme/vulnerable in the fake composer.lock (the inventory reads it on every check). */
    private function installVulnerableVersion(string $version): void
    {
        file_put_contents($this->dir.'/composer.lock', json_encode([
            'packages' => [['name' => 'acme/vulnerable', 'version' => $version], ['name' => 'acme/fine', 'version' => '2.0.0']],
            'packages-dev' => [],
        ]));
    }

    /** A source that still reports GHSA-aaaa-bbbb-cccc for every queried version but cannot serve its text. */
    private function osvStillReportsButTextUnreadable(): void
    {
        Http::swap(new Factory);
        Http::fake([
            'api.osv.dev/v1/querybatch' => fn ($r) => Http::response(['results' => array_map(fn ($q) => $q['package']['name'] === 'acme/vulnerable' ? ['vulns' => [['id' => 'GHSA-aaaa-bbbb-cccc']]] : (object) [], $r['queries'])], 200),
            'api.osv.dev/v1/vulns/*' => Http::response('x', 503),
        ]);
    }

    public function test_a_stale_fix_is_dropped_when_the_installed_version_changed_and_the_text_cannot_be_read(): void
    {
        $this->osv(['acme/vulnerable@1.2.0' => ['GHSA-aaaa-bbbb-cccc']]);
        app(PlatformUpdateScanner::class)->run('manual');
        $known = PlatformAdvisory::firstOrFail();
        $this->assertSame(['1.2.0', '1.2.5', 'critical'], [$known->affected_version, $known->fixed_version, $known->severity]);
        $verifiedAt = $known->details_checked_at;

        // The component was changed to another vulnerable version; the source still affects it but its text is unreadable now.
        $this->installVulnerableVersion('1.2.1');
        $this->travel(1)->minutes();
        $this->osvStillReportsButTextUnreadable();
        $check = app(PlatformUpdateScanner::class)->run('manual');

        $stale = PlatformAdvisory::firstOrFail();
        // Still open and never downgraded (the source says it affects 1.2.1), but a fix that was proven for 1.2.0 is not
        // presented for 1.2.1; the old text is not claimed as freshly verified either.
        $this->assertSame(['open', 'critical', null, '1.2.0'], [$stale->state, $stale->severity, $stale->fixed_version, $stale->affected_version]);
        $this->assertTrue($verifiedAt->equalTo($stale->details_checked_at), 'details_checked_at must not move when nothing was re-read');
        $this->assertSame('partial', $check->status);

        // Once the text can be read again the fix is proven for the installed version and the row is current.
        $this->travel(1)->minutes();
        $this->osv(['acme/vulnerable@1.2.1' => ['GHSA-aaaa-bbbb-cccc']]);
        app(PlatformUpdateScanner::class)->run('manual');
        $fresh = PlatformAdvisory::firstOrFail();
        $this->assertSame(['open', '1.2.1', '1.2.5', 1], [$fresh->state, $fresh->affected_version, $fresh->fixed_version, PlatformAdvisory::count()]);
        $this->assertTrue($fresh->details_checked_at->greaterThan($verifiedAt));
    }

    public function test_a_failed_reread_for_the_same_installed_version_keeps_its_proven_fix(): void
    {
        $this->osv(['acme/vulnerable@1.2.0' => ['GHSA-aaaa-bbbb-cccc']]);
        app(PlatformUpdateScanner::class)->run('manual');

        $this->travel(25)->hours();
        $this->osvStillReportsButTextUnreadable();
        app(PlatformUpdateScanner::class)->run('manual');

        $kept = PlatformAdvisory::firstOrFail();
        $this->assertSame(['open', '1.2.0', '1.2.5', 'critical'], [$kept->state, $kept->affected_version, $kept->fixed_version, $kept->severity]);
    }

    public function test_a_changed_severity_is_taken_from_the_fresh_read_and_a_missing_one_is_unknown(): void
    {
        $detail = fn (?string $severity) => [
            'id' => 'GHSA-aaaa-bbbb-cccc', 'aliases' => ['CVE-2026-0001'], 'summary' => 'Remote code execution', 'published' => '2026-02-03T04:05:06Z',
            'database_specific' => $severity === null ? [] : ['severity' => $severity],
            'affected' => [['package' => ['ecosystem' => 'Packagist', 'name' => 'acme/vulnerable'], 'ranges' => [['type' => 'ECOSYSTEM', 'events' => [['introduced' => '0'], ['fixed' => '1.2.5']]]]]],
        ];
        $hits = ['acme/vulnerable@1.2.0' => ['GHSA-aaaa-bbbb-cccc']];

        $this->osv($hits, $detail('CRITICAL'));
        $check = app(PlatformUpdateScanner::class)->run('manual');
        $this->assertSame(['critical', 1], [PlatformAdvisory::firstOrFail()->severity, $check->advisories_critical]);

        $this->travel(25)->hours();   // re-read after a day: the publisher lowered it
        $this->osv($hits, $detail('LOW'));
        $check = app(PlatformUpdateScanner::class)->run('manual');
        $this->assertSame(['low', 0, 1], [PlatformAdvisory::firstOrFail()->severity, $check->advisories_critical, PlatformAdvisory::count()]);

        $this->travel(25)->hours();   // the record no longer states any severity: not invented, read again next time
        $this->osv($hits, $detail(null));
        app(PlatformUpdateScanner::class)->run('manual');
        $this->assertSame(['unknown', 'open'], [PlatformAdvisory::firstOrFail()->severity, PlatformAdvisory::firstOrFail()->state]);
    }

    public function test_a_resolved_advisory_that_comes_back_is_open_again_and_announced_again(): void
    {
        $hits = ['acme/vulnerable@1.2.0' => ['GHSA-aaaa-bbbb-cccc']];
        $this->osv($hits);
        app(PlatformUpdateScanner::class)->run('manual');
        $this->osv();   // gone from the source: resolved
        app(PlatformUpdateScanner::class)->run('manual');
        PlatformAdvisory::query()->update(['notified_at' => now()]);
        $this->assertSame(PlatformAdvisory::RESOLVED, PlatformAdvisory::firstOrFail()->state);

        $this->osv($hits);   // reported again and readable
        app(PlatformUpdateScanner::class)->run('manual');

        $back = PlatformAdvisory::firstOrFail();
        $this->assertSame([PlatformAdvisory::OPEN, null, null, 1], [$back->state, $back->resolved_at, $back->notified_at, PlatformAdvisory::count()]);
    }

    public function test_a_resolved_advisory_stays_resolved_when_its_text_cannot_be_read(): void
    {
        $hit = ['acme/vulnerable@1.2.0' => ['GHSA-aaaa-bbbb-cccc']];
        $this->osv($hit);
        app(PlatformUpdateScanner::class)->run('manual');
        PlatformAdvisory::query()->update(['state' => 'resolved', 'resolved_at' => now(), 'notified_at' => now()]);

        Http::swap(new Factory);
        Http::fake([
            'api.osv.dev/v1/querybatch' => fn ($r) => Http::response(['results' => array_map(fn ($q) => $q['package']['name'] === 'acme/vulnerable' ? ['vulns' => [['id' => 'GHSA-aaaa-bbbb-cccc']]] : (object) [], $r['queries'])], 200),
            'api.osv.dev/v1/vulns/*' => Http::response('x', 503),
        ]);
        app(PlatformUpdateScanner::class)->run('manual');

        $advisory = PlatformAdvisory::firstOrFail();
        $this->assertSame('resolved', $advisory->state);
        $this->assertNotNull($advisory->notified_at, 'no new notification');
    }

    public function test_the_client_asks_for_uncompressed_answers(): void
    {
        $this->osv();
        app(PlatformUpdateScanner::class)->run('manual');

        Http::assertSent(fn ($request) => $request->header('Accept-Encoding') === ['identity']);
    }

    public function test_development_only_components_do_not_count_as_open_or_critical(): void
    {
        $this->osv(['acme/dev@3.1.0' => ['GHSA-dev1-dev1-dev1']], detail: ['id' => 'GHSA-dev1-dev1-dev1', 'database_specific' => ['severity' => 'CRITICAL'], 'affected' => []]);

        $check = app(PlatformUpdateScanner::class)->run('manual');

        $this->assertSame(1, PlatformAdvisory::where('state', 'open')->count(), 'it is stored, with its component flagged development_only');
        $this->assertSame([0, 0], [$check->advisories_open, $check->advisories_critical]);
        $this->assertTrue((bool) PlatformComponent::where('component_key', 'composer:acme/dev')->value('development_only'));
    }

    public function test_an_oversized_or_non_array_answer_is_refused(): void
    {
        config(['platform_updates.max_response_bytes' => 1000]);
        Http::swap(new Factory);
        Http::fake(['api.osv.dev/*' => Http::response(str_repeat('x', 5000), 200, ['Content-Length' => '5000'])]);
        $this->assertSame('osv_response_too_large', app(PlatformUpdateScanner::class)->run('manual')->reason_code);

        config(['platform_updates.max_response_bytes' => 5 * 1024 * 1024]);
        PlatformUpdateCheck::query()->delete();
        Http::swap(new Factory);
        Http::fake(['api.osv.dev/v1/querybatch' => fn ($r) => Http::response(['results' => array_map(fn () => ['vulns' => 'not-a-list'], $r['queries'])], 200)]);
        $check = app(PlatformUpdateScanner::class)->run('manual');
        $this->assertSame(['failed', 'osv_batch_entry_invalid'], [$check->status, $check->reason_code]);
    }

    public function test_an_overlong_npm_key_is_ignored_instead_of_breaking_the_check(): void
    {
        $name = str_repeat('a', 150);
        file_put_contents($this->dir.'/package-lock.json', json_encode(['packages' => ['node_modules/x/node_modules/'.$name => ['version' => '1.0.0-'.str_repeat('b', 50)]]]));

        $keys = collect(app(ComponentInventory::class)->collect())->pluck('key');

        $this->assertTrue($keys->every(fn ($key) => strlen($key) <= 190));
    }

    public function test_the_feature_flag_defaults_to_off_and_is_documented_off(): void
    {
        $this->assertStringContainsString("env('PLATFORM_UPDATES_ENABLED', false)", (string) file_get_contents(base_path('config/platform_updates.php')));
        $this->assertMatchesRegularExpression('/^PLATFORM_UPDATES_ENABLED=false$/m', (string) file_get_contents(base_path('.env.example')));
    }

    public function test_the_module_can_execute_nothing_and_update_nothing(): void
    {
        $forbidden = ['exec(', 'shell_exec', 'passthru', 'proc_open', 'popen(', 'system(', 'Process::', 'Artisan::call', 'eval(', 'composer update', 'sudo'];
        foreach (glob(base_path('app/Platform/Updates/*.php')) as $file) {
            $code = file_get_contents($file);
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $code, basename($file).' contains '.$needle);
            }
        }
        $this->assertSame(['https://api.osv.dev/v1/querybatch', 'https://api.osv.dev/v1/vulns/'], [OsvClient::BATCH_URL, OsvClient::VULN_URL]);
    }
}
