<?php

namespace App\Cloudflare;

use Illuminate\Support\Arr;

/** Zone-scoped DNS operations. Zone IDs are always resolved from an approved exact name. */
final class CloudflareDnsService
{
    private const ALWAYS_PROTECTED_NAMES = [
        'wpcaregrid.com', 'www.wpcaregrid.com', 'staging.wpcaregrid.com',
        'stg1.wpcaregrid.com', 'mail.wpcaregrid.com', 'wp-test.staging.wpcaregrid.com',
    ];

    public function __construct(private readonly CloudflareApiClient $client) {}

    /** @return list<string> */
    public function allowedZones(): array
    {
        $zones = config('cloudflare.allowed_zones', []);
        if (! is_array($zones)) {
            return [];
        }

        $normalized = [];
        foreach ($zones as $zone) {
            try {
                $normalized[] = DnsNames::normalizeZone((string) $zone);
            } catch (CloudflareDnsException) {
                continue;
            }
        }

        return array_values(array_unique($normalized));
    }

    /** Read-only token + approved zone + DNS read diagnostic. */
    public function diagnose(): array
    {
        $this->client->verifyToken();
        $approvedZones = $this->allowedZones();
        if ($approvedZones === []) {
            throw new CloudflareDnsException('zone_not_allowed');
        }
        $zones = [];
        foreach ($approvedZones as $name) {
            $zone = $this->resolveZone($name);
            $this->client->records((string) $zone['id'], firstPageOnly: true);
            $zones[] = ['name' => $name, 'reachable' => true];
        }

        return ['token' => 'accepted', 'dns_read' => 'available', 'dns_edit' => 'unknown', 'zones' => $zones];
    }

