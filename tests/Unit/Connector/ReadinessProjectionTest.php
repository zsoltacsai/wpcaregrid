<?php

namespace Tests\Unit\Connector;

use App\Connector\ReadinessProjection;
use App\Models\WebsiteInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PairsConnectors;
use Tests\TestCase;

final class ReadinessProjectionTest extends TestCase
{
    use PairsConnectors, RefreshDatabase;

    public function test_fresh_persisted_evidence_is_projected_without_claiming_a_live_connector(): void
    {
        $site = $this->pairedWebsite();
        $website = $site['website'];
        $connector = $website->activeConnector;
        $now = now()->subSecond();
        $connector->update([
            'last_seen_at' => $now,
            'last_heartbeat_at' => $now,
            'last_successful_poll_at' => $now,
            'last_inventory_at' => $now,
        ]);
        WebsiteInventory::create([
            'website_id' => $website->id,
            'connector_id' => $connector->id,
            'core' => ['version' => '7.1.2'],
            'plugins' => [],
            'themes' => [],
            'collected_at' => $now,
        ]);

        $projection = app(ReadinessProjection::class)->project($website, $connector);

        $this->assertSame('VERIFIED', $projection['connection']['state']);
        $this->assertSame($now->toIso8601String(), $projection['connection']['last_activity_at']);
        $this->assertSame($now->toIso8601String(), $projection['connection']['last_heartbeat_at']);
        $this->assertSame('VERIFIED', $projection['connection']['heartbeat_state']);
        $this->assertSame($now->toIso8601String(), $projection['connection']['last_successful_poll_at']);
        $this->assertSame('VERIFIED', $projection['connection']['poll_state']);
        $this->assertSame($now->toIso8601String(), $projection['inventory']['collected_at']);
        $this->assertSame($now->toIso8601String(), $projection['inventory']['ingested_at']);
        $this->assertSame('VERIFIED', $projection['inventory']['collection_state']);
        $this->assertSame('VERIFIED', $projection['capability_snapshot']['state']);
        $this->assertSame('UNKNOWN', $projection['jobs']['running']['state']);
    }

    public function test_missing_evidence_is_unknown_not_not_configured(): void
    {
        $site = $this->pairedWebsite();
        $website = $site['website'];
        $connector = $website->activeConnector;
        $connector->update([
            'last_seen_at' => null,
            'last_heartbeat_at' => null,
            'last_successful_poll_at' => null,
            'last_inventory_at' => null,
        ]);
        $website->capabilitySnapshot()->delete();

        $projection = app(ReadinessProjection::class)->project($website, $connector);

        $this->assertSame('UNKNOWN', $projection['connection']['state']);
        $this->assertSame('UNKNOWN', $projection['connection']['heartbeat_state']);
        $this->assertSame('UNKNOWN', $projection['connection']['poll_state']);
        $this->assertSame('UNKNOWN', $projection['inventory']['collection_state']);
        $this->assertSame('UNKNOWN', $projection['inventory']['ingestion_state']);
        $this->assertSame('UNKNOWN', $projection['capability_snapshot']['state']);
        $this->assertNotContains('NOT_CONFIGURED', $projection['capability_snapshot']['capabilities']);
    }

    public function test_stale_and_future_timestamps_fail_closed(): void
    {
        $site = $this->pairedWebsite();
        $website = $site['website'];
        $connector = $website->activeConnector;
        $stale = now()->subSeconds((int) config('connector.stale_after') + 1);
        $future = now()->addMinute();
        $connector->update([
            'last_seen_at' => $stale,
            'last_heartbeat_at' => $stale,
            'last_successful_poll_at' => $future,
            'last_inventory_at' => $stale,
        ]);
        WebsiteInventory::create([
            'website_id' => $website->id,
            'connector_id' => $connector->id,
            'core' => ['version' => '7.1.2'],
            'plugins' => [],
            'themes' => [],
            'collected_at' => $stale,
        ]);
        $website->capabilitySnapshot()->update(['checked_at' => $future]);

        $projection = app(ReadinessProjection::class)->project($website, $connector);

        $this->assertSame('STALE', $projection['connection']['state']);
        $this->assertSame('STALE', $projection['connection']['heartbeat_state']);
        $this->assertSame('UNKNOWN', $projection['connection']['poll_state']);
        $this->assertSame('STALE', $projection['inventory']['collection_state']);
        $this->assertSame('STALE', $projection['inventory']['ingestion_state']);
        $this->assertSame('UNKNOWN', $projection['capability_snapshot']['state']);
        $this->assertSame('UNKNOWN', $projection['capability_snapshot']['capabilities']['UPDATE_READY']);
    }

    public function test_future_inventory_timestamps_are_unknown(): void
    {
        $site = $this->pairedWebsite();
        $website = $site['website'];
        $connector = $website->activeConnector;
        $future = now()->addMinute();
        $connector->update(['last_inventory_at' => $future]);
        WebsiteInventory::create([
            'website_id' => $website->id,
            'connector_id' => $connector->id,
            'core' => ['version' => '7.1.2'],
            'plugins' => [],
            'themes' => [],
            'collected_at' => $future,
        ]);

        $projection = app(ReadinessProjection::class)->project($website, $connector);

        $this->assertSame('UNKNOWN', $projection['inventory']['collection_state']);
        $this->assertSame('UNKNOWN', $projection['inventory']['ingestion_state']);
    }

    public function test_expired_or_connector_mismatched_snapshot_is_stale(): void
    {
        $site = $this->pairedWebsite();
        $website = $site['website'];
        $connector = $website->activeConnector;
        $snapshot = $website->capabilitySnapshot;

        $snapshot->update(['checked_at' => now()->subSeconds((int) config('compatibility.dynamic_ttl_seconds') + 1)]);
        $expired = app(ReadinessProjection::class)->project($website, $connector);
        $this->assertSame('STALE', $expired['capability_snapshot']['state']);

        $this->refreshCapabilityEvidence($website);
        $snapshot = $website->capabilitySnapshot()->firstOrFail();
        $snapshot->update(['connector_id' => null]);
        $mismatched = app(ReadinessProjection::class)->project($website, $connector);

        $this->assertSame('STALE', $mismatched['capability_snapshot']['state']);
        $this->assertSame('UNKNOWN', $mismatched['capability_snapshot']['capabilities']['UPDATE_READY']);
    }

    public function test_wrong_connector_site_pair_returns_unknown_without_cross_site_data(): void
    {
        $first = $this->pairedWebsite('https://first.example');
        $second = $this->pairedWebsite('https://second.example');
        $secondConnector = $second['website']->activeConnector;
        $secondConnector->update(['last_seen_at' => now(), 'last_heartbeat_at' => now()]);

        $projection = app(ReadinessProjection::class)->project($first['website'], $secondConnector);

        $this->assertSame('UNKNOWN', $projection['connection']['state']);
        $this->assertNull($projection['connection']['last_activity_at']);
        $this->assertSame('UNKNOWN', $projection['capability_snapshot']['state']);
        $this->assertSame(0, $projection['jobs']['queued']);
        $this->assertSame(0, $projection['jobs']['delivered']);
    }
}
