<?php

namespace Tests\Feature\SyntheticFixtures;

use App\Backups\BackupReason;
use App\Backups\BackupRefused;
use App\Backups\BackupService;
use App\Backups\BackupType;
use App\Connector\ConnectorError;
use App\Connector\JobQueue;
use App\Connector\JobType;
use App\Connector\PairingCodes;
use App\Models\BillingInvoice;
use App\Models\CheckoutIntent;
use App\Models\Customer;
use App\Models\CustomerBillingIdentity;
use App\Models\M14SecurityScanWindow;
use App\Models\StripeEvent;
use App\Models\Subscription;
use App\Models\SyntheticSiteFixture;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteInventory;
use App\Security\Confidence;
use App\Security\FindingRecorder;
use App\Security\FindingType;
use App\Security\IncidentService;
use App\Security\ReportedFinding;
use App\Security\ScanType;
use App\Security\SecurityScanService;
use App\Security\Severity;
use App\SyntheticFixtures\M14SecurityHost;
use App\SyntheticFixtures\M14SecurityScanWindowService;
use App\SyntheticFixtures\StagingIdentity;
use App\SyntheticFixtures\SyntheticFixturePolicy;
use App\SyntheticFixtures\SyntheticFixtureProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ActsAsOwner;
use Tests\Concerns\PairsConnectors;
use Tests\TestCase;

