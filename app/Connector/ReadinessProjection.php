<?php

namespace App\Connector;

use App\Compatibility\Capability;
use App\Compatibility\CapabilitySnapshot;
use App\Models\Connector;
use App\Models\Website;
use App\Models\WebsiteInventory;
use App\Models\WebsiteJob;
use Illuminate\Support\Carbon;

/**
 * A read-only projection of persisted Connector readiness evidence.
 *
 * This is deliberately not an authorization or dispatch decision. Every
 * operation must re-check its own policy at the point it is requested.
 */
final class ReadinessProjection
{
    /** @return array<string, mixed> */
    public function project(Website $requestedWebsite, Connector $requestedConnector): array
    {
        $websiteId = $requestedWebsite->getKey();
        $connectorId = $requestedConnector->getKey();

        if (! $websiteId || ! $connectorId) {
            return $this->unknown();
        }

        // Re-read only the columns needed, so unsaved caller-side model changes
        // and credential material can never become readiness evidence/output.
        $website = Website::query()
            ->select(['id', 'wordpress_version', 'php_version'])
            ->whereKey($websiteId)
            ->first();

        if (! $website) {
            return $this->unknown();
        }

        $connector = Connector::query()
            ->select([
                'id', 'website_id', 'status', 'connector_version', 'capabilities',
                'last_seen_at', 'last_heartbeat_at', 'last_successful_poll_at', 'last_inventory_at',
            ])
            ->whereKey($connectorId)
            ->where('website_id', $website->id)
            ->first();

        if (! $connector) {
            return $this->unknown();
        }

        $activityState = $this->activityState($connector);
        $heartbeatState = $this->timestampState($connector->last_heartbeat_at, (int) config('connector.stale_after'));
        $pollState = $this->timestampState($connector->last_successful_poll_at, (int) config('connector.stale_after'));
        $inventoryTtl = (int) config('compatibility.dynamic_ttl_seconds');

        $inventory = WebsiteInventory::query()
            ->select(['id', 'website_id', 'connector_id', 'collected_at'])
            ->where('website_id', $website->id)
            ->where('connector_id', $connector->id)
            ->first();

        $collectedAt = $inventory?->collected_at;
        $ingestedAt = $connector->last_inventory_at;

        $snapshot = CapabilitySnapshot::query()
            ->select([
                'id', 'website_id', 'connector_id', 'connector_version',
                'connector_capabilities_hash', 'wordpress_version', 'php_version',
                'policy_version', 'evaluator_version', 'checked_at', 'capabilities',
            ])
            ->where('website_id', $website->id)
            ->first();

        [$snapshotState, $snapshotReason] = $this->snapshotState($snapshot, $website, $connector);
        $capabilities = [];
        foreach (Capability::cases() as $capability) {
            $capabilities[$capability->value] = $snapshotState === 'VERIFIED'
                ? ($snapshot?->status($capability)->value ?? 'UNKNOWN')
                : 'UNKNOWN';
        }

        $jobs = $this->jobCounts((int) $website->id, (int) $connector->id);

        return [
            'connection' => [
                // VERIFIED describes only fresh last-seen evidence, never a
                // claim that a Connector process is currently running.
                'activity_state' => $activityState,
                'last_activity_at' => $this->timestamp($connector->last_seen_at),
                // This records a heartbeat accepted by the application. It does
                // not prove that the Connector received the response.
                'last_heartbeat_at' => $this->timestamp($connector->last_heartbeat_at),
                'heartbeat_state' => $heartbeatState,
                'last_successful_poll_at' => $this->timestamp($connector->last_successful_poll_at),
                'poll_state' => $pollState,
                'connector_version' => $connector->connector_version,
            ],
            'inventory' => [
                'collected_at' => $this->timestamp($collectedAt),
                'collection_state' => $this->timestampState($collectedAt, $inventoryTtl),
                // last_inventory_at is the server-side ingestion time, not the
                // time at which WordPress collected the inventory.
                'ingested_at' => $this->timestamp($ingestedAt),
                'ingestion_state' => $this->timestampState($ingestedAt, $inventoryTtl),
            ],
            'capability_snapshot' => [
                'state' => $snapshotState,
                'reason' => $snapshotReason,
                'checked_at' => $this->timestamp($snapshot?->checked_at),
                'capabilities' => $capabilities,
            ],
            'jobs' => $jobs + [
                // WebsiteJob has no authoritative running status. Delivered is
                // only handoff evidence, not proof that execution has started.
                'running' => ['state' => 'UNKNOWN', 'reason' => 'NO_PERSISTED_RUNNING_STATE'],
            ],
        ];
    }

