<?php

namespace Tests\Feature\Platform;

use App\Jobs\RunPlatformUpdateCheck;
use App\Models\Customer;
use App\Models\PlatformAdvisory;
use App\Models\PlatformComponent;
use App\Models\PlatformUpdateCheck;
use App\Models\User;
use App\Platform\Updates\PlatformUpdateOverview;
use App\Platform\Updates\PlatformUpdateScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

/** Phase C: System → Platform Updates (Owner only; shows stored results; the only action is a read-only check). */
class PlatformUpdatesPageTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    private function makeComponent(string $key = 'composer:acme/vulnerable', string $version = '1.2.0', array $extra = []): PlatformComponent
    {
        return PlatformComponent::create($extra + [
            'component_key' => $key, 'kind' => 'composer', 'name' => substr($key, strpos($key, ':') + 1), 'ecosystem' => 'Packagist',
            'installed_version' => $version, 'version_state' => 'VERIFIED', 'advisory_state' => 'CHECKED', 'evidence_source' => 'composer.lock',
            'present' => true, 'last_seen_at' => now(), 'advisories_checked_at' => now(),
        ]);
    }

    private function makeAdvisory(PlatformComponent $component, array $extra = []): PlatformAdvisory
    {
        return PlatformAdvisory::create($extra + [
            'platform_component_id' => $component->id, 'advisory_id' => 'GHSA-aaaa-bbbb-cccc', 'aliases' => ['CVE-2026-0001'], 'severity' => 'critical',
            'summary' => 'Remote code execution', 'affected_version' => $component->installed_version, 'fixed_version' => '1.2.5',
            'published_at' => now()->subDay(), 'source' => 'osv', 'source_url' => 'https://osv.dev/vulnerability/GHSA-aaaa-bbbb-cccc', 'state' => 'open',
        ]);
    }

    private function makeCheck(string $status, array $extra = []): PlatformUpdateCheck
    {
        return PlatformUpdateCheck::create($extra + ['uuid' => (string) Str::uuid(), 'trigger' => 'scheduled', 'status' => $status, 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4)]);
    }

    public function test_before_any_check_the_state_is_unknown_never_healthy(): void
    {
        $this->asOwner();

        $this->get(route('owner.platform-updates.index'))->assertOk()
            ->assertSeeInOrder(['Platform status', 'Unknown'])->assertSee('never')->assertDontSee('No known advisories', false)
            ->assertSee('Scheduled checks are off');
    }

    public function test_a_clean_successful_check_is_healthy_but_unverified_components_stay_visible(): void
    {
        $this->asOwner();
        $this->makeComponent('composer:acme/fine', '2.0.0');
        $this->makeComponent('runtime:php', '8.4.1', ['kind' => 'runtime', 'ecosystem' => null, 'advisory_state' => 'UNVERIFIED', 'advisories_checked_at' => null]);
        $this->makeCheck('succeeded');

        $this->get(route('owner.platform-updates.index'))->assertOk()->assertSee('No known advisories in the checked components')
            ->assertSee('1 components have no advisory source yet', false)->assertSee('Unverified')
            ->assertSee('No open advisories in the checked components.');
    }

    public function test_a_critical_advisory_makes_the_platform_critical_and_is_listed_with_its_details(): void
    {
        $this->asOwner();
        $advisory = $this->makeAdvisory($this->makeComponent());
        $this->makeCheck('succeeded');

        $page = $this->get(route('owner.platform-updates.index'))->assertOk();
        $page->assertSee('Critical')->assertSee('GHSA-aaaa-bbbb-cccc')->assertSee('acme/vulnerable 1.2.0')->assertSee('→ 1.2.5');
        $this->assertStringContainsString('data-tile="critical"', $page->getContent());

        $this->get(route('owner.platform-updates.advisory', $advisory->id))->assertOk()
            ->assertSee('CVE-2026-0001')->assertSee('https://osv.dev/vulnerability/GHSA-aaaa-bbbb-cccc')->assertSee('Remote code execution')
            ->assertSee('Plan an update of acme/vulnerable to 1.2.5 or later. Test it on staging first; nothing is updated automatically.');
    }

    public function test_a_development_only_advisory_is_not_counted_or_listed_as_open(): void
    {
        $this->asOwner();
        $this->makeAdvisory($this->makeComponent('composer:acme/dev', '3.1.0', ['development_only' => true]));
        $this->makeCheck('succeeded');

        $this->get(route('owner.platform-updates.index'))->assertOk()->assertSee('No known advisories in the checked components')->assertSee('No open advisories in the checked components.')->assertDontSee('data-advisory', false);
    }

    public function test_without_a_proven_fix_no_fixed_version_is_invented(): void
    {
        $this->asOwner();
        $advisory = $this->makeAdvisory($this->makeComponent(), ['fixed_version' => null, 'severity' => 'unknown']);

        $this->get(route('owner.platform-updates.advisory', $advisory->id))->assertOk()
            ->assertSee('does not state a severity or fix')->assertSee('none')->assertDontSee('or later');
    }

    public function test_a_fix_the_source_does_not_name_says_so_and_shows_the_installed_version(): void
    {
        $this->asOwner();
        $advisory = $this->makeAdvisory($this->makeComponent(), ['fixed_version' => null, 'severity' => 'high']);

        $this->get(route('owner.platform-updates.advisory', $advisory->id))->assertOk()
            ->assertSee('The source names no fixed version')->assertSee('none')
            ->assertDontSee('No fix is confirmed for the installed version')->assertDontSee('not confirmed');
    }

    public function test_a_fix_cleared_because_the_installed_version_changed_is_not_confirmed_never_none_and_the_real_version_is_shown(): void
    {
        $this->asOwner();
        // The component was changed to 1.2.1; the advisory text (read for 1.2.0) could not be read again, so its fix was cleared.
        $component = $this->makeComponent('composer:acme/vulnerable', '1.2.1');
        $advisory = $this->makeAdvisory($component, ['affected_version' => '1.2.0', 'fixed_version' => null, 'severity' => 'critical']);

        $detail = $this->get(route('owner.platform-updates.advisory', $advisory->id))->assertOk();
        $detail->assertSee('No fix is confirmed for the installed version')->assertSee('not confirmed')
            ->assertDontSee('The source names no fixed version')->assertDontSee('or later')
            ->assertSee('1.2.1')->assertDontSee('1.2.0');

        $this->get(route('owner.platform-updates.index'))->assertOk()->assertSee('acme/vulnerable 1.2.1')->assertDontSee('acme/vulnerable 1.2.0');
    }

    public function test_a_stored_fix_proven_for_another_version_is_not_advice_for_the_installed_one(): void
    {
        $this->asOwner();
        // The inventory moved to 1.2.1 but the source was unreachable since: the row still carries the fix proven for 1.2.0.
        $component = $this->makeComponent('composer:acme/vulnerable', '1.2.1');
        $advisory = $this->makeAdvisory($component, ['affected_version' => '1.2.0', 'fixed_version' => '1.2.5', 'severity' => 'critical']);
        $this->makeCheck('failed');

        $detail = $this->get(route('owner.platform-updates.advisory', $advisory->id))->assertOk();
        $detail->assertSee('No fix is confirmed for the installed version')->assertSee('not confirmed')
            ->assertDontSee('Plan an update')->assertDontSee('1.2.5')->assertDontSee('or later');

        $index = $this->get(route('owner.platform-updates.index'))->assertOk();
        $index->assertDontSee('1.2.5')->assertSee('acme/vulnerable 1.2.1');
        $this->assertSame(0, (new PlatformUpdateOverview)->summary()['fixes_available'], 'an unconfirmed fix is not counted as available');

        // Once the text was read again for 1.2.1 the fix is proven for it and is shown and counted.
        $advisory->forceFill(['affected_version' => '1.2.1', 'fixed_version' => '1.2.5'])->save();
        $this->get(route('owner.platform-updates.advisory', $advisory->id))->assertOk()->assertSee('Plan an update')->assertSee('1.2.5');
        $this->assertSame(1, (new PlatformUpdateOverview)->summary()['fixes_available']);
    }

    public function test_unknown_versions_unchecked_components_and_a_stale_success_are_never_healthy(): void
    {
        $this->asOwner();
        $this->makeComponent('composer:acme/fine', '2.0.0');
        $this->makeCheck('succeeded');
        $this->get(route('owner.platform-updates.index'))->assertSee('No known advisories in the checked components');

        $this->makeComponent('composer:acme/noversion', '0', ['installed_version' => null, 'version_state' => 'UNKNOWN', 'advisory_state' => 'UNKNOWN']);
        $this->get(route('owner.platform-updates.index'))->assertDontSee('No known advisories')->assertSee('Attention');
        PlatformComponent::where('component_key', 'composer:acme/noversion')->delete();

        $this->makeComponent('composer:acme/unchecked', '1.0.0', ['advisory_state' => 'UNKNOWN']);
        $this->get(route('owner.platform-updates.index'))->assertDontSee('No known advisories')->assertSee('Attention');
        PlatformComponent::where('component_key', 'composer:acme/unchecked')->delete();

        PlatformUpdateCheck::query()->update(['finished_at' => now()->subDays(3), 'started_at' => now()->subDays(3)]);
        $this->get(route('owner.platform-updates.index'))->assertDontSee('No known advisories')->assertSee('more than two days old');
    }

    public function test_the_component_table_does_not_count_development_only_advisories(): void
    {
        $this->asOwner();
        $this->makeAdvisory($this->makeComponent('composer:acme/dev', '3.1.0', ['development_only' => true]));
        $this->makeCheck('succeeded');

        $html = $this->get(route('owner.platform-updates.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#data-component="composer:acme/dev".*?tabular-nums">0</td>#s', $html);
    }

    public function test_a_failed_last_check_is_never_shown_as_healthy(): void
    {
        $this->asOwner();
        $this->makeCheck('succeeded', ['started_at' => now()->subDay(), 'finished_at' => now()->subDay()]);
        $this->makeCheck('failed', ['reason_code' => 'osv_unreachable']);

        $this->get(route('owner.platform-updates.index'))->assertOk()->assertDontSee('No known advisories')->assertSee('osv_unreachable')->assertSee('Failed');
    }

    public function test_stored_source_text_is_escaped(): void
    {
        $this->asOwner();
        $advisory = $this->makeAdvisory($this->makeComponent(), ['summary' => '<script>alert(1)</script> & "x"']);

        foreach ([route('owner.platform-updates.index'), route('owner.platform-updates.advisory', $advisory->id)] as $url) {
            $this->get($url)->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        }
    }

    public function test_the_page_is_usable_on_a_phone_and_offers_exactly_one_action(): void
    {
        $this->asOwner();
        $this->makeComponent();

        $html = $this->get(route('owner.platform-updates.index'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'overflow-x-auto'), 'both tables scroll inside their own box');
        $this->assertStringContainsString('grid-cols-2', $html);
        $this->assertStringContainsString('name="viewport"', $html);
        $forms = [];
        preg_match_all('/<form[^>]*action="([^"]+)"/', $html, $forms);
        $this->assertSame([route('owner.platform-updates.check')], array_values(array_filter($forms[1], fn ($u) => str_contains($u, 'platform-updates'))));
        foreach (['composer update', 'apt upgrade', 'apt-get', 'npm update'] as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }
    }

    public function test_the_component_list_filters_by_kind_and_ignores_other_values(): void
    {
        $this->asOwner();
        $this->makeComponent();
        $this->makeComponent('runtime:php', '8.4.1', ['kind' => 'runtime']);

        $this->get(route('owner.platform-updates.index', ['kind' => 'runtime']))->assertSee('data-component="runtime:php"', false)->assertDontSee('data-component="composer:acme/vulnerable"', false);
        $this->get(route('owner.platform-updates.index', ['kind' => "x' or 1=1"]))->assertOk()->assertSee('data-component="composer:acme/vulnerable"', false);
    }

    public function test_the_manual_check_only_queues_one_read_only_job(): void
    {
        Queue::fake();
        config(['platform_updates.enabled' => true]);
        $this->asOwner();

        $this->post(route('owner.platform-updates.check'))->assertRedirect()->assertSessionHas('status');

        Queue::assertPushed(RunPlatformUpdateCheck::class, 1);
    }

    public function test_the_manual_check_is_refused_when_disabled_or_on_closed_staging(): void
    {
        Queue::fake();
        $this->asOwner();

        config(['platform_updates.enabled' => false]);
        $this->post(route('owner.platform-updates.check'))->assertRedirect()->assertSessionHas('owner_error');

        config(['platform_updates.enabled' => true, 'app.deployment_environment' => 'staging_next', 'marketing_release.staging_active_features.external_services' => false]);
        $this->post(route('owner.platform-updates.check'))->assertRedirect()->assertSessionHas('owner_error');

        Queue::assertNothingPushed();
    }

    public function test_the_job_runs_a_check_only_when_enabled_and_never_executes_anything(): void
    {
        Http::fake();
        config(['platform_updates.enabled' => false]);
        (new RunPlatformUpdateCheck)->handle(app(PlatformUpdateScanner::class));
        Http::assertNothingSent();
        $this->assertSame(0, PlatformUpdateCheck::count());
    }

    public function test_only_an_owner_can_see_or_trigger_anything(): void
    {
        Queue::fake();
        config(['platform_updates.enabled' => true]);
        $advisory = $this->makeAdvisory($this->makeComponent());
        $urls = [
            ['get', route('owner.platform-updates.index')],
            ['get', route('owner.platform-updates.advisory', $advisory->id)],
            ['post', route('owner.platform-updates.check')],
        ];

        foreach ($urls as [$method, $url]) {   // anonymous
            $this->{$method}($url)->assertRedirect(route('owner.login'));
        }
        $customer = Customer::factory()->withUser()->create()->user;
        $operator = User::factory()->operator()->create();
        foreach ([[$customer, 'web'], [$operator, 'operator']] as [$user, $guard]) {
            foreach ($urls as [$method, $url]) {
                $this->actingAs($user, $guard);
                $response = $this->{$method}($url);
                $this->assertTrue($response->isRedirect() || $response->status() === 403, "$guard $method $url");
                $this->assertNotSame(200, $response->status());
            }
        }
        Queue::assertNothingPushed();
    }

    public function test_the_next_scheduled_check_is_computed_in_utc(): void
    {
        $overview = new PlatformUpdateOverview;

        $this->assertSame('2026-11-10 05:20:00', $overview->nextScheduled(Carbon::parse('2026-11-10 04:00:00', 'UTC'))->toDateTimeString());
        $this->assertSame('2026-11-11 05:20:00', $overview->nextScheduled(Carbon::parse('2026-11-10 06:00:00', 'UTC'))->toDateTimeString());
    }
}