final class M14SecurityFixtureTest extends TestCase
{
    use ActsAsOwner, PairsConnectors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.env' => 'production',
            'app.url' => 'https://staging.wpcaregrid.com',
            'app.deployment_environment' => 'staging',
            'app.deployment_hostname' => 'stg1.wpcaregrid.com',
            'synthetic_fixtures.provisioning_enabled' => true,
            'm14_security_fixture.provisioning_enabled' => false,
            'm14_security_fixture.fixture_enabled' => false,
            'm14_security_fixture.controlled_polling_enabled' => false,
            'm14_security_fixture.security_scan_enabled' => false,
            'm14_security_fixture.side_effect_isolation_enabled' => false,
            'm14_security_fixture.site_uuid' => null,
            'm14_security_fixture.customer_uuid' => null,
            'm14_security_fixture.connector_uuid' => null,
            'm14_security_fixture.site_host' => 'm14-security-fixture.staging.wpcaregrid.com',
            'platform.recovery_mode' => false,
        ]);
        $this->assertTrue(app(StagingIdentity::class)->allowsCurrentDeployment());
    }

    public function test_m14_fixture_is_separate_expiring_non_billing_and_has_no_backup_entitlement(): void
    {
        Mail::fake();
        Notification::fake();
        Queue::fake();
        config(['m14_security_fixture.provisioning_enabled' => true]);

        $result = app(SyntheticFixtureProvisioner::class)->provisionM14SecurityFixture();
        $customer = $result['customer'];
        $website = $result['website'];
        $fixture = SyntheticSiteFixture::sole();

        $this->assertSame('m14-security', $fixture->purpose);
        $this->assertSame('staging', $fixture->environment);
        $this->assertSame('https://m14-security-fixture.staging.wpcaregrid.com', $website->url);
        $this->assertFalse($fixture->backup_restore_entitled);
        $this->assertTrue($fixture->expires_at->isFuture());
        $this->assertNull($customer->user_id);
        $this->assertSame(0, Subscription::count());
        $this->assertSame(0, BillingInvoice::count());
        $this->assertSame(0, CheckoutIntent::count());
        $this->assertSame(0, CustomerBillingIdentity::count());
        $this->assertSame(0, User::count());
        $this->assertSame(0, StripeEvent::count());
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_m14_provisioner_accepts_an_explicit_external_https_hostname(): void
    {
        config([
            'm14_security_fixture.provisioning_enabled' => true,
            'm14_security_fixture.site_host' => 'm14-test.example.org',
        ]);

        $website = app(SyntheticFixtureProvisioner::class)->provisionM14SecurityFixture()['website'];

        $this->assertSame('https://m14-test.example.org', $website->url);
        $this->assertTrue(M14SecurityHost::isAllowed('m14-test.example.org'));
        $this->assertTrue(M14SecurityHost::isExactHttpsUrl($website->url, 'm14-test.example.org'));
    }

    public function test_m14_provisioner_rejects_unsafe_or_noncanonical_hosts_without_writes(): void
    {
        foreach ([
            'M14-test.example.org', 'm14-test.example.org.', '*.example.org',
            'https://m14-test.example.org', 'user@m14-test.example.org',
            'm14-test.example.org:443', '127.0.0.1', '[::1]',
            '127.1', '0177.0.0.1', 'localhost', 'm14-test.local', 'm14-test.test',
            'm14-test.example', 'm14-test.home.arpa',
        ] as $host) {
            config([
                'm14_security_fixture.provisioning_enabled' => true,
                'm14_security_fixture.site_host' => $host,
            ]);

            try {
                app(SyntheticFixtureProvisioner::class)->provisionM14SecurityFixture();
                $this->fail("Unsafe M14 host was accepted: {$host}");
            } catch (ValidationException) {
                $this->assertSame(0, Customer::count());
                $this->assertSame(0, Website::count());
            }
        }

        foreach ([
            'https://m14-test.example.org/',
            'https://user@m14-test.example.org',
            'https://m14-test.example.org:443',
            'http://m14-test.example.org',
            'https://alias.example.org',
        ] as $url) {
            $this->assertFalse(M14SecurityHost::isExactHttpsUrl($url, 'm14-test.example.org'));
        }
    }

    public function test_m14_provisioning_is_disabled_by_default_and_fails_closed_outside_staging(): void
    {
        try {
            app(SyntheticFixtureProvisioner::class)->provisionM14SecurityFixture();
            $this->fail('M14 provisioning must be disabled by default.');
        } catch (ValidationException) {
            $this->assertSame(0, Customer::count());
        }

        config([
            'm14_security_fixture.provisioning_enabled' => true,
            'app.deployment_environment' => 'production',
            'app.deployment_hostname' => 'wpcaregrid.com',
            'app.url' => 'https://wpcaregrid.com',
        ]);

        try {
            app(SyntheticFixtureProvisioner::class)->provisionM14SecurityFixture();
            $this->fail('M14 provisioning must reject production.');
        } catch (ValidationException) {
            $this->assertSame(0, Customer::count());
            $this->assertSame(0, Website::count());
            $this->assertSame(0, SyntheticSiteFixture::count());
        }
    }

    public function test_m14_and_m8_fixtures_coexist_and_duplicate_m14_provisioning_is_refused(): void
    {
        app(SyntheticFixtureProvisioner::class)->provision(
            'WP CareGrid M8 E2E', 'm8-e2e@example.test', 'https://m8-e2e.staging.wpcaregrid.com',
        );
        config(['m14_security_fixture.provisioning_enabled' => true]);
        app(SyntheticFixtureProvisioner::class)->provisionM14SecurityFixture();

        $this->assertSame(['m14-security', 'm8-e2e'], SyntheticSiteFixture::query()->orderBy('purpose')->pluck('purpose')->all());
        $this->assertSame(2, Customer::count());
        $this->assertSame(2, Website::count());
        $this->assertSame(0, Subscription::count());

        $this->expectException(ValidationException::class);
        app(SyntheticFixtureProvisioner::class)->provisionM14SecurityFixture();
    }

    public function test_paid_sites_cannot_become_m14_fixtures_and_m14_never_inherits_m8_backups(): void
    {
        config(['m14_security_fixture.provisioning_enabled' => true]);
        $m14 = app(SyntheticFixtureProvisioner::class)->provisionM14SecurityFixture()['website'];
        config([
            'm14_security_fixture.fixture_enabled' => true,
            'm14_security_fixture.site_uuid' => $m14->uuid,
            'm14_security_fixture.customer_uuid' => $m14->customer->uuid,
        ]);
        $policy = app(SyntheticFixturePolicy::class);
        $fixture = SyntheticSiteFixture::query()->where('website_id', $m14->id)->first();
        $this->assertNotNull($fixture);
        $this->assertTrue($policy->m14SecurityFixtureEnabledFor($m14));
        $this->assertFalse($policy->backupRestoreAllowed($m14));
        $this->assertFalse($policy->controlledExecutionAllowed($m14));
        $this->assertFalse($policy->jobAllowed($m14, JobType::BackupFull));
        $this->assertFalse($policy->jobAllowed($m14, JobType::Restore));

        $paidCustomer = Customer::factory()->withUser()->create();
        $paidSubscription = Subscription::factory()->for($paidCustomer)->create();
        $paidWebsite = Website::factory()->for($paidCustomer)->create([
            'url' => 'https://paid.example.test',
            'subscription_id' => $paidSubscription->id,
        ]);
        // Simulate corrupted/relabelled fixture metadata without violating the unique-purpose DB constraint.
        $m14->syntheticFixture->forceFill([
            'customer_id' => $paidCustomer->id,
            'website_id' => $paidWebsite->id,
        ])->save();
        config([
            'm14_security_fixture.site_uuid' => $paidWebsite->uuid,
            'm14_security_fixture.customer_uuid' => $paidCustomer->uuid,
        ]);
        $this->assertFalse($policy->m14SecurityFixtureEnabledFor($paidWebsite->fresh()));
        $this->assertFalse($policy->securityScanAllowed($paidWebsite->fresh(), 'integrity'));
    }

    public function test_m14_authorization_binds_exact_site_customer_and_connector_and_requires_expiry(): void
    {
        $site = $this->pairedM14Fixture();
        $website = $site['website'];
        $policy = app(SyntheticFixturePolicy::class);

        config(['m14_security_fixture.controlled_polling_enabled' => true]);
        $this->assertTrue($policy->controlledPollingAllowed($website));

        config(['m14_security_fixture.site_uuid' => (string) Str::uuid()]);
        $this->assertFalse($policy->m14SecurityFixtureEnabledFor($website));
        config(['m14_security_fixture.site_uuid' => $website->uuid]);

        config(['m14_security_fixture.customer_uuid' => (string) Str::uuid()]);
        $this->assertFalse($policy->m14SecurityFixtureEnabledFor($website));
        config(['m14_security_fixture.customer_uuid' => $website->customer->uuid]);

        config(['m14_security_fixture.connector_uuid' => (string) Str::uuid()]);
        $this->assertFalse($policy->controlledPollingAllowed($website));
        config(['m14_security_fixture.connector_uuid' => $website->activeConnector->uuid]);

        $website->syntheticFixture->forceFill(['expires_at' => null])->save();
        $this->assertFalse($policy->m14SecurityFixtureEnabledFor($website->fresh()));
    }

    public function test_m14_controlled_poll_delivers_only_the_safe_inventory_setup_job(): void
    {
        $site = $this->pairedM14Fixture();
        $website = $site['website'];
        config(['m14_security_fixture.controlled_polling_enabled' => true]);

        $jobs = app(JobQueue::class)->deliver($website->activeConnector, controlledExecution: true);

        $this->assertCount(1, $jobs);
        $this->assertSame(JobType::GetInventory, $jobs->first()->type);
        $this->assertSame('delivered', $jobs->first()->status->value);
    }

    public function test_m14_scan_window_refuses_missing_connector_capability(): void
    {
        $site = $this->pairedM14Fixture(['inventory', 'health', 'license_check']);
        $website = $site['website'];
        config([
            'm14_security_fixture.controlled_polling_enabled' => true,
            'm14_security_fixture.security_scan_enabled' => true,
            'security.incidents.auto_open' => false,
            'security.incidents.auto_backup' => false,
        ]);

        try {
            app(M14SecurityScanWindowService::class)->open($website, 'test-owner');
            $this->fail('The scan window must reject a Connector without the required capability.');
        } catch (ValidationException) {
            $this->assertFalse(M14SecurityScanWindow::query()->whereKey(1)->firstOrFail()->active);
        }
    }

    public function test_m14_scan_window_refuses_stale_readiness(): void
    {
        $site = $this->pairedM14Fixture();
        $website = $site['website'];
        config([
            'm14_security_fixture.controlled_polling_enabled' => true,
            'm14_security_fixture.security_scan_enabled' => true,
            'security.incidents.auto_open' => false,
            'security.incidents.auto_backup' => false,
        ]);
        $snapshot = $website->capabilitySnapshot;
        $snapshot->forceFill(['checked_at' => now()->subDays(2)])->save();

        $this->expectException(ValidationException::class);
        app(M14SecurityScanWindowService::class)->open($website, 'test-owner');
    }

    public function test_m14_scan_window_requires_fresh_inventory_for_the_active_connector(): void
    {
        $site = $this->pairedM14Fixture();
        $website = $site['website'];
        config([
            'm14_security_fixture.controlled_polling_enabled' => true,
            'm14_security_fixture.security_scan_enabled' => true,
            'security.incidents.auto_open' => false,
            'security.incidents.auto_backup' => false,
        ]);

        WebsiteInventory::query()->updateOrCreate(
            ['website_id' => $website->id],
            ['connector_id' => $website->activeConnector->id, 'core' => [], 'plugins' => [], 'themes' => [], 'collected_at' => now()->subHours(2)],
        );

        $this->expectException(ValidationException::class);
        app(M14SecurityScanWindowService::class)->open($website, 'test-owner');
    }

    public function test_m14_scan_window_requires_security_scan_capability_status_exactly_pass(): void
    {
        $site = $this->pairedM14Fixture();
        $website = $site['website'];
        config([
            'm14_security_fixture.controlled_polling_enabled' => true,
            'm14_security_fixture.security_scan_enabled' => true,
            'security.incidents.auto_open' => false,
            'security.incidents.auto_backup' => false,
        ]);
        $snapshot = $website->capabilitySnapshot;
        $capabilities = $snapshot->capabilities;
        $capabilities['SECURITY_SCAN_READY']['status'] = 'WARN';
        $snapshot->forceFill(['capabilities' => $capabilities])->save();

        $this->expectException(ValidationException::class);
        app(M14SecurityScanWindowService::class)->open($website, 'test-owner');
    }

    public function test_m14_integrity_scan_requires_exclusive_window_and_blocks_other_sites(): void
    {
        $site = $this->pairedM14Fixture();
        $website = $site['website'];
        $policy = app(SyntheticFixturePolicy::class);

        config([
            'm14_security_fixture.controlled_polling_enabled' => true,
            'm14_security_fixture.security_scan_enabled' => true,
            'security.incidents.auto_open' => false,
            'security.incidents.auto_backup' => false,
        ]);
        $this->assertTrue($policy->controlledPollingAllowed($website));
        $this->assertTrue($policy->jobAllowed($website, JobType::GetInventory));
        $this->assertFalse($policy->jobAllowed($website, JobType::SecurityScan, ['scan_type' => 'integrity']));

        $window = app(M14SecurityScanWindowService::class)->open($website, 'test-owner');
        $this->assertTrue($window->isLive());
        $this->assertTrue($policy->securityScanAllowed($website, 'integrity'));
        $this->assertFalse($policy->securityScanAllowed($website, 'malware'));
        $this->assertFalse($policy->securityScanAllowed($website, 'full'));
        $this->assertFalse($policy->jobAllowed($website, JobType::BackupFull));
        $this->assertFalse($policy->jobAllowed($website, JobType::Restore));

        $scan = app(SecurityScanService::class)->request($website, ScanType::Integrity, 'operator:1');
        $this->assertSame('queued', $scan->status->value);
        $this->assertSame(1, $website->jobs()->where('type', JobType::SecurityScan->value)->count());
        $this->assertDatabaseHas('m14_security_scan_isolations', [
            'scan_uuid' => $scan->uuid,
            'website_id' => $website->id,
            'connector_id' => $website->activeConnector->id,
        ]);
        try {
            app(JobQueue::class)->queue($website, JobType::SecurityScan, [
                'scan_uuid' => (string) Str::uuid(),
                'scan_type' => 'integrity', 'part' => 1, 'time_budget' => 60,
            ]);
            $this->fail('An M14 scan job must be bound to the exact authorized scan UUID.');
        } catch (ConnectorError $error) {
            $this->assertSame('JOB_NOT_ALLOWED', $error->errorCode);
        }

        $other = $this->pairedWebsite('https://unrelated-customer.example.test')['website'];
        $this->assertSame('M14_SCAN_WINDOW_ACTIVE', app(SecurityScanService::class)->refusal($other, ScanType::Integrity));
        $preexistingScanJob = $other->jobs()->create([
            'type' => JobType::SecurityScan,
            'payload' => ['scan_uuid' => (string) Str::uuid(), 'scan_type' => 'integrity', 'part' => 1, 'time_budget' => 60],
            'status' => 'queued',
            'requested_by' => 'race-regression-test',
        ]);
        $deliveredJobs = app(JobQueue::class)->deliver($other->activeConnector);
        $this->assertFalse($deliveredJobs->contains(fn ($job) => $job->id === $preexistingScanJob->id));
        $this->assertSame('cancelled', $preexistingScanJob->fresh()->status->value);
        try {
            app(JobQueue::class)->queue($other, JobType::SecurityScan, [
                'scan_uuid' => (string) Str::uuid(),
                'scan_type' => 'integrity', 'part' => 1, 'time_budget' => 60,
            ]);
            $this->fail('Other staging sites must be blocked during the exclusive window.');
        } catch (ConnectorError $error) {
            $this->assertSame('JOB_NOT_ALLOWED', $error->errorCode);
        }
    }

    public function test_m14_window_keeps_global_incident_settings_and_uses_exact_site_isolation(): void
    {
        $site = $this->pairedM14Fixture();
        config([
            'm14_security_fixture.controlled_polling_enabled' => true,
            'm14_security_fixture.security_scan_enabled' => true,
            'security.incidents.auto_open' => true,
            'security.incidents.auto_backup' => true,
        ]);

        $window = app(M14SecurityScanWindowService::class)->open($site['website'], 'test-owner');

        $this->assertTrue($window->isLive());
        $this->assertTrue($window->side_effect_isolation);
        $this->assertTrue(config('security.incidents.auto_open'));
        $this->assertTrue(config('security.incidents.auto_backup'));
    }

    public function test_m14_window_fails_closed_when_site_scoped_isolation_is_disabled(): void
    {
        $site = $this->pairedM14Fixture();
        config([
            'm14_security_fixture.controlled_polling_enabled' => true,
            'm14_security_fixture.security_scan_enabled' => true,
            'm14_security_fixture.side_effect_isolation_enabled' => false,
        ]);

        $this->expectException(ValidationException::class);
        app(M14SecurityScanWindowService::class)->open($site['website'], 'test-owner');
    }

    public function test_m14_scan_delivery_rechecks_bound_scan_uuid(): void
    {
        $site = $this->pairedM14Fixture();
        $website = $site['website'];
        config([
            'm14_security_fixture.controlled_polling_enabled' => true,
            'm14_security_fixture.security_scan_enabled' => true,
        ]);
        app(M14SecurityScanWindowService::class)->open($website, 'test-owner');
        $website->jobs()->where('type', '!=', JobType::SecurityScan->value)->update(['status' => 'success']);
        $scan = app(SecurityScanService::class)->request($website, ScanType::Integrity, 'operator:1');
        $job = $scan->job()->firstOrFail();
        $payload = $job->payload;
        $payload['scan_uuid'] = (string) Str::uuid();
        $job->forceFill(['payload' => $payload])->save();

        $delivered = app(JobQueue::class)->deliver($website->activeConnector, controlledExecution: true);

        $this->assertCount(0, $delivered);
        $this->assertSame('cancelled', $job->fresh()->status->value);
        $this->assertSame('JOB_NOT_ALLOWED', $job->fresh()->error['code']);
    }

    public function test_exact_m14_scan_suppresses_automatic_incident_and_backup_with_audit(): void
    {
        $site = $this->pairedM14Fixture();
        $website = $site['website'];
        config([
            'm14_security_fixture.controlled_polling_enabled' => true,
            'm14_security_fixture.security_scan_enabled' => true,
            'security.incidents.auto_open' => true,
            'security.incidents.auto_backup' => true,
        ]);

        app(M14SecurityScanWindowService::class)->open($website, 'test-owner');
        $scan = app(SecurityScanService::class)->request($website, ScanType::Integrity, 'operator:1');
        app(FindingRecorder::class)->record($scan, new ReportedFinding(
            FindingType::SuspiciousFile,
            'm14-test-suspicious-file',
            Severity::High,
            Confidence::High,
            'Synthetic suspicious file',
            'Synthetic test finding only.',
            'fixture',
            'synthetic/test.php',
        ));

        $this->assertNull(app(IncidentService::class)->openForDetection($scan));
        $this->assertSame(0, $website->securityIncidents()->count());
        $this->assertSame(0, $website->backups()->count());
        $this->assertDatabaseHas('website_events', [
            'website_id' => $website->id,
            'event' => 'security.m14_test_side_effects_suppressed',
        ]);
        $this->assertSame(0, $website->jobs()->whereIn('type', [
            JobType::BackupFiles->value, JobType::BackupDatabase->value, JobType::BackupFull->value,
        ])->count());
        $this->assertTrue(config('security.incidents.auto_open'));
        $this->assertTrue(config('security.incidents.auto_backup'));
    }

    public function test_site_scoped_m14_suppression_does_not_change_ordinary_auto_incidents(): void
    {
        $website = $this->pairedWebsite('https://ordinary-customer.example.test')['website'];
        config([
            'security.incidents.auto_open' => true,
            'security.incidents.auto_backup' => true,
        ]);
        $scan = $website->securityScans()->create([
            'type' => ScanType::Integrity,
            'status' => 'completed',
            'requested_by' => 'system',
            'scanner_version' => 'test',
            'coverage' => [],
        ]);
        app(FindingRecorder::class)->record($scan, new ReportedFinding(
            FindingType::SuspiciousFile, 'ordinary-suspicious-file', Severity::High, Confidence::High,
            'Suspicious file', 'Test finding.', 'plugin', 'plugin/test.php',
        ));

        $incident = app(IncidentService::class)->openForDetection($scan);

        $this->assertNotNull($incident);
        $this->assertSame(1, $website->securityIncidents()->count());
        $this->assertFalse($website->events()->where('event', 'security.m14_test_side_effects_suppressed')->exists());
        $this->assertTrue(config('security.incidents.auto_open'));
        $this->assertTrue(config('security.incidents.auto_backup'));
    }

    public function test_persisted_m14_isolation_survives_window_expiry_for_a_delayed_failure_callback(): void
    {
        $site = $this->pairedM14Fixture();
        $website = $site['website'];
        config([
            'm14_security_fixture.controlled_polling_enabled' => true,
            'm14_security_fixture.security_scan_enabled' => true,
            'security.incidents.auto_open' => true,
            'security.incidents.auto_backup' => true,
        ]);

        $window = app(M14SecurityScanWindowService::class)->open($website, 'test-owner');
        $scan = app(SecurityScanService::class)->request($website, ScanType::Integrity, 'operator:1');
        $scan->job()->update(['status' => 'cancelled']);
        $scan->forceFill(['status' => 'cancelled', 'completed_at' => now()])->save();
        $window->forceFill(['expires_at' => now()->subSecond()])->save();
        app(M14SecurityScanWindowService::class)->close('test-owner', 'expired-terminal-scan');

        app(FindingRecorder::class)->record($scan, new ReportedFinding(
            FindingType::SuspiciousFile, 'm14-delayed-failure', Severity::High, Confidence::High,
            'Synthetic delayed result', 'Synthetic test finding only.', 'fixture', 'synthetic/delayed.php',
        ));

        $this->assertNull(app(IncidentService::class)->openForDetection($scan));
        $this->assertSame(0, $website->securityIncidents()->count());
        $this->assertSame(0, $website->backups()->count());
    }

    public function test_m14_backup_service_refuses_even_direct_backup_requests(): void
    {
        config(['m14_security_fixture.provisioning_enabled' => true]);
        $website = app(SyntheticFixtureProvisioner::class)->provisionM14SecurityFixture()['website'];

        try {
            app(BackupService::class)->request($website, BackupReason::Recovery, 'test', BackupType::Full);
            $this->fail('M14 fixtures must not receive real backup operations.');
        } catch (BackupRefused $error) {
            $this->assertSame('M14_FIXTURE_BACKUP_DISABLED', $error->errorCode);
        }

        $this->assertSame(0, $website->backups()->count());
        $this->assertSame(0, $website->jobs()->whereIn('type', [
            JobType::BackupFiles->value, JobType::BackupDatabase->value, JobType::BackupFull->value,
        ])->count());
    }

    public function test_window_close_requires_all_fixture_scan_work_to_be_terminal(): void
    {
        $site = $this->pairedM14Fixture();
        config([
            'm14_security_fixture.controlled_polling_enabled' => true,
            'm14_security_fixture.security_scan_enabled' => true,
            'security.incidents.auto_open' => false,
            'security.incidents.auto_backup' => false,
        ]);
        app(M14SecurityScanWindowService::class)->open($site['website'], 'test-owner');
        $scan = app(SecurityScanService::class)->request($site['website'], ScanType::Integrity, 'operator:1');

        try {
            app(M14SecurityScanWindowService::class)->close('test-owner', 'premature');
            $this->fail('Window close must refuse a queued scan.');
        } catch (ValidationException) {
            $this->assertTrue(M14SecurityScanWindow::query()->whereKey(1)->firstOrFail()->isLive());
        }

        $scan->job()->update(['status' => 'cancelled']);
        $scan->forceFill(['status' => 'cancelled', 'completed_at' => now()])->save();
        app(M14SecurityScanWindowService::class)->close('test-owner', 'completed');
        $this->assertFalse(M14SecurityScanWindow::query()->whereKey(1)->firstOrFail()->isLive());
    }

    public function test_expired_window_remains_an_isolation_lock_until_closed(): void
    {
        $site = $this->pairedM14Fixture();
        config([
            'm14_security_fixture.controlled_polling_enabled' => true,
            'm14_security_fixture.security_scan_enabled' => true,
            'security.incidents.auto_open' => false,
            'security.incidents.auto_backup' => false,
        ]);
        $window = app(M14SecurityScanWindowService::class)->open($site['website'], 'test-owner');
        $window->forceFill(['expires_at' => Carbon::now()->subSecond()])->save();

        $other = $this->pairedWebsite('https://unrelated-customer.example.test')['website'];
        $this->assertSame('M14_SCAN_WINDOW_EXPIRED_REQUIRES_CLOSE', app(SecurityScanService::class)->refusal($other, ScanType::Integrity));
        $this->assertFalse(app(SyntheticFixturePolicy::class)->securityScanAllowed($site['website'], 'integrity'));

        app(M14SecurityScanWindowService::class)->close('test-owner', 'expired-with-no-work');
        $this->assertNotSame(
            'M14_SCAN_WINDOW_EXPIRED_REQUIRES_CLOSE',
            app(SecurityScanService::class)->refusal($other, ScanType::Integrity),
        );
    }

    private function pairedM14Fixture(array $capabilities = ['inventory', 'health', 'license_check', 'security_scan']): array
    {
        config([
            'm14_security_fixture.provisioning_enabled' => true,
            'm14_security_fixture.fixture_enabled' => true,
            'm14_security_fixture.side_effect_isolation_enabled' => true,
            'm14_security_fixture.site_host' => 'm14-security-fixture.staging.wpcaregrid.com',
        ]);
        $website = app(SyntheticFixtureProvisioner::class)->provisionM14SecurityFixture()['website'];
        config([
            'm14_security_fixture.site_uuid' => $website->uuid,
            'm14_security_fixture.customer_uuid' => $website->customer->uuid,
        ]);
        $code = app(PairingCodes::class)->issue($website);
        $this->pair($code, $website->url, [
            'connector_version' => config('connector.latest_version'),
            'wp_version' => '7.1.3',
            'php_version' => '8.4.27',
            'capabilities' => $capabilities,
        ])->assertCreated();

        $website = $website->fresh();
        config(['m14_security_fixture.connector_uuid' => $website->activeConnector->uuid]);
        $this->refreshCapabilityEvidence($website);
        WebsiteInventory::query()->create([
            'website_id' => $website->id,
            'connector_id' => $website->activeConnector->id,
            'core' => [],
            'plugins' => [],
            'themes' => [],
            'collected_at' => now(),
        ]);

        return ['website' => $website->fresh()];
    }
}
