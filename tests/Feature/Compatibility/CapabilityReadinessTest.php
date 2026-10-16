<?php

namespace Tests\Feature\Compatibility;

use App\Compatibility\Capability;
use App\Compatibility\CapabilityEvaluator;
use App\Compatibility\CapabilityReadiness;
use App\Connector\ConnectorError;
use App\Connector\JobQueue;
use App\Connector\JobType;
use App\Models\User;
use App\Operator\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PairsConnectors;
use Tests\TestCase;

final class CapabilityReadinessTest extends TestCase
{
    use PairsConnectors, RefreshDatabase;

    public function test_connector_release_config_uses_the_canonical_package_metadata(): void
    {
        $release = json_decode((string) file_get_contents(base_path('wordpress/wp-caregrid-connector/release.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($release['version'], config('connector.latest_version'));
    }

    public function test_unknown_or_stale_snapshot_fails_closed_without_queue_side_effect(): void
    {
        $site = $this->pairedWebsite();
        $website = $site['website'];
        $before = $website->jobs()->count();
        $website->capabilitySnapshot()->delete();

        $this->assertSame('COMPATIBILITY_UNKNOWN_OR_STALE', app(CapabilityReadiness::class)->refusal($website->fresh(), JobType::UpdatePlugin));
        try {
            app(JobQueue::class)->queue($website->fresh(), JobType::UpdatePlugin, ['plugin' => 'akismet/akismet.php']);
            $this->fail('A site without current facts was allowed to queue an update.');
        } catch (ConnectorError $error) {
            $this->assertSame('JOB_NOT_ALLOWED', $error->errorCode);
        }

        $this->assertSame($before, $website->jobs()->count());
    }

    public function test_multisite_blocks_filesystem_mutations_but_keeps_connection_diagnostics(): void
    {
        $site = $this->pairedWebsite();
        $facts = $this->inventoryPayload()['compatibility'];
        $facts['multisite'] = true;
        $facts['layout'] = 'multisite';
        $this->refreshCapabilityEvidence($site['website'], $facts);

        $results = app(CapabilityEvaluator::class)->evaluate($site['website']->fresh(), $site['website']->activeConnector, $facts);

        $this->assertSame('PASS', $results[Capability::ConnectionReady->value]['status']);
        $this->assertSame('BLOCK', $results[Capability::UpdateReady->value]['status']);
        $this->assertContains('MULTISITE_UNSUPPORTED', $results[Capability::UpdateReady->value]['reasons']);
        $this->assertSame('BLOCK', $results[Capability::SecurityScanReady->value]['status']);
        $this->assertSame('BLOCK', $results[Capability::AdminAccessReady->value]['status']);
    }

    public function test_high_impact_action_requires_a_recent_compatibility_check_without_queue_side_effect(): void
    {
        $site = $this->pairedWebsite();
        $website = $site['website'];
        $snapshot = $website->capabilitySnapshot()->firstOrFail();
        $snapshot->update(['checked_at' => now()->subSeconds(config('compatibility.operation_ttl_seconds') + 1)]);
        $before = $website->jobs()->count();

        $this->assertSame('COMPATIBILITY_UNKNOWN_OR_STALE', app(CapabilityReadiness::class)->refusal($website->fresh(), JobType::UpdatePlugin));
        try {
            app(JobQueue::class)->queue($website->fresh(), JobType::UpdatePlugin, ['plugin' => 'akismet/akismet.php']);
            $this->fail('A high-impact action was allowed using old dynamic host facts.');
        } catch (ConnectorError $error) {
            $this->assertSame('JOB_NOT_ALLOWED', $error->errorCode);
        }

        $this->assertSame($before, $website->jobs()->count());
    }

    public function test_connector_045_is_supported_old_for_monitoring_but_cannot_pass_mutating_readiness(): void
    {
        $site = $this->pairedWebsite();
        $connector = $site['website']->activeConnector;
        $connector->update(['connector_version' => '0.4.5']);
        $this->refreshCapabilityEvidence($site['website']);

        $results = app(CapabilityEvaluator::class)->evaluate($site['website']->fresh(), $connector->fresh(), $this->inventoryPayload()['compatibility']);

        $this->assertSame('WARN', $results[Capability::MonitoringReady->value]['status']);
        $this->assertSame('BLOCK', $results[Capability::UpdateReady->value]['status']);
    }

    public function test_custom_layout_blocks_mutation_without_blocking_connection_or_monitoring(): void
    {
        $site = $this->pairedWebsite();
        $facts = $this->inventoryPayload()['compatibility'];
        $facts['layout'] = 'custom';
        $this->refreshCapabilityEvidence($site['website'], $facts);

        $results = app(CapabilityEvaluator::class)->evaluate($site['website']->fresh(), $site['website']->activeConnector, $facts);

        $this->assertSame('PASS', $results[Capability::ConnectionReady->value]['status']);
        $this->assertSame('PASS', $results[Capability::MonitoringReady->value]['status']);
        $this->assertSame('BLOCK', $results[Capability::UpdateReady->value]['status']);
        $this->assertContains('CUSTOM_LAYOUT_UNSUPPORTED', $results[Capability::RestoreReady->value]['reasons']);
    }

    public function test_disk_capacity_changes_backup_readiness_without_changing_read_only_capabilities(): void
    {
        $site = $this->pairedWebsite();
        $facts = $this->inventoryPayload()['compatibility'];
        $facts['disk_free_bytes'] = config('compatibility.disk_reserve_bytes');
        $connector = $site['website']->activeConnector;
        $connector->update(['capabilities' => array_merge($connector->capabilities, ['backup_files', 'backup_database'])]);
        $healthy = app(CapabilityEvaluator::class)->evaluate($site['website']->fresh(), $connector->fresh(), $this->inventoryPayload()['compatibility']);
        $this->assertSame('PASS', $healthy[Capability::BackupReady->value]['status']);
        $this->refreshCapabilityEvidence($site['website'], $facts);

        $results = app(CapabilityEvaluator::class)->evaluate($site['website']->fresh(), $connector->fresh(), $facts);

        $this->assertSame('PASS', $results[Capability::MonitoringReady->value]['status']);
        $this->assertSame('BLOCK', $results[Capability::BackupReady->value]['status']);
        $missingDisk = app(CapabilityEvaluator::class)->evaluate(
            $site['website']->fresh(),
            $connector->fresh(),
            array_merge($facts, ['disk_free_bytes' => null]),
        )[Capability::BackupReady->value];
        $this->assertSame('UNKNOWN', $missingDisk['status']);
    }

    public function test_scanner_readiness_does_not_claim_a_scan_was_completed(): void
    {
        $site = $this->pairedWebsite();
        $facts = $this->inventoryPayload()['compatibility'];

        $results = app(CapabilityEvaluator::class)->evaluate($site['website']->fresh(), $site['website']->activeConnector, $facts, scannerConfigured: false);

        $this->assertSame('BLOCK', $results[Capability::SecurityScanReady->value]['status']);
        $this->assertContains('SCANNER_UNAVAILABLE', $results[Capability::SecurityScanReady->value]['reasons']);
    }

    public function test_policy_change_or_expired_fact_snapshot_is_not_fresh(): void
    {
        $site = $this->pairedWebsite();
        $snapshot = $site['website']->capabilitySnapshot()->firstOrFail();

        $this->assertTrue($snapshot->isFresh());
        $snapshot->update(['checked_at' => now()->subSeconds(config('compatibility.dynamic_ttl_seconds') + 1)]);
        $this->assertFalse($snapshot->fresh()->isFresh());
        $snapshot->update(['checked_at' => now(), 'policy_version' => 'compatibility-policy-old']);
        $this->assertFalse($snapshot->fresh()->isFresh());
    }

    public function test_untested_runtime_and_database_facts_never_pass_mutating_capabilities(): void
    {
        $site = $this->pairedWebsite();
        $facts = $this->inventoryPayload()['compatibility'];
        $facts['database']['engine'] = 'mysql';
        $facts['database']['version'] = '8.0.40';
        $site['website']->update(['php_version' => '8.5.0']);
        $this->refreshCapabilityEvidence($site['website'], $facts);

        $results = app(CapabilityEvaluator::class)->evaluate($site['website']->fresh(), $site['website']->activeConnector, $facts);

        $this->assertSame('UNKNOWN', $results[Capability::UpdateReady->value]['status']);
        $this->assertContains('RUNTIME_VERSION_UNTESTED', $results[Capability::UpdateReady->value]['reasons']);
        $databaseBackup = $results[Capability::BackupReady->value]['scopes']['database'];
        $this->assertNotSame('PASS', $databaseBackup['status']);
        $this->assertContains('DB_VERSION_UNKNOWN', $databaseBackup['reasons']);
    }

    public function test_unknown_nested_connector_fact_is_rejected_before_persistence(): void
    {
        $site = $this->pairedWebsite();
        $payload = $this->inventoryPayload();
        $payload['compatibility']['database']['password'] = 'must-not-persist';

        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.inventory'), $payload)
            ->assertUnprocessable();

        $facts = $site['website']->fresh()->capabilitySnapshot->facts;
        $this->assertArrayNotHasKey('password', $facts['database']);
    }

    public function test_customer_diagnostics_are_scoped_and_show_unknown_when_no_snapshot_exists(): void
    {
        $site = $this->pairedWebsite();
        $website = $site['website'];
        $website->capabilitySnapshot()->delete();

        $response = $this->actingAs($website->customer->user)
            ->get(route('account.websites.show', $website))
            ->assertOk()
            ->assertSee(__('compatibility.title'))
            ->assertSee(__('compatibility.status.UNKNOWN'))
            ->assertSee(__('compatibility.request_check'));

        $response->assertDontSee('DB_PASSWORD')->assertDontSee('/var/www/');
    }

    public function test_operator_diagnostics_are_scoped_and_show_redacted_technical_facts(): void
    {
        $site = $this->pairedWebsite();
        $website = $site['website'];
        $operator = User::factory()->operator([Permission::WebsitesView])->create();

        $response = $this->actingAs($operator, 'operator')
            ->get(route('operator.websites.show', $website))
            ->assertOk()
            ->assertSee(__('compatibility.operator_details'))
            ->assertSee($website->capabilitySnapshot->policy_version)
            ->assertSee($website->activeConnector->connector_version);

        $response->assertDontSee('DB_PASSWORD')->assertDontSee('/var/www/');
    }
}
