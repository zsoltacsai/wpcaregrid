<?php

namespace Tests\Feature\SyntheticFixtures;

use App\Backups\BackupReason;
use App\Backups\BackupService;
use App\Backups\Storage\LocalStorage;
use App\Backups\Storage\StorageManager;
use App\Connector\JobStatus;
use App\Connector\JobType;
use App\Connector\PairingCodes;
use App\Models\SyntheticSiteFixture;
use App\SyntheticFixtures\SyntheticFixtureProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PairsConnectors;
use Tests\TestCase;

final class SyntheticFixtureConnectorTest extends TestCase
{
    use PairsConnectors, RefreshDatabase;

    public function test_synthetic_fixture_uses_normal_pairing_and_server_scoped_controlled_poll(): void
    {
        $this->configureStaging();
        $website = app(SyntheticFixtureProvisioner::class)->provision(
            'WP CareGrid M8 E2E', 'm8-e2e@example.test', 'https://m8-e2e.staging.wpcaregrid.com',
        )['website'];
        $code = app(PairingCodes::class)->issue($website);

        $pair = $this->pair($code, $website->url)->assertCreated()
            ->assertJsonPath('website_uuid', $website->uuid)
            ->assertJsonPath('synthetic_site', true)
            ->assertJsonPath('controlled_execution', false);
        $site = ['website' => $website->refresh(), 'credential' => $pair->json('credential')];

        $this->assertSame(
            [JobType::GetInventory->value, JobType::SiteHealth->value, JobType::LicenseCheck->value],
            $website->jobs()->oldest('id')->get()->map(fn ($job) => $job->type->value)->all(),
        );

        // Normal pairing and automatic polling can complete harmless setup checks.
        $this->withHeaders($this->connectorHeaders($site))
            ->getJson(route('api.v1.connector.jobs'))
            ->assertOk()->assertJsonCount(3, 'jobs');
        $this->assertSame(3, $website->jobs()->where('status', 'delivered')->count());

        config([
            'synthetic_fixtures.controlled_execution_enabled' => true,
            'synthetic_fixtures.site_uuid' => $website->uuid,
        ]);

        $this->withHeaders($this->connectorHeaders($site))
            ->getJson(route('api.v1.connector.jobs').'?execution_mode=controlled')
            ->assertOk()->assertJsonPath('synthetic_site', true)->assertJsonPath('controlled_execution', true)
            ->assertJsonCount(1, 'jobs');

        // A synthetic fixture UUID cannot be substituted by the request.
        $this->withHeaders(array_replace($this->connectorHeaders($site), ['X-CareGrid-Website' => '00000000-0000-4000-8000-000000000001']))
            ->getJson(route('api.v1.connector.jobs').'?execution_mode=controlled')
            ->assertForbidden();
        $this->assertSame(1, SyntheticSiteFixture::count());
    }

    public function test_controlled_execution_is_refused_for_an_ordinary_site(): void
    {
        $this->configureStaging();
        $site = $this->pairedWebsite();

        $this->withHeaders($this->connectorHeaders($site))
            ->getJson(route('api.v1.connector.jobs').'?execution_mode=controlled')
            ->assertForbidden();
    }

    public function test_synthetic_controlled_poll_is_refused_if_deployment_identity_becomes_production(): void
    {
        $this->configureStaging();
        $website = app(SyntheticFixtureProvisioner::class)->provision(
            'WP CareGrid M8 E2E', 'm8-e2e@example.test', 'https://m8-e2e.staging.wpcaregrid.com',
        )['website'];
        config([
            'synthetic_fixtures.controlled_execution_enabled' => true,
            'synthetic_fixtures.site_uuid' => $website->uuid,
        ]);
        $code = app(PairingCodes::class)->issue($website);
        $pair = $this->pair($code, $website->url)->assertCreated();
        $site = ['website' => $website, 'credential' => $pair->json('credential')];

        config(['app.deployment_environment' => 'production', 'app.url' => 'https://wpcaregrid.com']);
        $this->withHeaders($this->connectorHeaders($site))
            ->getJson(route('api.v1.connector.jobs').'?execution_mode=controlled')
            ->assertForbidden();
    }

    public function test_automatic_poll_can_run_initial_checks_but_never_delivers_backup_jobs(): void
    {
        $this->configureStaging();
        $website = app(SyntheticFixtureProvisioner::class)->provision(
            'WP CareGrid M8 E2E', 'm8-e2e@example.test', 'https://m8-e2e.staging.wpcaregrid.com',
        )['website'];
        $root = sys_get_temp_dir().'/m8-synthetic-backup-'.uniqid();
        config([
            'synthetic_fixtures.controlled_execution_enabled' => true,
            'synthetic_fixtures.site_uuid' => $website->uuid,
            'backup.storage' => 'local',
            'backup.providers.local.driver' => 'local',
            'backup.providers.local.root' => $root,
        ]);
        app(StorageManager::class)->set('local', new LocalStorage($root));

        $code = app(PairingCodes::class)->issue($website);
        $pair = $this->pair($code, $website->url, ['capabilities' => [
            'inventory', 'health', 'updates', 'license_check', 'backup_files', 'backup_database',
            'backup_full', 'restore_files', 'restore_database', 'restore_full',
        ]])->assertCreated();
        $site = ['website' => $website->refresh(), 'credential' => $pair->json('credential')];

        $backup = app(BackupService::class)->request($website, BackupReason::Manual, 'operator:m8-test');
        $ordinaryPoll = $this->withHeaders($this->connectorHeaders($site))
            ->getJson(route('api.v1.connector.jobs'))
            ->assertOk();
        $this->assertNotContains(JobType::BackupFull->value, array_column($ordinaryPoll->json('jobs'), 'type'));
        $this->assertSame(JobStatus::Queued, $backup->job()->sole()->status);

        // After harmless setup checks are complete, the site-scoped runner
        // receives the real backup WebsiteJob through the same authenticated API.
        $website->jobs()->whereIn('type', [JobType::GetInventory->value, JobType::SiteHealth->value, JobType::LicenseCheck->value])
            ->update(['status' => JobStatus::Success->value]);
        $controlledPoll = $this->withHeaders($this->connectorHeaders($site))
            ->getJson(route('api.v1.connector.jobs').'?execution_mode=controlled')
            ->assertOk();
        $this->assertSame([JobType::BackupFull->value], array_column($controlledPoll->json('jobs'), 'type'));
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
            'synthetic_fixtures.restore_enabled' => false,
            'synthetic_fixtures.site_uuid' => null,
        ]);
    }
}