    /** @return list<array<string, mixed>> Safe public DNS data only. */
    public function listRecords(string $zoneName): array
    {
        $zone = $this->resolveZone($zoneName);
        $zoneName = DnsNames::normalizeZone($zoneName);
        $records = $this->client->records((string) $zone['id']);
        $this->assertRecordsBelongToZone($zoneName, (string) $zone['id'], $records);

        return array_map(fn (array $record): array => $this->publicRecord($record), $records);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function planEnsure(string $zoneName, array $input): array
    {
        $zoneName = DnsNames::normalizeZone($zoneName);
        $zone = $this->resolveZone($zoneName);
        $desired = DnsRecordData::fromInput($zoneName, $input);
        $zoneRecords = $this->client->records((string) $zone['id']);
        $this->assertRecordsBelongToZone($zoneName, (string) $zone['id'], $zoneRecords);
        $sameName = array_values(array_filter($zoneRecords, fn (array $record): bool => strtolower(rtrim((string) ($record['name'] ?? ''), '.')) === $desired->name
        ));
        $cnameConflict = $desired->type === 'CNAME'
            ? array_filter($sameName, fn (array $record): bool => strtoupper((string) ($record['type'] ?? '')) !== 'CNAME') !== []
            : array_filter($sameName, fn (array $record): bool => strtoupper((string) ($record['type'] ?? '')) === 'CNAME') !== [];
        if ($cnameConflict) {
            throw new CloudflareDnsException('record_conflict');
        }
        if ($desired->type === 'CNAME' && count($sameName) > 1) {
            throw new CloudflareDnsException('record_conflict');
        }
        $requestedId = $input['record_id'] ?? null;
        if (is_string($requestedId) && $requestedId !== '') {
            $matches = array_values(array_filter($zoneRecords, fn (array $record): bool => hash_equals((string) ($record['id'] ?? ''), $requestedId)));
            if (count($matches) !== 1) {
                throw new CloudflareDnsException('record_not_found');
            }
            if (strtoupper((string) ($matches[0]['type'] ?? '')) !== $desired->type
                || strtolower(rtrim((string) ($matches[0]['name'] ?? ''), '.')) !== $desired->name) {
                throw new CloudflareDnsException('invalid_record');
            }
        } else {
            $matches = array_values(array_filter($sameName, function (array $record) use ($desired): bool {
                if (strtoupper((string) ($record['type'] ?? '')) !== $desired->type) {
                    return false;
                }
                if ($desired->type === 'CNAME') {
                    return true;
                }

                return strtolower(rtrim((string) ($record['content'] ?? ''), '.')) === strtolower(rtrim($desired->content, '.'));
            }));
        }

        if (count($matches) > 1) {
            throw new CloudflareDnsException('record_conflict');
        }
        $current = $matches[0] ?? null;
        $desiredPayload = $desired->payload();
        $action = $current === null ? 'created' : ($this->sameConfiguration($current, $desiredPayload) ? 'unchanged' : 'updated');
        if ($action !== 'unchanged' && $this->isProtectedName($desired->name)) {
            throw new CloudflareDnsException('record_protected');
        }

        return [
            'action' => $action,
            'zone' => $zoneName,
            'record_id' => $current['id'] ?? null,
            'before' => $current ? $this->publicRecord($current) : null,
            'after' => $desiredPayload,
        ];
    }

    /** Re-plans immediately before applying to avoid stale previews. */
    public function applyEnsure(string $zoneName, array $input, array $expectedPlan): array
    {
        $plan = $this->planEnsure($zoneName, $input);
        if ($plan !== $expectedPlan) {
            throw new CloudflareDnsException('record_conflict');
        }
        if ($plan['action'] === 'unchanged') {
            return $plan;
        }
        $zone = $this->resolveZone($zoneName);
        $record = $plan['action'] === 'created'
            ? $this->client->createRecord((string) $zone['id'], $plan['after'])
            : $this->client->updateRecord((string) $zone['id'], (string) $plan['record_id'], $plan['after']);

        return $plan + ['result' => $this->publicRecord($record)];
    }

    public function planDelete(string $zoneName, string $recordId): array
    {
        $zoneName = DnsNames::normalizeZone($zoneName);
        $zone = $this->resolveZone($zoneName);
        $records = $this->client->records((string) $zone['id']);
        $this->assertRecordsBelongToZone($zoneName, (string) $zone['id'], $records);
        $matches = array_values(array_filter($records, fn (array $record): bool => hash_equals((string) ($record['id'] ?? ''), $recordId)
        ));
        if (count($matches) !== 1) {
            throw new CloudflareDnsException('record_not_found');
        }

        $record = $this->publicRecord($matches[0]);
        if (! in_array(strtoupper($record['type']), ['A', 'AAAA', 'CNAME', 'TXT', 'MX'], true)) {
            throw new CloudflareDnsException('invalid_record');
        }
        if ($this->isProtectedName($record['name'])) {
            throw new CloudflareDnsException('record_protected');
        }

        return ['action' => 'deleted', 'zone' => $zoneName, 'record_id' => $recordId, 'before' => $record];
    }

    public function applyDelete(string $zoneName, string $recordId, array $expectedPlan): array
    {
        $plan = $this->planDelete($zoneName, $recordId);
        if ($plan !== $expectedPlan) {
            throw new CloudflareDnsException('record_conflict');
        }
        $zone = $this->resolveZone($zoneName);
        $this->client->deleteRecord((string) $zone['id'], $recordId);

        return $plan;
    }

    /** @return array<string, mixed> */
    private function resolveZone(string $zoneName): array
    {
        $zoneName = DnsNames::normalizeZone($zoneName);
        if (! in_array($zoneName, $this->allowedZones(), true)) {
            throw new CloudflareDnsException('zone_not_allowed');
        }
        $matches = array_values(array_filter($this->client->zones(['name' => $zoneName]), fn (array $zone): bool => strtolower(rtrim((string) ($zone['name'] ?? ''), '.')) === $zoneName && is_string($zone['id'] ?? null) && $zone['id'] !== ''
        ));
        if ($matches === []) {
            throw new CloudflareDnsException('zone_not_found');
        }
        if (count($matches) !== 1) {
            throw new CloudflareDnsException('zone_ambiguous');
        }

        return $matches[0];
    }

    /** Reject a malformed or out-of-zone record returned for an approved zone. */
    private function assertRecordsBelongToZone(string $zoneName, string $zoneId, array $records): void
    {
        foreach ($records as $record) {
            try {
                $name = DnsNames::normalizeRecordName((string) $record['name'], $zoneName);
            } catch (CloudflareDnsException) {
                throw new CloudflareDnsException('invalid_response');
            }
            if ($name !== strtolower(rtrim((string) $record['name'], '.'))
                || (isset($record['zone_id']) && $record['zone_id'] !== $zoneId)) {
                throw new CloudflareDnsException('invalid_response');
            }
        }
    }

    private function sameConfiguration(array $current, array $desired): bool
    {
        foreach (['type', 'name', 'content', 'ttl', 'proxied', 'priority', 'comment'] as $key) {
            $actual = $current[$key] ?? null;
            $wanted = $desired[$key] ?? null;
            if ($key === 'type') {
                $actual = strtoupper((string) $actual);
            }
            if ($key === 'name') {
                $actual = strtolower(rtrim((string) $actual, '.'));
            }
            if ($key === 'content' && in_array($desired['type'], ['CNAME', 'MX'], true)) {
                $actual = strtolower(rtrim((string) $actual, '.'));
            }
            if ($key === 'ttl') {
                $actual = (int) $actual;
            }
            if ($key === 'proxied') {
                $actual = (bool) $actual;
            }
            if ($actual !== $wanted) {
                return false;
            }
        }

        return true;
    }

    private function isProtectedName(string $name): bool
    {
        $protected = array_values(array_unique(array_merge(self::ALWAYS_PROTECTED_NAMES, array_map(
            static fn ($protectedName): string => strtolower(rtrim(trim((string) $protectedName), '.')),
            (array) config('cloudflare.protected_record_names', []),
        ))));

        return in_array(strtolower(rtrim($name, '.')), $protected, true);
    }

    private function publicRecord(array $record): array
    {
        return Arr::only($record, ['id', 'type', 'name', 'content', 'ttl', 'proxied', 'priority', 'comment']);
    }
}
