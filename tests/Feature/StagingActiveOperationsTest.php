<?php

namespace Tests\Feature;

use App\Connector\HealthStatus;
use App\Jobs\Monitoring\RunWebsiteProbe;
use App\Mail\StaffInvitation;
use App\Models\User;
use App\Models\Website;
use App\Monitoring\MonitoringEngine;
use App\Monitoring\WebsiteProbe;
use App\Owner\StatusCheck;
use App\Owner\SystemStatus;
use App\Pricing\StripeCatalogue\CatalogueGuard;
use App\Pricing\StripeCatalogue\CatalogueRefused;
use App\Pricing\StripeCatalogue\StripeMode;
use App\Release\StagingActiveProfile;
use App\Release\StagingDatabaseProbe;
use App\SyntheticFixtures\SyntheticFixturePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Operational side of staging_active: the existing Owner can be (re)invited without
 * creating or deleting anything, and system:check proves the isolation before workers
 * or the scheduler are started.
 */
class StagingActiveOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_existing_unactivated_owner_gets_a_new_invitation_without_a_second_account(): void
    {
        Mail::fake();

        $this->assertSame(0, Artisan::call('owner:user', ['email' => 'owner@wpcaregrid.example', '--name' => 'Owner']));
        $first = $this->linkToken(Artisan::output());
        $owner = User::where('email', 'owner@wpcaregrid.example')->sole();
        $id = $owner->id;

        $this->assertSame(0, Artisan::call('owner:user', ['email' => 'owner@wpcaregrid.example']));
        $second = $this->linkToken(Artisan::output());

        $this->assertNotSame($first, $second, 'a new, different single-use token is issued');
        $this->assertSame(1, User::where('role', User::ROLE_OWNER)->count());
        $this->assertSame($id, User::where('email', 'owner@wpcaregrid.example')->sole()->id, 'the same account is kept');
        $this->assertNull($owner->refresh()->email_verified_at, 'still waiting for the Owner to set a password');

        // The superseded token no longer works; the new one does, and the Owner chooses the password.
        $this->post(route('staff.activate.store'), $this->activation($first))->assertSessionHasErrors();
        $this->assertNull($owner->refresh()->email_verified_at);
        $this->post(route('staff.activate.store'), $this->activation($second))->assertRedirect(route('owner.login'));
        $this->assertNotNull($owner->refresh()->email_verified_at);
        $this->post(route('owner.login.store'), ['email' => 'owner@wpcaregrid.example', 'password' => 'chosen-by-owner-123'])
            ->assertRedirect(route('owner.dashboard'));
    }

    public function test_an_activated_owner_is_not_reinvited_and_not_deleted(): void
    {
        Mail::fake();
        Artisan::call('owner:user', ['email' => 'owner@wpcaregrid.example']);
        $token = $this->linkToken(Artisan::output());
        $this->post(route('staff.activate.store'), $this->activation($token))->assertRedirect(route('owner.login'));

        $this->assertSame(0, Artisan::call('owner:user', ['email' => 'owner@wpcaregrid.example']));
        $this->assertStringContainsString('already an active owner', Artisan::output());
        $this->assertSame(1, User::where('role', User::ROLE_OWNER)->count());
        Mail::assertQueued(StaffInvitation::class, 1);
    }

    public function test_the_isolation_check_is_listed_only_on_a_staging_next_deployment(): void
    {
        config(['app.deployment_environment' => 'production', 'marketing_release.requested_profile' => 'marketing_only', 'marketing_release.profile' => 'marketing_only']);
        $this->assertNotContains('staging_isolation', array_map(fn (StatusCheck $c) => $c->key, app(SystemStatus::class)->checks()));

        config(['app.deployment_environment' => 'staging_next']);
        $this->assertContains('staging_isolation', array_map(fn (StatusCheck $c) => $c->key, app(SystemStatus::class)->checks()));
    }

    public function test_the_isolation_check_is_healthy_only_when_everything_is_proven(): void
    {
        $this->provenStaging();
        $check = $this->isolation();
        $this->assertSame(HealthStatus::Healthy, $check->status);
        $this->assertSame('OK', $check->level());

        // Stripe not yet configured: the stack is isolated, Stripe stays closed (warning, not an error).
        config(['services.stripe.secret' => null]);
        $this->assertSame(HealthStatus::Attention, $this->isolation()->status);

        // Mail with a real transport and no allowlist: all mail is blocked (warning).
        $this->provenStaging();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp', 'marketing_release.staging_mail_allowlist' => []]);
        $this->assertSame(HealthStatus::Attention, $this->isolation()->status);
        config(['marketing_release.staging_mail_allowlist' => ['owner@example.test']]);
        $this->assertSame(HealthStatus::Healthy, $this->isolation()->status);
    }

    public function test_the_isolation_check_fails_hard_on_identity_database_or_live_stripe(): void
    {
        $cases = [
            'production url' => ['app.url' => 'https://wpcaregrid.com'],
            'not eligible' => ['marketing_release.staging_active_enabled' => false],
            'legacy database' => ['database.connections.mysql.database' => 'wpcaregrid'],
            'live stripe' => ['services.stripe.secret' => 'sk_live_super_secret_value'],
            'live publishable' => ['services.stripe.key' => 'pk_live_other_value'],
            'live mode allowed' => ['services.stripe.allow_live_mode' => true],
        ];
        foreach ($cases as $label => $override) {
            $this->provenStaging();
            config($override);
            $check = $this->isolation();
            $this->assertSame(HealthStatus::Critical, $check->status, $label);
            $this->assertSame('ERROR', $check->level(), $label);
        }

        $this->provenStaging();
        $this->probe('wpcaregrid');
        $this->assertSame('ERROR', $this->isolation()->level(), 'connected to the wrong live database');
    }

    public function test_the_isolation_check_shows_codes_but_never_values(): void
    {
        $this->provenStaging();
        config([
            'services.stripe.secret' => 'sk_live_super_secret_value',
            'marketing_release.staging_mail_allowlist' => ['private.person@example.com'],
            'database.connections.mysql.host' => 'db-secret-host.internal',
        ]);

        $json = json_encode($this->isolation());
        $this->assertStringNotContainsString('super_secret', $json);
        $this->assertStringNotContainsString('private.person', $json);
        $this->assertStringNotContainsString('db-secret-host', $json);
        $this->assertStringContainsString('stripe_secret_not_test', $json);
    }

    public function test_external_contact_is_closed_on_staging_next_until_the_owner_opens_it(): void
    {
        config(['app.deployment_environment' => 'production', 'marketing_release.requested_profile' => 'marketing_only', 'marketing_release.profile' => 'marketing_only']);
        $this->assertFalse(StagingActiveProfile::externalContactClosed(), 'other deployments are unchanged');

        config(['app.deployment_environment' => 'staging_next', 'marketing_release.staging_active_features' => ['connector_api' => false, 'external_services' => false]]);
        $this->assertTrue(StagingActiveProfile::externalContactClosed());

        config(['marketing_release.staging_active_features.external_services' => true]);
        $this->assertFalse(StagingActiveProfile::externalContactClosed());
    }

    public function test_the_system_check_does_not_contact_cloudflare_from_closed_staging_next(): void
    {
        config(['services.cloudflare.api_token' => 'token-not-used', 'app.deployment_environment' => 'staging_next',
            'marketing_release.staging_active_features' => ['connector_api' => false, 'external_services' => false]]);
        Http::fake();

        $check = collect(app(SystemStatus::class)->checks())->firstWhere('key', 'cloudflare');

        $this->assertSame('NOT CONFIGURED', $check->level());
        $this->assertStringNotContainsString('token-not-used', json_encode($check));
        Http::assertNothingSent();

        // Positive control: once the Owner opens external services the probe is attempted.
        config(['marketing_release.staging_active_features.external_services' => true]);
        collect(app(SystemStatus::class)->checks())->firstWhere('key', 'cloudflare');
        Http::assertSentCount(1);
    }

    public function test_an_unknown_or_incomplete_stripe_configuration_cannot_run_a_catalogue_operation(): void
    {
        config([
            'app.deployment_environment' => 'staging_next', 'app.env' => 'staging', 'app.url' => 'https://staging-next.wpcaregrid.com',
            'marketing_release.requested_profile' => 'staging_active', 'marketing_release.staging_active_enabled' => true,
            'services.stripe.allow_live_mode' => false, 'services.stripe.key' => null,
            'services.stripe.catalogue.apply_enabled' => false, 'services.stripe.catalogue.live_apply_approved' => false,
        ]);
        $profile = app(StagingActiveProfile::class);
        $writes = [['POST', '/owner/pricing/stripe-catalogue/preview'], ['POST', '/owner/pricing/stripe-catalogue/products/wordpress'], ['POST', '/owner/pricing/stripe-catalogue/1/apply']];

        // [secret, configured mode, reason the catalogue guard gives]. Both layers must refuse each one.
        $unproven = [
            'no key' => [null, 'test', 'stripe_not_configured'],
            'empty key' => ['', 'test', 'stripe_not_configured'],
            'unknown key' => ['not-a-stripe-key', 'test', 'key_mode_unknown'],
            'live key, test mode configured' => ['sk_live_unit', 'test', 'key_mode_mismatch'],
            'live key, live mode configured, on staging' => ['sk_live_unit', 'live', 'live_forbidden_on_staging'],
            'restricted live key on staging' => ['rk_live_unit', 'live', 'live_forbidden_on_staging'],
            'test key, live mode configured' => ['sk_test_unit', 'live', 'key_mode_mismatch'],
        ];
        foreach ($unproven as $label => [$secret, $mode, $reason]) {
            config(['services.stripe.secret' => $secret, 'services.stripe.mode' => $mode]);

            foreach ($writes as [$method, $path]) {
                $group = $profile->groupFor($path, $method);
                $this->assertSame(StagingActiveProfile::GROUP_STRIPE, $group, $path);
                $this->assertFalse($profile->groupOpen($group, $path), "{$label}: the staging gate must close {$method} {$path}");
            }
            try {
                app(CatalogueGuard::class)->mode();
                $this->fail("{$label}: the catalogue guard must refuse");
            } catch (CatalogueRefused $refused) {
                $this->assertSame($reason, $refused->reason, $label);
            }
        }

        // Proven test-mode keys open the route fence, but a change still needs the Owner's apply switch (off by default).
        config(['services.stripe.secret' => 'sk_test_unit', 'services.stripe.key' => 'pk_test_unit', 'services.stripe.mode' => 'test']);
        foreach ($writes as [$method, $path]) {
            $this->assertTrue($profile->groupOpen(StagingActiveProfile::GROUP_STRIPE, $path), "{$method} {$path}");
        }
        $this->assertSame(StripeMode::Test, app(CatalogueGuard::class)->mode());
        try {
            app(CatalogueGuard::class)->assertCanWrite(StripeMode::Test);
            $this->fail('changing the catalogue needs STRIPE_CATALOGUE_APPLY_ENABLED');
        } catch (CatalogueRefused $refused) {
            $this->assertSame('apply_disabled', $refused->reason);
        }
        // The pages that only read local data are not part of the Stripe group.
        $this->assertNull($profile->groupFor('/owner/pricing/stripe-catalogue', 'GET'));
        $this->assertNull($profile->groupFor('/owner/pricing/stripe-catalogue/1', 'GET'));
    }

    public function test_monitoring_does_not_probe_customer_websites_from_staging_next(): void
    {
        Queue::fake();
        User::factory()->create(['role' => User::ROLE_OWNER, 'email_verified_at' => now()]);
        Website::factory()->create();
        config(['app.deployment_environment' => 'staging_next', 'marketing_release.staging_active_features' => ['connector_api' => false, 'external_services' => false]]);

        $this->artisan('monitoring:run')->assertSuccessful();
        Queue::assertNotPushed(RunWebsiteProbe::class);

        // The Owner opens external services: ordinary dispatch resumes.
        config(['marketing_release.staging_active_features.external_services' => true]);
        $this->artisan('monitoring:run')->assertSuccessful();
        Queue::assertPushed(RunWebsiteProbe::class);
    }

    public function test_a_probe_job_that_is_already_queued_makes_no_request_from_staging_next(): void
    {
        Http::fake();
        $website = Website::factory()->create();
        config(['app.deployment_environment' => 'staging_next', 'marketing_release.staging_active_features' => ['connector_api' => false, 'external_services' => false]]);

        (new RunWebsiteProbe($website->id))->handle(
            app(WebsiteProbe::class),
            app(MonitoringEngine::class),
            app(SyntheticFixturePolicy::class),
        );

        Http::assertNothingSent();
    }

    // ---- helpers ----

    private function provenStaging(): void
    {
        config([
            'marketing_release.profile' => 'staging_active',
            'marketing_release.requested_profile' => 'staging_active',
            'marketing_release.staging_active_enabled' => true,
            'marketing_release.enforcement_mode' => 'enforced',
            'marketing_release.staging_active_features' => ['connector_api' => false, 'external_services' => false],
            'marketing_release.staging_active_db_uuid_sha256' => null,
            'marketing_release.staging_mail_allowlist' => [],
            'app.env' => 'staging',
            'app.deployment_environment' => 'staging_next',
            'app.url' => 'https://staging-next.wpcaregrid.com',
            'app.debug' => false,
            'database.connections.mysql.database' => 'wpcg_staging_next',
            'database.connections.mysql.host' => '127.0.0.1',
            'services.stripe.allow_live_mode' => false,
            'services.stripe.secret' => 'sk_test_unit',
            'services.stripe.key' => 'pk_test_unit',
            'services.stripe.webhook_secret' => 'whsec_unit',
            'mail.default' => 'array',
        ]);
        $this->probe('wpcg_staging_next');
    }

    private function isolation(): StatusCheck
    {
        $status = app(SystemStatus::class);
        $method = new ReflectionMethod($status, 'stagingIsolation');

        // The test database is SQLite. The identity check reads the configured connection
        // name, so it is switched only around this call (which itself never queries).
        $original = config('database.default');
        config(['database.default' => 'mysql']);
        try {
            return $method->invoke($status);
        } finally {
            config(['database.default' => $original]);
        }
    }

    private function probe(?string $database): void
    {
        $this->app->instance(StagingDatabaseProbe::class, new class($database) implements StagingDatabaseProbe
        {
            public function __construct(private readonly ?string $database) {}

            public function currentDatabase(): ?string
            {
                return $this->database;
            }

            public function serverUuidSha256(): ?string
            {
                return null;
            }
        });
        $this->app->forgetInstance(StagingActiveProfile::class);
    }

    private function linkToken(string $output): string
    {
        $link = trim(collect(explode("\n", $output))->first(fn (string $line) => str_contains($line, '/staff/activate/')));

        return basename(parse_url($link, PHP_URL_PATH));
    }

    /** @return array<string, string> */
    private function activation(string $token): array
    {
        return [
            'token' => $token,
            'email' => 'owner@wpcaregrid.example',
            'password' => 'chosen-by-owner-123',
            'password_confirmation' => 'chosen-by-owner-123',
        ];
    }
}
