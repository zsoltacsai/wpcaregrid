<?php

namespace WPCareGrid\Connector\Security\Scan;

use WPCareGrid\Connector\Backups\TempDir;

/**
 * Where a scan is between its parts: finished phases, cursors, counters and
 * the integrity results the malware phase uses. Kept as a private JSON file
 * in the connector's temporary directory (0600), not in the options table,
 * and removed when the scan finishes.
 */
final class ScanState
{
    /** @var list<string> */
    public array $done = [];

    /** @var array<string, int> */
    public array $cursor = [];

    /** @var array<string, array<string, int>> */
    public array $counts = [];

    /** @var array<string, string> */
    public array $integrity = [];

    /** @var array<string, true> Paths matching their official checksum. */
    public array $verified = [];

    /** @var array<string, string> Paths that are modified or extra. */
    public array $flagged = [];

    /** @var array<string, string> Phase => completed | unavailable | failed */
    public array $phases = [];

    public int $parts = 0;

    private function __construct(public readonly string $uuid) {}

    public static function start(string $uuid): self
    {
        self::forget($uuid);

        return new self($uuid);
    }

    public static function load(string $uuid): ?self
    {
        $file = self::file($uuid);
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($data)) {
            return null;
        }

        $state = new self($uuid);
        foreach (['done', 'cursor', 'counts', 'integrity', 'verified', 'flagged', 'phases'] as $key) {
            $state->{$key} = is_array($data[$key] ?? null) ? $data[$key] : [];
        }
        $state->parts = (int) ($data['parts'] ?? 0);

        return $state;
    }

    public function save(): void
    {
        $file = self::file($this->uuid);
        TempDir::create($file);
        file_put_contents($file, wp_json_encode(get_object_vars($this)));
    }

    public static function forget(string $uuid): void
    {
        $file = self::file($uuid);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function count(string $group, string $key, int $by = 1): void
    {
        $this->counts[$group][$key] = ($this->counts[$group][$key] ?? 0) + $by;
    }

    private static function file(string $uuid): string
    {
        return TempDir::file($uuid, 'security-state.json');
    }
}
