<?php

namespace Tests\Feature\Compatibility;

use App\Compatibility\Capability;
use App\Compatibility\CapabilityEvaluator;
use App\Compatibility\CapabilityReadiness;
use App\Compatibility\CapabilitySnapshotStore;
use App\Connector\ConnectorError;
use App\Connector\InventoryIngest;
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

    public function test_restore_provider_readiness_requires_fresh_allowlisted_identity(): void
    {
        config(['compatibility.restore_isolation_provider_ids' => ['tests.fake-host-isolation']]);
        $site = $this->pairedWebsite();
        $website = $site['website'];
        $readiness = app(CapabilityReadiness::class);

        $this->assertTrue($readiness->restoreIsolationSupported($website->fresh()));

        $facts = $website->capabilitySnapshot->facts;
        unset($facts['restore_isolation_provider_id']);
        $this->refreshCapabilityEvidence($website, $facts);
        $this->assertFalse($readiness->restoreIsolationSupported($website->fresh()), 'Missing provider identity is unsupported.');

        $facts['restore_isolation_provider_id'] = 'unreviewed.provider';
        $this->refreshCapabilityEvidence($website, $facts);
        $this->assertFalse($readiness->restoreIsolationSupported($website->fresh()), 'An unreviewed provider identity is not accepted.');

        $facts['restore_isolation_provider_id'] = 'tests.fake-host-isolation';
        $this->refreshCapabilityEvidence($website, $facts);
        $website->fresh()->capabilitySnapshot->update(['checked_at' => now()->subSeconds(config('compatibility.operation_ttl_seconds') + 1)]);
        $this->assertFalse($readiness->restoreIsolationSupported($website->fresh()), 'Stale provider evidence is unsupported.');
    }

    public function test_unknown_restore_isolation_does_not_disable_backup_readiness(): void
    {
        config(['compatibility.restore_isolation_provider_ids' => []]);
        $site = $this->pairedWebsite();
        $website = $site['website'];
        $connector = $website->activeConnector;
        $connector->update(['capabilities' => array_merge($connector->capabilities, ['backup_files', 'backup_database', 'backup_full', 'restore_full'])]);
        $facts = $this->inventoryPayload()['compatibility'];
        unset($facts['restore_isolation_provider_id']);

        $results = app(CapabilityEvaluator::class)->evaluate($website->fresh(), $connector->fresh(), $facts);

        $this->assertSame('PASS', $results[Capability::BackupReady->value]['status']);
        $this->assertSame('UNKNOWN', $results[Capability::RestoreReady->value]['status']);
        $this->assertContains('FACT_MISSING', $results[Capability::RestoreReady->value]['reasons']);
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

    public function test_connector_capability_changes_invalidate_the_evaluated_snapshot(): void
    {
        $site = $this->pairedWebsite();
        $website = $site['website'];
        $before = $website->jobs()->count();
        $connector = $website->activeConnector;
        $connector->update(['capabilities' => array_values(array_diff($connector->capabilities, ['updates']))]);

        $this->assertSame('COMPATIBILITY_UNKNOWN_OR_STALE', app(CapabilityReadiness::class)->refusal($website->fresh(), JobType::UpdatePlugin));
        $this->assertSame($before, $website->jobs()->count());

        $this->refreshCapabilityEvidence($website);
        $this->assertSame('BLOCK', $website->fresh()->capabilitySnapshot->status(Capability::UpdateReady)->value);
    }

    public function test_capability_transition_history_retains_only_the_latest_fifty_rows(): void
    {
        $site = $this->pairedWebsite();
        $website = $site['website'];
        $connector = $website->activeConnector;

        for ($index = 0; $index < 55; $index++) {
            $capabilities = $connector->capabilities;
            if ($index % 2 === 0) {
                $capabilities = array_values(array_diff($capabilities, ['inventory']));
            } else {
                $capabilities[] = 'inventory';
            }
            $connector->update(['capabilities' => $capabilities]);
            app(CapabilitySnapshotStore::class)->refresh($website->fresh(), $connector->fresh(), $this->inventoryPayload()['compatibility']);
        }

        $this->assertSame(50, $website->capabilityTransitions()->count());
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

    public function test_delayed_inventory_does_not_refresh_compatibility_readiness(): void
    {
        $site = $this->pairedWebsite();
        $website = $site['website'];
        $collectedAt = now()->subSeconds(config('compatibility.dynamic_ttl_seconds') + 1);
        $payload = $this->inventoryPayload();
        $payload['collected_at'] = $collectedAt->toIso8601String();
        app(InventoryIngest::class)->store($website->activeConnector, $payload);

        $snapshot = $website->fresh()->capabilitySnapshot;
        $this->assertSame($collectedAt->timestamp, $snapshot->checked_at->timestamp);
        $this->assertFalse($snapshot->isFresh());

        $before = $website->jobs()->count();
        try {
            app(JobQueue::class)->queue($website->fresh(), JobType::UpdatePlugin, ['plugin' => 'akismet/akismet.php']);
            $this->fail('A delayed inventory refreshed readiness for a high-impact action.');
        } catch (ConnectorError $error) {
            $this->assertSame('JOB_NOT_ALLOWED', $error->errorCode);
        }

        $this->assertSame($before, $website->jobs()->count());
    }

    public function test_missing_or_future_inventory_timestamp_cannot_refresh_compatibility_readiness(): void
    {
        $site = $this->pairedWebsite();
        $website = $site['website'];
        $connector = $website->activeConnector;

        foreach ([null, now()->addDay()->toIso8601String()] as $collectedAt) {
            $payload = $this->inventoryPayload();
            if ($collectedAt === null) {
                unset($payload['collected_at']);
            } else {
                $payload['collected_at'] = $collectedAt;
            }

            app(InventoryIngest::class)->store($connector->fresh(), $payload);

            $snapshot = $website->fresh()->capabilitySnapshot;
            $this->assertFalse($snapshot->isFresh());
            $this->assertLessThanOrEqual(now()->timestamp, $snapshot->checked_at->timestamp);
        }
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
        $facts['database']['version'] = '5.6.51';
        $site['website']->update(['php_version' => '8.6.0']);
        $this->refreshCapabilityEvidence($site['website'], $facts);

        $results = app(CapabilityEvaluator::class)->evaluate($site['website']->fresh(), $site['website']->activeConnector, $facts);

        $this->assertSame('UNKNOWN', $results[Capability::UpdateReady->value]['status']);
        $this->assertContains('RUNTIME_VERSION_UNTESTED', $results[Capability::UpdateReady->value]['reasons']);
        $databaseBackup = $results[Capability::BackupReady->value]['scopes']['database'];
        $this->assertNotSame('PASS', $databaseBackup['status']);
        $this->assertContains('DB_VERSION_UNKNOWN', $databaseBackup['reasons']);
    }

    public function test_mysql_below_the_wordpress_floor_is_blocked_from_database_backup(): void
    {
        $site = $this->pairedWebsite();
        $connector = $site['website']->activeConnector;
        $connector->update(['capabilities' => array_merge($connector->capabilities, ['backup_database'])]);
        $facts = $this->inventoryPayload()['compatibility'];
        $facts['database']['engine'] = 'mysql';
        $facts['database']['version'] = '5.5.4';
        $this->refreshCapabilityEvidence($site['website'], $facts);

        $results = app(CapabilityEvaluator::class)->evaluate(
            $site['website']->fresh(),
            $connector->fresh(),
            $facts,
        );

        $databaseBackup = $results[Capability::BackupReady->value]['scopes']['database'];
        $this->assertSame('BLOCK', $databaseBackup['status']);
        $this->assertContains('DB_VERSION_UNSUPPORTED', $databaseBackup['reasons']);
    }

    public function test_ci_tested_mysql_57_and_80_branches_enable_database_backup_only_with_other_evidence(): void
    {
        $site = $this->pairedWebsite();
        $website = $site['website'];
        $connector = $website->activeConnector;
        $connector->update(['capabilities' => array_merge($connector->capabilities, ['backup_database'])]);
        $facts = $this->inventoryPayload()['compatibility'];

        foreach (['5.7.44', '8.0.46'] as $version) {
            $facts['database']['engine'] = 'mysql';
            $facts['database']['version'] = $version;
            $this->refreshCapabilityEvidence($website, $facts);
            $results = app(CapabilityEvaluator::class)->evaluate($website->fresh(), $connector->fresh(), $facts);

            $this->assertSame('PASS', $results[Capability::BackupReady->value]['scopes']['database']['status'], $version);
        }
    }

    public function test_php_81_is_explicitly_end_of_life_and_never_passes_capability_gates(): void
    {
        $site = $this->pairedWebsite();
        $site['website']->update(['php_version' => '8.1.33']);
        $facts = $this->inventoryPayload()['compatibility'];

        $results = app(CapabilityEvaluator::class)->evaluate(
            $site['website']->fresh(),
            $site['website']->activeConnector,
            $facts,
        );

        foreach ([Capability::SecurityScanReady, Capability::BackupReady, Capability::RestoreReady, Capability::UpdateReady] as $capability) {
            $this->assertNotSame('PASS', $results[$capability->value]['status']);
            $this->assertContains('RUNTIME_VERSION_EOL', $results[$capability->value]['reasons']);
        }
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

    public function test_pass_without_reason_uses_correct_copy_in_both_locales_and_stale_fails_closed(): void
    {
        $site = $this->pairedWebsite();
        $website = $site['website'];
        $operator = User::factory()->operator([Permission::WebsitesView])->create();
        $snapshot = $website->capabilitySnapshot()->firstOrFail();
        $capabilities = $snapshot->capabilities;
        $capabilities[Capability::SecurityScanReady->value] = ['status' => 'PASS', 'reasons' => []];
        $snapshot->update(['capabilities' => $capabilities, 'checked_at' => now()]);

        foreach (['en', 'hu'] as $locale) {
            $expected = [
                trans('compatibility.capabilities.SECURITY_SCAN_READY', [], $locale),
                trans('compatibility.reasons.PASS_NO_ISSUES', [], $locale),
                trans('compatibility.status.PASS', [], $locale),
            ];
            $checkRequired = trans('compatibility.reasons.CHECK_REQUIRED', [], $locale);

            $this->actingAs($website->customer->user)
                ->withCookie((string) config('localization.cookie'), $locale)
                ->get(route('account.websites.show', $website))
                ->assertOk()
                ->assertSeeInOrder($expected)
                ->assertDontSee($checkRequired);
        }

        // Staff areas are intentionally English-only; the locale cookie must not change them.
        $operatorExpected = [
            trans('compatibility.capabilities.SECURITY_SCAN_READY', [], 'en'),
            trans('compatibility.reasons.PASS_NO_ISSUES', [], 'en'),
            trans('compatibility.status.PASS', [], 'en'),
        ];
        $operatorCheckRequired = trans('compatibility.reasons.CHECK_REQUIRED', [], 'en');

        $this->actingAs($operator, 'operator')
            ->withCookie((string) config('localization.cookie'), 'hu')
            ->get(route('operator.websites.show', $website))
            ->assertOk()
            ->assertSeeInOrder($operatorExpected)
            ->assertDontSee($operatorCheckRequired);

        $snapshot->update(['checked_at' => now()->subSeconds((int) config('compatibility.dynamic_ttl_seconds') + 1)]);
        $staleExpected = [
            trans('compatibility.capabilities.SECURITY_SCAN_READY', [], 'en'),
            trans('compatibility.reasons.FACT_STALE', [], 'en'),
            trans('compatibility.status.UNKNOWN', [], 'en'),
        ];

        $this->actingAs($website->customer->user)
            ->withCookie((string) config('localization.cookie'), 'en')
            ->get(route('account.websites.show', $website))
            ->assertOk()
            ->assertSeeInOrder($staleExpected);

        $this->actingAs($operator, 'operator')
            ->withCookie((string) config('localization.cookie'), 'en')
            ->get(route('operator.websites.show', $website))
            ->assertOk()
            ->assertSeeInOrder($staleExpected);
    }

    public function test_compatibility_copy_preserves_known_reasons_and_handles_missing_or_unknown_details(): void
    {
        $site = $this->pairedWebsite();
        $website = $site['website'];
        $operator = User::factory()->operator([Permission::WebsitesView])->create();
        $snapshot = $website->capabilitySnapshot()->firstOrFail();

        $cases = [
            ['status' => 'PASS', 'reasons' => ['CONNECTOR_UPDATE_RECOMMENDED'], 'reason' => 'CONNECTOR_UPDATE_RECOMMENDED'],
            ['status' => 'WARN', 'reasons' => [], 'reason' => 'DETAIL_UNAVAILABLE'],
            ['status' => 'BLOCK', 'reasons' => [], 'reason' => 'DETAIL_UNAVAILABLE'],
            ['status' => 'UNKNOWN', 'reasons' => [], 'reason' => 'CHECK_REQUIRED'],
            ['status' => 'PASS', 'reasons' => ['UNRECOGNIZED_REASON'], 'reason' => 'DETAIL_UNAVAILABLE'],
        ];

        foreach ($cases as $case) {
            $capabilities = $snapshot->fresh()->capabilities;
            $capabilities[Capability::SecurityScanReady->value] = [
                'status' => $case['status'],
                'reasons' => $case['reasons'],
            ];
            $snapshot->update(['capabilities' => $capabilities, 'checked_at' => now()]);

            foreach (['en', 'hu'] as $locale) {
                $expected = [
                    trans('compatibility.capabilities.SECURITY_SCAN_READY', [], $locale),
                    trans('compatibility.reasons.'.$case['reason'], [], $locale),
                    trans('compatibility.status.'.$case['status'], [], $locale),
                ];

                $this->actingAs($website->customer->user)
                    ->withCookie((string) config('localization.cookie'), $locale)
                    ->get(route('account.websites.show', $website))
                    ->assertOk()
                    ->assertSeeInOrder($expected);
            }

            // Operator pages deliberately stay English regardless of device locale.
            $operatorExpected = [
                trans('compatibility.capabilities.SECURITY_SCAN_READY', [], 'en'),
                trans('compatibility.reasons.'.$case['reason'], [], 'en'),
                trans('compatibility.status.'.$case['status'], [], 'en'),
            ];

            $this->actingAs($operator, 'operator')
                ->withCookie((string) config('localization.cookie'), 'hu')
                ->get(route('operator.websites.show', $website))
                ->assertOk()
                ->assertSeeInOrder($operatorExpected);
        }
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
