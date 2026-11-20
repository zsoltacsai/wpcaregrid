<?php

namespace Tests\Feature\Platform;

use App\Jobs\RunPlatformUpdateCheck;
use App\Mail\PlatformUpdateNotice;
use App\Models\PlatformAdvisory;
use App\Models\PlatformComponent;
use App\Models\PlatformUpdateCheck;
use App\Models\User;
use App\Platform\Updates\PlatformUpdateGate;
use App\Platform\Updates\PlatformUpdateNotifier;
use App\Platform\Updates\PlatformUpdateScanner;
use App\Release\StagingActiveProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsOwner;
use Tests\Concerns\ProvesStagingNext;
use Tests\TestCase;

/**
 * One environment decision for every entry point of Platform Update Intelligence (scheduler, command, queued job, Owner button,
 * scanner, notifier). Each of them used to ask its own, narrower question: a stack identified only by its host, only by a requested
 * profile, or claiming to be staging-next without proof could still reach the advisory source or send mail through some of them.
 */
class PlatformUpdatesStagingGateTest extends TestCase
{
    use ActsAsOwner, ProvesStagingNext, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform_updates.enabled' => true, 'platform_updates.min_interval_seconds' => 0, 'mail.default' => 'log']);
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake(['api.osv.dev/*' => Http::response(['results' => []], 200)]);
    }

    /**
     * Stacks that must be held although the Owner opened external services and the mail is a sink: nothing here proves staging-next,
     * but each one says (by id, by profile request or by origin) that it is or wants to be.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function heldStacks(): array
    {
        $open = ['marketing_release.staging_active_features.platform_updates_outbound' => true, 'marketing_release.staging_mail_allowlist' => ['@example.test']];

        return [
            'identified only by its host' => [$open + ['app.deployment_environment' => 'production', 'app.url' => 'https://staging-next.wpcaregrid.com']],
            'only the staging_active profile was requested' => [$open + ['app.deployment_environment' => 'testing', 'marketing_release.requested_profile' => 'staging_active']],
            'claims the staging-next id without the proof' => [$open + ['app.deployment_environment' => 'staging_next']],
        ];
    }

    #[DataProvider('heldStacks')]
    public function test_a_stack_that_is_not_proven_staging_next_reaches_nothing_through_any_entry_point(array $config): void
    {
        config($config);
        $this->makeAdvisory();
        $this->asOwner();
        Queue::fake();

        // scanner
        $check = app(PlatformUpdateScanner::class)->run('manual');
        $this->assertSame(['refused', 'staging_environment_unproven'], [$check->status, $check->reason_code]);

        // artisan command
        $this->artisan('platform:updates:check')->assertFailed();

        // the queued job (a job that was queued before the stack changed)
        (new RunPlatformUpdateCheck)->handle(app(PlatformUpdateScanner::class), app(PlatformUpdateNotifier::class));

        // the Owner's button
        $this->post(route('owner.platform-updates.check'))->assertRedirect()->assertSessionHas('owner_error');
        Queue::assertNothingPushed();

        Http::assertNothingSent();
        $this->assertSame(0, PlatformUpdateCheck::query()->where('status', '!=', 'refused')->count(), 'no check ran');

        // notifications: not even a sink-confined mail leaves a stack that is not proven
        $notifier = app(PlatformUpdateNotifier::class);
        $notifier->afterCheck($this->finishedCheck('succeeded'));
        $notifier->afterCheck($this->finishedCheck('failed'));
        $this->assertFalse($notifier->digest());
        Mail::assertNothingSent();
    }

    public function test_the_gate_codes_and_the_unchanged_behaviour_outside_staging(): void
    {
        // not staging-next at all (the local default): nothing changes
        $this->assertNull(PlatformUpdateGate::checkRefusal());
        $this->assertNull(PlatformUpdateGate::mailRefusal());
        $this->assertSame('checked', $this->runCheck());

        config(['platform_updates.enabled' => false]);
        $this->assertSame('disabled', PlatformUpdateGate::checkRefusal());
        $this->assertSame('disabled', PlatformUpdateGate::mailRefusal());
        foreach ([null, 1, 'true', 'yes', 'on', [], 0, ''] as $notStrictlyTrue) {
            config(['platform_updates.enabled' => $notStrictlyTrue]);
            $this->assertFalse(PlatformUpdateGate::enabled(), 'only boolean true enables: '.var_export($notStrictlyTrue, true));
            $this->assertSame('disabled', PlatformUpdateGate::checkRefusal());
        }
    }

    public function test_the_owner_button_names_the_reason_it_is_blocked(): void
    {
        Queue::fake();
        $this->asOwner();
        config(['app.deployment_environment' => 'staging_next', 'marketing_release.staging_active_features.external_services' => true]);   // the broad opt-in is not enough

        $this->post(route('owner.platform-updates.check'))->assertRedirect()->assertSessionHas('owner_error', 'Blocked: this staging stack has not been opened for the platform update check (STAGING_ACTIVE_PLATFORM_UPDATES_OUTBOUND).');

        config(['marketing_release.staging_active_features.platform_updates_outbound' => true]);   // opened, but the stack is not proven
        $this->post(route('owner.platform-updates.check'))->assertRedirect()->assertSessionHas('owner_error', 'Blocked: this staging stack is not proven to be the isolated staging environment.');
        Queue::assertNothingPushed();
    }

    public function test_a_proven_staging_next_follows_the_two_independent_opt_ins(): void
    {
        $this->provenStagingNext();

        // closed (the default): no check; a sink-confined mail is still allowed
        config(['mail.default' => 'log']);
        $this->assertSame('staging_platform_updates_closed', PlatformUpdateGate::checkRefusal());
        $this->assertNull(PlatformUpdateGate::mailRefusal());

        // the broad external-services opt-in does not open it, the dedicated one does
        config(['marketing_release.staging_active_features.external_services' => true]);
        $this->assertSame('staging_platform_updates_closed', PlatformUpdateGate::checkRefusal());
        config(['marketing_release.staging_active_features.external_services' => false, 'marketing_release.staging_active_features.platform_updates_outbound' => true]);
        $this->assertNull(PlatformUpdateGate::checkRefusal());
        $this->assertTrue(StagingActiveProfile::externalContactClosed(), 'everything else external stays closed');

        // mail needs a sink or an allowlist, never just the opt-in
        config(['mail.default' => 'smtp', 'marketing_release.staging_mail_allowlist' => []]);
        $this->assertSame('staging_mail_not_confined', PlatformUpdateGate::mailRefusal());
        $this->assertNull(PlatformUpdateGate::checkRefusal(), 'the check does not depend on mail');
        config(['marketing_release.staging_mail_allowlist' => ['@example.test']]);
        $this->assertNull(PlatformUpdateGate::mailRefusal());
        config(['mail.default' => 'array', 'marketing_release.staging_mail_allowlist' => []]);
        $this->assertNull(PlatformUpdateGate::mailRefusal());

        // the feature switch still comes first
        config(['platform_updates.enabled' => false]);
        $this->assertSame('disabled', PlatformUpdateGate::checkRefusal());
        $this->assertSame('disabled', PlatformUpdateGate::mailRefusal());
    }

    public function test_the_proof_is_read_again_on_every_call_so_a_long_running_worker_never_keeps_a_stale_yes(): void
    {
        $this->provenStagingNext();
        config(['marketing_release.staging_active_features.platform_updates_outbound' => true]);
        $this->assertNull(PlatformUpdateGate::checkRefusal());

        $this->stagingDatabaseProbe('wpcg_somewhere_else');   // the connection now is not the staging database

        $this->assertSame('staging_environment_unproven', PlatformUpdateGate::checkRefusal());
        $this->assertSame('staging_environment_unproven', PlatformUpdateGate::mailRefusal());

        $this->stagingDatabaseProbe(null);   // unreadable is not proven either
        $this->assertSame('staging_environment_unproven', PlatformUpdateGate::checkRefusal());
    }

    private function runCheck(): string
    {
        return app(PlatformUpdateScanner::class)->run('manual')->status === 'refused' ? 'refused' : 'checked';
    }

    private function makeAdvisory(): PlatformAdvisory
    {
        User::factory()->owner()->create(['email' => 'owner@example.test', 'email_verified_at' => now()]);
        $component = PlatformComponent::create([
            'component_key' => 'composer:acme/vulnerable', 'kind' => 'composer', 'name' => 'acme/vulnerable', 'ecosystem' => 'Packagist', 'installed_version' => '1.2.0',
            'version_state' => 'VERIFIED', 'advisory_state' => 'CHECKED', 'evidence_source' => 'composer.lock', 'present' => true,
        ]);

        return PlatformAdvisory::create([
            'platform_component_id' => $component->id, 'advisory_id' => 'GHSA-aaaa-bbbb-cccc', 'aliases' => [], 'severity' => 'critical', 'summary' => 'x',
            'affected_version' => '1.2.0', 'fixed_version' => '1.2.5', 'source' => 'osv', 'source_url' => 'https://osv.dev/vulnerability/GHSA-aaaa-bbbb-cccc', 'state' => 'open',
        ]);
    }

    private function finishedCheck(string $status): PlatformUpdateCheck
    {
        return PlatformUpdateCheck::create(['uuid' => (string) Str::uuid(), 'trigger' => 'scheduled', 'status' => $status, 'started_at' => now()->subMinute(), 'finished_at' => now()]);
    }

    public function test_outside_staging_the_notifier_still_sends(): void
    {
        $this->makeAdvisory();

        app(PlatformUpdateNotifier::class)->afterCheck($this->finishedCheck('succeeded'));

        Mail::assertSent(PlatformUpdateNotice::class, 1);
    }
}
