<?php

namespace Tests\Feature\SyntheticFixtures;

use App\Connector\ConnectorError;
use App\Connector\JobQueue;
use App\Connector\JobStatus;
use App\Connector\JobType;
use App\Connector\PairingCodes;
use App\Models\SecurityScan;
use App\Models\SyntheticSiteFixture;
use App\Models\Website;
use App\Models\WebsiteJob;
use App\Operator\Permission;
use App\Security\ScanStatus;
use App\Security\ScanType;
use App\Security\SecurityRefused;
use App\Security\SecurityScanService;
use App\SyntheticFixtures\SyntheticFixturePolicy;
use App\SyntheticFixtures\SyntheticFixtureProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\RunsSecurityScans;
use Tests\TestCase;

final class SyntheticFixtureSecurityScanTest extends TestCase
{
    use RefreshDatabase, RunsSecurityScans;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups();
        $this->configureStaging();
    }

    protected function tearDown(): void
    {
        $this->tearDownBackups();
        parent::tearDown();
    }

    public function test_only_authorized_operator_can_request_exact_fixture_integrity_scan_and_controlled_poll_delivers_it(): void
    {
        $site = $this->pairedSyntheticFixture();
        $website = $site['website'];

        // Refresh the current security-scan compatibility snapshot before requesting a scan.
        $this->withHeaders($this->connectorHeaders($site))
            ->postJson(route('api.v1.connector.inventory'), $this->inventoryPayload())
            ->assertSuccessful();
        $website->jobs()->whereIn('type', [JobType::GetInventory, JobType::SiteHealth, JobType::LicenseCheck])
            ->update(['status' => JobStatus::Success->value]);

        config([
            'synthetic_fixtures.controlled_execution_enabled' => true,
            'synthetic_fixtures.security_scan_enabled' => true,
            'synthetic_fixtures.site_uuid' => $website->uuid,
        ]);
        $this->assertTrue(app(SyntheticFixturePolicy::class)->securityScanAllowed($website, 'integrity'));

        $this->asOperator([]);
        $this->post(route('operator.websites.security.scans.store', $website), ['type' => 'integrity'])->assertForbidden();
        $this->assertSame(0, SecurityScan::count());

        $this->asOperator([Permission::SecurityScan]);
        $this->post(route('operator.websites.security.scans.store', $website), ['type' => 'integrity'])
            ->assertSessionHas('status');

        $scan = SecurityScan::sole();
        $this->assertSame(ScanType::Integrity, $scan->type);
        $job = WebsiteJob::where('type', JobType::SecurityScan)->sole();
        $this->assertSame('integrity', $job->payload['scan_type']);

        // The ordinary poll remains restricted; only the site-scoped controlled poll receives the scan.
        $normal = $this->withHeaders($this->connectorHeaders($site))->getJson(route('api.v1.connector.jobs'))
            ->assertOk()->assertJsonCount(0, 'jobs');
        $this->assertSame([], $normal->json('jobs'));
        $this->assertSame(JobStatus::Queued, $job->refresh()->status);

        $controlled = $this->withHeaders($this->connectorHeaders($site))
            ->getJson(route('api.v1.connector.jobs').'?execution_mode=controlled')
            ->assertOk()->assertJsonPath('controlled_execution', true)->assertJsonCount(1, 'jobs');
        $this->assertSame(JobType::SecurityScan->value, $controlled->json('jobs.0.type'));
        $this->assertSame('integrity', $controlled->json('jobs.0.payload.scan_type'));
        $this->assertSame(ScanStatus::Running, $scan->refresh()->status);

        $this->reportJob($site, $job->refresh(), 'success', 'SCAN_COMPLETED', ['security' => [
            'scan_uuid' => $scan->uuid,
            'part' => 1,
            'complete' => true,
            'scanner_version' => '0.4.7',
            'rules_version' => 'm8-synthetic-test',
            'coverage' => ['integrity' => ['status' => 'completed', 'core' => 'verified', 'verified' => 1, 'modified' => 0, 'missing' => 0, 'extra' => 0, 'unavailable' => 0]],
            'findings' => [],
        ]])->assertSuccessful();

        $this->assertSame(ScanStatus::Completed, $scan->refresh()->status);
        $this->assertSame('completed', $scan->coverage['integrity']['status']);
        $this->assertDatabaseCount('security_findings', 0);
        $this->assertDatabaseCount('security_incidents', 0);
        $this->assertDatabaseCount('backups', 0);
        $this->assertSame(1, SyntheticSiteFixture::count());
    }

    public function test_synthetic_integrity_scan_is_fail_closed_when_disabled_or_scope_is_changed(): void
    {
        $site = $this->pairedSyntheticFixture();
        $website = $site['website'];
        $policy = app(SyntheticFixturePolicy::class);

        config([
            'synthetic_fixtures.controlled_execution_enabled' => true,
            'synthetic_fixtures.site_uuid' => $website->uuid,
            'synthetic_fixtures.security_scan_enabled' => false,
        ]);
        $this->assertFalse($policy->securityScanAllowed($website, 'integrity'));
        $this->assertSame('SYNTHETIC_SCAN_DISABLED', app(SecurityScanService::class)->refusal($website, ScanType::Integrity));
        $scanPayload = [
            'scan_uuid' => (string) Str::uuid(),
            'scan_type' => 'integrity',
            'part' => 1,
            'time_budget' => 60,
        ];

        try {
            app(JobQueue::class)->queue($website->load('activeConnector'), JobType::SecurityScan, $scanPayload);
            $this->fail('A disabled synthetic scanner must not enqueue a Connector job.');
        } catch (ConnectorError $error) {
            $this->assertSame('JOB_NOT_ALLOWED', $error->errorCode);
        }

        try {
            app(SecurityScanService::class)->request($website->load(['activeConnector', 'customer']), ScanType::Integrity, 'operator:1');
            $this->fail('A disabled synthetic scanner must not create a scan.');
        } catch (SecurityRefused $error) {
            $this->assertSame('SYNTHETIC_SCAN_DISABLED', $error->errorCode);
        }
        $this->assertSame(0, SecurityScan::count());
        $this->assertSame(0, WebsiteJob::where('type', JobType::SecurityScan)->count());

        config(['synthetic_fixtures.security_scan_enabled' => true]);
        try {
            app(JobQueue::class)->queue($website->load('activeConnector'), JobType::SecurityScan, [
                ...$scanPayload,
                'scan_type' => 'full',
            ]);
            $this->fail('The synthetic scanner must not enqueue a Full scan.');
        } catch (ConnectorError $error) {
            $this->assertSame('JOB_NOT_ALLOWED', $error->errorCode);
        }
        $this->assertFalse($policy->securityScanAllowed($website, 'malware'));
        $this->assertFalse($policy->securityScanAllowed($website, 'full'));
        $this->assertFalse($policy->jobAllowed($website, JobType::SecurityScan, ['scan_type' => 'malware']));
        $this->assertFalse($policy->jobAllowed($website, JobType::SecurityScan, ['scan_type' => 'full']));
        $this->assertSame('SYNTHETIC_SCAN_TYPE_NOT_ALLOWED', app(SecurityScanService::class)->refusal($website, ScanType::Full));

        config(['synthetic_fixtures.site_uuid' => '00000000-0000-4000-8000-000000000001']);
        $this->assertFalse($policy->securityScanAllowed($website, 'integrity'));

        config([
            'synthetic_fixtures.site_uuid' => $website->uuid,
            'app.deployment_environment' => 'production',
            'app.url' => 'https://wpcaregrid.com',
            'app.deployment_hostname' => 'wpcaregrid.com',
        ]);
        $this->assertFalse($policy->securityScanAllowed($website, 'integrity'));
    }

    public function test_integrity_scan_result_cannot_smuggle_malware_finding_into_incident_or_backup_flow(): void
    {
        $site = $this->pairedSyntheticFixture();
        $website = $site['website'];
        $this->withHeaders($this->connectorHeaders($site))
            ->postJson(route('api.v1.connector.inventory'), $this->inventoryPayload())
            ->assertSuccessful();
        $website->jobs()->whereIn('type', [JobType::GetInventory, JobType::SiteHealth, JobType::LicenseCheck])
            ->update(['status' => JobStatus::Success->value]);
        config([
            'synthetic_fixtures.controlled_execution_enabled' => true,
            'synthetic_fixtures.security_scan_enabled' => true,
            'synthetic_fixtures.site_uuid' => $website->uuid,
        ]);

        $scan = app(SecurityScanService::class)->request($website->load(['activeConnector', 'customer', 'inventory']), ScanType::Integrity, 'operator:1');
        $job = WebsiteJob::where('type', JobType::SecurityScan)->sole();
        $this->withHeaders($this->connectorHeaders($site))
            ->getJson(route('api.v1.connector.jobs').'?execution_mode=controlled')
            ->assertOk()->assertJsonPath('jobs.0.type', JobType::SecurityScan->value);

        $this->reportJob($site, $job->refresh(), 'success', 'SCAN_COMPLETED', ['security' => [
            'scan_uuid' => $scan->uuid,
            'part' => 1,
            'complete' => true,
            'scanner_version' => '0.4.7',
            'rules_version' => 'm8-synthetic-test',
            'coverage' => ['integrity' => ['status' => 'completed', 'core' => 'verified', 'verified' => 1, 'modified' => 0, 'missing' => 0, 'extra' => 0, 'unavailable' => 0]],
            'findings' => [$this->suspiciousFile('wp-content/uploads/synthetic.php', 'critical', 'high')],
        ]])->assertSuccessful();

        $this->assertSame(ScanStatus::Failed, $scan->refresh()->status);
        $this->assertSame('INVALID_RESULT', $scan->error_code);
        $this->assertDatabaseCount('security_findings', 0);
        $this->assertDatabaseCount('security_incidents', 0);
        $this->assertDatabaseCount('backups', 0);
    }

    /** @return array{website: Website, credential: string} */
    private function pairedSyntheticFixture(): array
    {
        $website = app(SyntheticFixtureProvisioner::class)->provision(
            'WP CareGrid M8 E2E', 'm8-e2e@example.test', 'https://m8-e2e.staging.wpcaregrid.com',
        )['website'];
        $code = app(PairingCodes::class)->issue($website);
        $pair = $this->pair($code, $website->url, [
            'connector_version' => config('connector.latest_version'),
            'capabilities' => ['inventory', 'health', 'license_check', 'security_scan'],
        ])->assertCreated();

        return ['website' => $website->refresh(), 'credential' => $pair->json('credential')];
    }

    private function configureStaging(): void
    {
        config([
            'app.env' => 'production',
            'app.url' => 'https://staging.wpcaregrid.com',
            'app.deployment_environment' => 'staging',
            'app.deployment_hostname' => 'stg1.wpcaregrid.com',
            'synthetic_fixtures.provisioning_enabled' => true,
            'synthetic_fixtures.controlled_execution_enabled' => false,
            'synthetic_fixtures.security_scan_enabled' => false,
            'synthetic_fixtures.restore_enabled' => false,
            'synthetic_fixtures.site_uuid' => null,
        ]);
    }
}