    /** @return array{queued: int, waiting: int, delivered: int} */
    private function jobCounts(int $websiteId, int $connectorId): array
    {
        $counts = WebsiteJob::query()
            ->where('website_id', $websiteId)
            ->where(function ($query) use ($connectorId) {
                $query->whereIn('status', [JobStatus::Queued->value, JobStatus::Waiting->value])
                    ->orWhere(function ($delivered) use ($connectorId) {
                        $delivered->where('status', JobStatus::Delivered->value)
                            ->where('connector_id', $connectorId);
                    });
            })
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'queued' => (int) ($counts[JobStatus::Queued->value] ?? 0),
            'waiting' => (int) ($counts[JobStatus::Waiting->value] ?? 0),
            'delivered' => (int) ($counts[JobStatus::Delivered->value] ?? 0),
        ];
    }

    private function activityState(Connector $connector): string
    {
        if (! $connector->isActive()) {
            return 'REVOKED';
        }

        if ($connector->last_seen_at && $connector->last_seen_at->greaterThan(now())) {
            return 'UNKNOWN';
        }

        return match ($connector->presence()) {
            Presence::Connected => 'VERIFIED',
            Presence::Stale, Presence::Disconnected => 'STALE',
            Presence::NeverConnected => 'UNKNOWN',
        };
    }

    private function timestampState(?Carbon $timestamp, int $ttlSeconds): string
    {
        if (! $timestamp || $timestamp->greaterThan(now())) {
            return 'UNKNOWN';
        }

        return $timestamp->greaterThanOrEqualTo(now()->subSeconds(max(0, $ttlSeconds)))
            ? 'VERIFIED'
            : 'STALE';
    }

    /** @return array{string, ?string} */
    private function snapshotState(?CapabilitySnapshot $snapshot, Website $website, Connector $connector): array
    {
        if (! $snapshot || ! $snapshot->checked_at) {
            return ['UNKNOWN', 'SNAPSHOT_MISSING'];
        }

        if ($snapshot->checked_at->greaterThan(now())) {
            return ['UNKNOWN', 'FUTURE_TIMESTAMP'];
        }

        if (! $snapshot->isFresh()) {
            return ['STALE', 'SNAPSHOT_STALE'];
        }

        if (! $snapshot->matchesCurrent($website, $connector)) {
            return ['STALE', 'SNAPSHOT_MISMATCH'];
        }

        return ['VERIFIED', null];
    }

    private function timestamp(?Carbon $timestamp): ?string
    {
        return $timestamp?->toIso8601String();
    }

    /** @return array<string, mixed> */
    private function unknown(): array
    {
        $capabilities = [];
        foreach (Capability::cases() as $capability) {
            $capabilities[$capability->value] = 'UNKNOWN';
        }

        return [
            'connection' => [
                'activity_state' => 'UNKNOWN',
                'last_activity_at' => null,
                'last_heartbeat_at' => null,
                'heartbeat_state' => 'UNKNOWN',
                'last_successful_poll_at' => null,
                'poll_state' => 'UNKNOWN',
                'connector_version' => null,
            ],
            'inventory' => [
                'collected_at' => null,
                'collection_state' => 'UNKNOWN',
                'ingested_at' => null,
                'ingestion_state' => 'UNKNOWN',
            ],
            'capability_snapshot' => [
                'state' => 'UNKNOWN',
                'reason' => 'REQUESTED_IDENTITY_NOT_FOUND',
                'checked_at' => null,
                'capabilities' => $capabilities,
            ],
            'jobs' => [
                'queued' => 0,
                'waiting' => 0,
                'delivered' => 0,
                'running' => ['state' => 'UNKNOWN', 'reason' => 'NO_PERSISTED_RUNNING_STATE'],
            ],
        ];
    }
}
