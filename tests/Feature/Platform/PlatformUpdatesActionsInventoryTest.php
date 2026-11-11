<?php

namespace Tests\Feature\Platform;

use App\Models\PlatformAdvisory;
use App\Models\PlatformComponent;
use App\Platform\Updates\ActionsLockParser;
use App\Platform\Updates\ComponentInventory;
use App\Platform\Updates\DetectedComponent;
use App\Platform\Updates\PlatformUpdateGate;
use App\Platform\Updates\PlatformUpdateOverview;
use App\Platform\Updates\PlatformUpdateScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

/** Phase E1: read-only GitHub Actions inventory. No advisory source: these components can only ever be UNVERIFIED. */
class PlatformUpdatesActionsInventoryTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    private const SHA_A = '3d3c42e5aac5ba805825da76410c181273ba90b1';

    private const SHA_B = '949feb2413d6458794dcd2491c4babbbce0c15c1';

    private const SHA_C = 'eb7c497e18156a6bbabfef1d3a82760b9eda3962';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/wpcg-ai-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/.github/workflows', 0777, true);
        mkdir($this->dir.'/wordpress/wp-caregrid-connector', 0777, true);
        file_put_contents($this->dir.'/composer.lock', json_encode(['packages' => [['name' => 'acme/fine', 'version' => '2.0.0']], 'packages-dev' => []]));
        file_put_contents($this->dir.'/package-lock.json', json_encode(['packages' => ['' => ['name' => 'root']]]));
        file_put_contents($this->dir.'/wordpress/wp-caregrid-connector/wp-caregrid-connector.php', "<?php\n/**\n * Version:           0.4.8\n */\n");
        file_put_contents($this->dir.'/os-release', "NAME=\"Ubuntu\"\nVERSION_ID=\"26.04\"\n");
        $this->writeLock($this->validLock());
        app()->instance(ComponentInventory::class, new ComponentInventory($this->dir, $this->dir.'/os-release'));
        config(['platform_updates.enabled' => true, 'platform_updates.min_interval_seconds' => 0, 'app.deployment_environment' => 'testing']);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->dir);
        parent::tearDown();
    }

    private function rmTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (is_dir($path) ? array_diff(scandir($path), ['.', '..']) : [] as $entry) {
            $this->rmTree($path.'/'.$entry);
        }
        @rmdir($path);
    }

    private function writeLock(string $text): void
    {
        file_put_contents($this->dir.'/.github/workflows/actions.lock', $text);
    }

    private function dep(string $spec, string $ref, string $sha): string
    {
        return "    '$spec':\n        ref: '$ref'\n        commit: 'sha1-$sha'\n        owner_id: 44036562\n        repo_id: 197814629\n";
    }

    /** @param  list<string>  $dependencies already formatted dependency blocks */
    private function lock(array $dependencies, string $version = 'v0.0.3'): string
    {
        // The workflow section names exactly the dependencies given (a workflow may only name described actions).
        $uses = '';
        foreach ($dependencies as $block) {
            if (preg_match("/^    '([^']+)':/", $block, $m) === 1) {
                $uses .= "        - '".$m[1]."'\n";
            }
        }

        return "# This file is machine-generated.\nversion: '$version'\nworkflows:\n    '.github/workflows/ci.yml':\n".$uses."dependencies:\n".implode('', $dependencies);
    }

    private function validLock(): string
    {
        return $this->lock([
            $this->dep('actions/checkout@'.self::SHA_A, 'v7.0.1', self::SHA_A),
            $this->dep('actions/checkout@v7.0.1', 'v7.0.1', self::SHA_A),
            $this->dep('actions/setup-node@'.self::SHA_B, 'v7.1.0', self::SHA_B),
            $this->dep('shivammathur/setup-php@2.40.0', '2.40.0', self::SHA_C),
        ]);
    }

    /** @return list<DetectedComponent> */
    private function actions(): array
    {
        return array_values(array_filter((new ComponentInventory($this->dir, $this->dir.'/os-release'))->collect(), fn (DetectedComponent $c) => $c->kind === 'github-action'));
    }

    private function enable(): void
    {
        config(['platform_updates.github_actions_inventory' => true]);
    }

    public function test_the_inventory_is_off_by_default_even_when_the_lock_file_exists(): void
    {
        $this->assertFalse(config('platform_updates.github_actions_inventory'));
        $this->assertFalse(PlatformUpdateGate::actionsInventoryEnabled());
        $this->assertSame([], $this->actions());

        // Its own switch needs the main feature: on its own it does nothing.
        config(['platform_updates.enabled' => false, 'platform_updates.github_actions_inventory' => true]);
        $this->assertFalse(PlatformUpdateGate::actionsInventoryEnabled());
        $this->assertSame([], $this->actions());
    }

    public function test_only_a_strict_true_switch_enables_it(): void
    {
        foreach (['true', 1, 'yes', 'on', 'enabled'] as $value) {
            config(['platform_updates.github_actions_inventory' => $value]);
            $this->assertFalse(PlatformUpdateGate::actionsInventoryEnabled(), 'not strictly true: '.var_export($value, true));
        }
        $this->enable();
        $this->assertTrue(PlatformUpdateGate::actionsInventoryEnabled());
    }

    public function test_the_switch_is_declared_off_in_the_environment_example_and_read_fail_closed(): void
    {
        $this->assertMatchesRegularExpression('/^PLATFORM_UPDATES_ACTIONS_INVENTORY=false$/m', (string) file_get_contents(base_path('.env.example')));
        $this->assertStringContainsString("env('PLATFORM_UPDATES_ACTIONS_INVENTORY', false)", (string) file_get_contents(base_path('config/platform_updates.php')));
        $this->assertStringContainsString('FILTER_NULL_ON_FAILURE) === true', (string) file_get_contents(base_path('config/platform_updates.php')));
    }

    public function test_valid_entries_become_unverified_inventory_components(): void
    {
        $this->enable();

        $components = $this->actions();

        // The same action under its tag and under its SHA is one component.
        $this->assertSame(
            ['github-action:actions/checkout@'.self::SHA_A, 'github-action:actions/setup-node@'.self::SHA_B, 'github-action:shivammathur/setup-php@'.self::SHA_C],
            array_map(fn (DetectedComponent $c) => $c->key, $components),
        );
        $this->assertSame(['7.0.1', '7.1.0', '2.40.0'], array_map(fn (DetectedComponent $c) => $c->version, $components));
        foreach ($components as $component) {
            $this->assertNull($component->ecosystem, 'no advisory source');
            $this->assertFalse($component->checkable());
            $this->assertFalse($component->developmentOnly);
            $this->assertSame('actions.lock', $component->evidenceSource);
        }
    }

    public function test_the_real_repository_lock_file_is_accepted_without_being_modified(): void
    {
        $real = base_path('.github/workflows/actions.lock');
        $before = hash_file('sha256', $real);

        $entries = ActionsLockParser::parse((string) file_get_contents($real));

        $this->assertNotNull($entries);
        $this->assertSame(7, count($entries));
        $this->assertSame($before, hash_file('sha256', $real));
        // Reading through the inventory leaves the file alone too.
        $this->enable();
        $inventory = new ComponentInventory(base_path(), $this->dir.'/os-release');
        $actions = array_filter($inventory->collect(), fn (DetectedComponent $c) => $c->kind === 'github-action');
        $this->assertCount(4, $actions);
        $this->assertSame($before, hash_file('sha256', $real));
    }

    public function test_the_same_action_at_two_different_commits_is_two_components_and_an_unversioned_ref_is_unknown(): void
    {
        $this->enable();
        $this->writeLock($this->lock([
            $this->dep('actions/checkout@'.self::SHA_A, 'v7.0.1', self::SHA_A),
            $this->dep('actions/checkout@'.self::SHA_B, 'v6.0.0', self::SHA_B),
            $this->dep('acme/tool@main', 'main', self::SHA_C),
        ]));

        $byKey = [];
        foreach ($this->actions() as $component) {
            $byKey[$component->key] = $component->version;
        }

        $this->assertSame([
            'github-action:acme/tool@'.self::SHA_C => null,   // a branch is not a version
            'github-action:actions/checkout@'.self::SHA_A => '7.0.1',
            'github-action:actions/checkout@'.self::SHA_B => '6.0.0',
        ], $byKey);
    }

    /** @return array<string, array{0: string}> */
    public static function rejectedFiles(): array
    {
        $dep = fn (string $spec = 'actions/checkout@v7.0.1', string $ref = 'v7.0.1', string $sha = self::SHA_A) => "    '$spec':\n        ref: '$ref'\n        commit: 'sha1-$sha'\n        owner_id: 1\n        repo_id: 2\n";
        $head = "version: 'v0.0.3'\nworkflows:\n    '.github/workflows/ci.yml':\n        - 'actions/checkout@v7.0.1'\ndependencies:\n";

        return [
            'empty file' => [''],
            'comments only' => ["# only a comment\n"],
            'unknown format version' => [str_replace('v0.0.3', 'v0.0.4', $head).$dep()],
            'missing version' => [substr($head, strlen("version: 'v0.0.3'\n")).$dep()],
            'version twice' => ["version: 'v0.0.3'\n".$head.$dep()],
            'no dependencies' => [$head],
            'dependencies missing' => ["version: 'v0.0.3'\nworkflows:\n    '.github/workflows/ci.yml':\n        - 'actions/checkout@v7.0.1'\n"],
            'workflows missing' => ["version: 'v0.0.3'\ndependencies:\n".$dep()],
            'unexpected top level key' => [$head.$dep()."extra: 1\n"],
            'unexpected line in a dependency' => [$head.$dep()."        url: 'https://evil.example/x'\n"],
            'unexpected line in workflows' => ["version: 'v0.0.3'\nworkflows:\n    'other/path.yml':\n        - 'actions/checkout@v7.0.1'\ndependencies:\n".$dep()],
            'wrong indentation' => [$head."  'actions/checkout@v7.0.1':\n        ref: 'v7.0.1'\n        commit: 'sha1-".self::SHA_A."'\n        owner_id: 1\n        repo_id: 2\n"],
            'tab indentation' => [$head."\t'actions/checkout@v7.0.1':\n"],
            'carriage return' => [str_replace("\n", "\r\n", $head.$dep())],
            'nul byte' => [$head.$dep()."\0"],
            'invalid utf-8' => [$head.$dep()."\xFF\n"],
            'short commit' => [$head.$dep('actions/checkout@v7.0.1', 'v7.0.1', '3d3c42e')],
            'uppercase commit' => [$head.$dep('actions/checkout@v7.0.1', 'v7.0.1', strtoupper(self::SHA_A))],
            'sha256 commit' => [$head."    'actions/checkout@v7.0.1':\n        ref: 'v7.0.1'\n        commit: 'sha256-".str_repeat('a', 64)."'\n        owner_id: 1\n        repo_id: 2\n"],
            'missing commit' => [$head."    'actions/checkout@v7.0.1':\n        ref: 'v7.0.1'\n        owner_id: 1\n        repo_id: 2\n"],
            'missing ref' => [$head."    'actions/checkout@v7.0.1':\n        commit: 'sha1-".self::SHA_A."'\n        owner_id: 1\n        repo_id: 2\n"],
            'missing ids' => [$head."    'actions/checkout@v7.0.1':\n        ref: 'v7.0.1'\n        commit: 'sha1-".self::SHA_A."'\n"],
            'ref twice' => [$head.$dep()."        ref: 'v7.0.2'\n"],
            'duplicate dependency' => [$head.$dep().$dep()],
            'spec without a ref' => [$head."    'actions/checkout':\n        ref: 'v1'\n        commit: 'sha1-".self::SHA_A."'\n        owner_id: 1\n        repo_id: 2\n"],
            'spec with a space' => [$head.$dep('actions/check out@v1')],
            'spec with traversal' => [$head.$dep('actions/../etc@v1')],
            'spec with a shell metacharacter' => [$head.$dep('actions/checkout;rm@v1')],
            'spec with a url' => [$head.$dep('https://github.com/actions/checkout@v1')],
            'owner starting with a dash' => [$head.$dep('-actions/checkout@v1')],
            'ref with a space' => [$head.$dep('actions/checkout@v1', 'v 1')],
            'component key too long for its column' => [$head.$dep('acme/tool/'.str_repeat('a', 100).'/'.str_repeat('b', 100).'/'.str_repeat('c', 50).'@v1', 'v1', self::SHA_B)],
            'workflow names an action that dependencies do not describe' => [str_replace("- 'actions/checkout@v7.0.1'", "- 'acme/missing@v1'", $head).$dep()],
            'version after the sections' => [str_replace("version: 'v0.0.3'
", '', $head).$dep()."version: 'v0.0.3'
"],
            'over-long line' => [$head.str_repeat('#', ActionsLockParser::MAX_LINE_LENGTH + 1)."\n".$dep()],
            'dependency without any field before the next' => [$head."    'actions/checkout@v7.0.1':\n".$dep('actions/setup-node@v1', 'v1', self::SHA_B)],
        ];
    }

    #[DataProvider('rejectedFiles')]
    public function test_a_malformed_or_unexpected_lock_file_is_rejected_as_a_whole(string $content): void
    {
        $this->assertNull(ActionsLockParser::parse($content));

        $this->enable();
        $this->writeLock($content);
        $components = $this->actions();

        // Never a partial or an empty list: exactly one UNKNOWN placeholder, no version, no advisory source.
        $this->assertCount(1, $components);
        $this->assertSame('github-action:lock', $components[0]->key);
        $this->assertNull($components[0]->version);
        $this->assertNull($components[0]->ecosystem);
    }

    public function test_a_missing_or_unsafe_lock_file_is_one_unknown_placeholder(): void
    {
        $this->enable();

        unlink($this->dir.'/.github/workflows/actions.lock');
        $this->assertSame(['github-action:lock'], array_map(fn (DetectedComponent $c) => $c->key, $this->actions()));

        $this->writeLock($this->validLock());
        mkdir($this->dir.'/elsewhere');
        file_put_contents($this->dir.'/elsewhere/target.lock', $this->validLock());
        unlink($this->dir.'/.github/workflows/actions.lock');
        if (@symlink($this->dir.'/elsewhere/target.lock', $this->dir.'/.github/workflows/actions.lock')) {
            $this->assertSame(['github-action:lock'], array_map(fn (DetectedComponent $c) => $c->key, $this->actions()), 'a symlinked lock file is never followed');
        }
    }

    public function test_size_line_and_component_limits_reject_the_whole_file(): void
    {
        $this->enable();

        $max = [];
        for ($i = 0; $i < ActionsLockParser::MAX_ENTRIES; $i++) {
            $max[] = $this->dep('acme/tool-'.$i.'@v1.0.0', 'v1.0.0', str_pad(dechex($i), 40, '0', STR_PAD_LEFT));
        }
        $this->writeLock($this->lock($max));
        $this->assertCount(ActionsLockParser::MAX_ENTRIES, $this->actions(), 'the limit itself is allowed');

        $max[] = $this->dep('acme/one-too-many@v1.0.0', 'v1.0.0', str_pad('ff', 40, '0', STR_PAD_LEFT));
        $this->writeLock($this->lock($max));
        $this->assertSame(['github-action:lock'], array_map(fn (DetectedComponent $c) => $c->key, $this->actions()), 'one over the entry limit');

        $this->writeLock($this->validLock().str_repeat("# padding\n", 7000));   // above 64 KiB and above the line limit
        $this->assertSame(['github-action:lock'], array_map(fn (DetectedComponent $c) => $c->key, $this->actions()), 'oversized file');

        $this->writeLock($this->validLock().str_repeat("\n", ActionsLockParser::MAX_LINES));
        $this->assertNull(ActionsLockParser::parse((string) file_get_contents($this->dir.'/.github/workflows/actions.lock')), 'too many lines');
    }

    private function fakeOsv(): void
    {
        Http::swap(new Factory);
        Http::fake([
            'api.osv.dev/v1/querybatch' => fn ($request) => Http::response(['results' => array_map(fn () => (object) [], $request['queries'])], 200),
            'api.osv.dev/v1/vulns/*' => Http::response([], 404),
        ]);
    }

    public function test_actions_are_stored_as_unverified_and_never_sent_to_the_advisory_source(): void
    {
        $this->enable();
        $this->fakeOsv();

        $check = app(PlatformUpdateScanner::class)->run('manual');

        $this->assertSame('succeeded', $check->status);
        $rows = PlatformComponent::query()->where('kind', 'github-action')->orderBy('component_key')->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame('UNVERIFIED', $row->advisory_state);
            $this->assertNull($row->ecosystem);
            $this->assertNull($row->advisories_checked_at, 'never "checked"');
            $this->assertSame('VERIFIED', $row->version_state, 'a version read from the lock is a fact; it says nothing about advisories');
        }
        $this->assertSame(0, PlatformAdvisory::query()->count());
        // Nothing about an action reached the outside: the batch carries only the checkable Composer package.
        Http::assertSent(function ($request) {
            $names = array_map(fn ($q) => $q['package']['name'], $request['queries'] ?? []);

            return str_contains($request->url(), 'querybatch') && $names === ['acme/fine']
                && ! str_contains(json_encode($request->data()), 'GitHub Actions') && ! str_contains(json_encode($request->data()), 'actions/');
        });
        Http::assertSentCount(1);
    }

    public function test_a_second_run_keeps_them_unverified_and_the_other_components_unchanged(): void
    {
        $this->fakeOsv();
        app(PlatformUpdateScanner::class)->run('manual');   // switch off
        $withoutActions = PlatformComponent::query()->where('kind', '!=', 'github-action')->orderBy('component_key')->get(['component_key', 'advisory_state', 'version_state', 'installed_version'])->toArray();
        $this->assertSame(0, PlatformComponent::query()->where('kind', 'github-action')->count());

        $this->enable();
        app(PlatformUpdateScanner::class)->run('manual');
        app(PlatformUpdateScanner::class)->run('manual');

        $this->assertSame($withoutActions, PlatformComponent::query()->where('kind', '!=', 'github-action')->orderBy('component_key')->get(['component_key', 'advisory_state', 'version_state', 'installed_version'])->toArray());
        $this->assertSame(['UNVERIFIED'], PlatformComponent::query()->where('kind', 'github-action')->pluck('advisory_state')->unique()->values()->all());
        $this->assertSame('CHECKED', PlatformComponent::query()->where('component_key', 'composer:acme/fine')->value('advisory_state'));
    }

    public function test_switching_the_inventory_off_stops_reporting_the_actions(): void
    {
        $this->enable();
        $this->fakeOsv();
        app(PlatformUpdateScanner::class)->run('manual');
        $this->assertSame(3, PlatformComponent::query()->where('kind', 'github-action')->where('present', true)->count());

        config(['platform_updates.github_actions_inventory' => false]);
        app(PlatformUpdateScanner::class)->run('manual');

        $this->assertSame(0, PlatformComponent::query()->where('kind', 'github-action')->where('present', true)->count());
    }

    public function test_the_page_lists_them_as_unverified_and_counts_them_in_the_unverified_note(): void
    {
        $this->enable();
        $this->fakeOsv();
        app(PlatformUpdateScanner::class)->run('manual');
        $this->asOwner();

        $page = $this->get(route('owner.platform-updates.index', ['kind' => 'github-action']))->assertOk();
        $page->assertSee('github-action:actions/checkout@'.self::SHA_A, false)->assertSee('No advisory source')
            ->assertSee('7.0.1')->assertSee('actions/setup-node');
        $page->assertDontSee('composer:acme/fine', false);
        $summary = (new PlatformUpdateOverview)->summary();
        // 3 actions + PHP, OpenSSL, database, OS, connector.
        $this->assertSame(8, $summary['unverified']);
        $this->get(route('owner.platform-updates.index'))->assertOk()->assertSee('GitHub Actions');
    }

    public function test_a_lock_with_only_branch_refs_is_still_a_readable_lock_and_old_rows_do_not_linger(): void
    {
        $this->enable();
        $this->fakeOsv();
        $this->writeLock('not a lock file');
        app(PlatformUpdateScanner::class)->run('manual');
        $this->assertTrue((bool) PlatformComponent::query()->where('component_key', 'github-action:lock')->value('present'));

        // The file recovers; none of its refs is a version, so no component has one.
        $this->writeLock($this->lock([$this->dep('acme/tool@main', 'main', self::SHA_C)]));
        app(PlatformUpdateScanner::class)->run('manual');
        $this->assertFalse((bool) PlatformComponent::query()->where('component_key', 'github-action:lock')->value('present'), 'the placeholder does not linger');
        $this->assertSame(['github-action:acme/tool@'.self::SHA_C], PlatformComponent::query()->where('kind', 'github-action')->where('present', true)->pluck('component_key')->all());

        // A different action replaces it: the old row is no longer reported although it never had a version.
        $this->writeLock($this->lock([$this->dep('acme/other@main', 'main', self::SHA_B)]));
        app(PlatformUpdateScanner::class)->run('manual');
        $this->assertSame(['github-action:acme/other@'.self::SHA_B], PlatformComponent::query()->where('kind', 'github-action')->where('present', true)->pluck('component_key')->all());
        $this->assertSame(['UNKNOWN'], PlatformComponent::query()->where('kind', 'github-action')->where('present', true)->pluck('version_state')->unique()->values()->all());
        $this->assertSame(['UNVERIFIED'], PlatformComponent::query()->where('kind', 'github-action')->pluck('advisory_state')->unique()->values()->all());
    }

    public function test_missing_advisory_evidence_never_becomes_a_healthy_result(): void
    {
        $this->enable();
        $this->fakeOsv();
        $this->writeLock('not a lock file');
        app(PlatformUpdateScanner::class)->run('manual');

        // The unreadable lock is an UNKNOWN placeholder: the overall state cannot be healthy, and no row is CHECKED.
        $placeholder = PlatformComponent::query()->where('component_key', 'github-action:lock')->firstOrFail();
        $this->assertSame('UNKNOWN', $placeholder->version_state);
        $this->assertSame('UNVERIFIED', $placeholder->advisory_state);
        $summary = (new PlatformUpdateOverview)->summary();
        $this->assertGreaterThan(0, $summary['unknown']);
        $this->assertNotSame('healthy', $summary['state']->value);
        $this->assertSame(0, PlatformComponent::query()->where('kind', 'github-action')->where('advisory_state', 'CHECKED')->count());
        $this->assertSame(0, PlatformAdvisory::query()->count());
    }
}
