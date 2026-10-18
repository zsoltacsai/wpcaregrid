<?php

namespace App\Security\Vulnerabilities;

use App\Security\PlainText;
use App\Security\Severity;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Wordfence Intelligence vulnerability data (v2 feed format), turned into a
 * compact index grouped by software type and slug. The feed is downloaded
 * by `security:vulnerabilities:sync` (only when a feed URL is configured) or
 * imported from a file; scans read only the index.
 *
 * Only fields the feed provides are kept: affected version ranges, patched
 * versions, CVE, CVSS score and rating, title. Nothing is filled in.
 */
final class WordfenceFeed implements VulnerabilitySource
{
    /** @var array<string, array<string, list<array<string, mixed>>>>|null */
    private ?array $index = null;

    public function __construct(private readonly string $indexPath) {}

    public static function fromConfig(): self
    {
        return new self(self::indexPathFor((string) config('security.vulnerabilities.feed_path')));
    }

    public static function indexPathFor(string $feedPath): string
    {
        return preg_replace('/\.json$/', '', $feedPath).'.index.json';
    }

    public function name(): string
    {
        return 'wordfence';
    }

    public function available(): bool
    {
        if (! is_file($this->indexPath)) {
            return false;
        }

        $maxAge = (int) config('security.vulnerabilities.max_age_hours');
        $maxBytes = min(64 * 1024 * 1024, max(1, (int) config('security.vulnerabilities.max_bytes', 64 * 1024 * 1024)));
        $size = @filesize($this->indexPath);

        return $size !== false && $size >= 2 && $size <= $maxBytes
            && ($maxAge <= 0 || filemtime($this->indexPath) >= now()->subHours($maxAge)->getTimestamp())
            && $this->load() !== [];
    }

    public function affecting(string $type, string $slug, string $version): array
    {
        if (! $this->available() || $version === '') {
            return [];
        }

        $matches = [];
        foreach ($this->load()[$type][strtolower($slug)] ?? [] as $entry) {
            foreach ($entry['ranges'] as $range) {
                if (self::inRange($version, $range)) {
                    $matches[] = new Vulnerability(
                        id: $entry['id'],
                        title: $entry['title'],
                        cve: $entry['cve'],
                        cvssScore: $entry['cvss'],
                        severity: $entry['rating'] ? Severity::tryFrom(strtolower($entry['rating'])) : Severity::fromCvss($entry['cvss']),
                        patchedVersions: $entry['patched'],
                        affected: $range['label'],
                    );

                    continue 2;
                }
            }
        }

        return $matches;
    }

    /**
     * Build the index from a downloaded feed file. Returns the number of entries kept.
     *
     * @throws RuntimeException when the file is not a Wordfence v2 feed
     */
    public static function import(string $feedFile, string $indexPath): int
    {
        $maxBytes = min(64 * 1024 * 1024, max(1, (int) config('security.vulnerabilities.max_bytes', 64 * 1024 * 1024)));
        $size = @filesize($feedFile);
        if (! is_file($feedFile) || $size === false || $size < 2 || $size > $maxBytes) {
            throw new RuntimeException('The vulnerability feed is empty, unavailable, or exceeds its size limit.');
        }

        try {
            $feed = json_decode((string) file_get_contents($feedFile), true, 64, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('The vulnerability feed is not valid JSON.');
        }
        if (! is_array($feed) || $feed === [] || array_is_list($feed)) {
            throw new RuntimeException('The vulnerability feed has an unexpected structure.');
        }

        $index = [];
        $count = 0;
        foreach ($feed as $id => $vulnerability) {
            if (! is_array($vulnerability)) {
                throw new RuntimeException('The vulnerability feed contains an invalid record.');
            }
            if (($vulnerability['informational'] ?? false) === true) {
                continue;
            }
            if (! is_array($vulnerability['software'] ?? null) || $vulnerability['software'] === []) {
                throw new RuntimeException('The vulnerability feed contains an incomplete record.');
            }
            $recordId = $vulnerability['id'] ?? $id;
            if (! is_string($recordId) || ! preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $recordId)
                || ! is_string($vulnerability['title'] ?? null) || trim($vulnerability['title']) === '') {
                throw new RuntimeException('The vulnerability feed contains an invalid identifier or title.');
            }
            $cvss = is_array($vulnerability['cvss'] ?? null) ? $vulnerability['cvss'] : [];

            foreach ($vulnerability['software'] as $software) {
                if (! is_array($software)) {
                    throw new RuntimeException('The vulnerability feed contains invalid software metadata.');
                }
                $type = $software['type'] ?? null;
                $slug = $software['slug'] ?? null;
                if (! is_string($type) || ! in_array($type, ['core', 'plugin', 'theme'], true)
                    || ! is_string($slug)) {
                    throw new RuntimeException('The vulnerability feed contains unsupported software metadata.');
                }
                $slug = strtolower($slug);
                if (($type === 'core' && $slug !== 'wordpress') || ($type !== 'core' && ! preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $slug))
                    || ! is_array($software['affected_versions'] ?? null) || $software['affected_versions'] === []) {
                    throw new RuntimeException('The vulnerability feed contains incomplete software metadata.');
                }

                $ranges = [];
                foreach ($software['affected_versions'] as $label => $range) {
                    if (! is_array($range) || ! is_string($range['from_version'] ?? null) || ! is_string($range['to_version'] ?? null)
                        || $range['from_version'] === '' || $range['to_version'] === '') {
                        throw new RuntimeException('The vulnerability feed contains an invalid affected-version range.');
                    }
                    $ranges[] = [
                        'label' => substr((string) $label, 0, 64),
                        'from' => $range['from_version'],
                        'from_inclusive' => (bool) ($range['from_inclusive'] ?? true),
                        'to' => $range['to_version'],
                        'to_inclusive' => (bool) ($range['to_inclusive'] ?? true),
                    ];
                }
                if ($ranges === []) {
                    continue;
                }

                $index[$type][$type === 'core' ? 'wordpress' : $slug][] = [
                    'id' => substr($recordId, 0, 48),
                    'title' => PlainText::clean($vulnerability['title'], 191),
                    'cve' => is_string($vulnerability['cve'] ?? null) && preg_match('/^CVE-\d{4}-\d{4,}$/', $vulnerability['cve']) ? $vulnerability['cve'] : null,
                    'cvss' => is_numeric($cvss['score'] ?? null) ? (float) $cvss['score'] : null,
                    'rating' => is_string($cvss['rating'] ?? null) && in_array(strtolower($cvss['rating']), ['low', 'medium', 'high', 'critical'], true) ? strtolower($cvss['rating']) : null,
                    'patched' => array_values(array_filter(array_map(fn ($v) => is_string($v) ? $v : '', (array) ($software['patched_versions'] ?? [])), fn ($v) => preg_match('/^[0-9][0-9A-Za-z.\-]*$/', $v))),
                    'ranges' => $ranges,
                ];
                $count++;
            }
        }

        if ($count === 0) {
            throw new RuntimeException('The vulnerability feed contains no supported vulnerability records.');
        }

        File::ensureDirectoryExists(dirname($indexPath));
        $temporary = tempnam(dirname($indexPath), 'wpcg-index-');
        if ($temporary === false || realpath(dirname($temporary)) !== realpath(dirname($indexPath))) {
            if (is_string($temporary)) {
                File::delete($temporary);
            }
            throw new RuntimeException('The vulnerability index could not be prepared safely.');
        }

        try {
            $encoded = json_encode($index, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded) || ! rename($temporary, $indexPath)) {
                throw new RuntimeException('The vulnerability index could not be replaced atomically.');
            }
        } catch (\JsonException) {
            throw new RuntimeException('The vulnerability index could not be encoded.');
        } finally {
            File::delete($temporary);
        }

        return $count;
    }

    /** "*" means unbounded. Versions compare as WordPress does (version_compare). */
    public static function inRange(string $version, array $range): bool
    {
        $from = $range['from'] === '*' ? null : $range['from'];
        $to = $range['to'] === '*' ? null : $range['to'];

        $aboveFrom = $from === null || version_compare($version, $from, $range['from_inclusive'] ? '>=' : '>');
        $belowTo = $to === null || version_compare($version, $to, $range['to_inclusive'] ? '<=' : '<');

        return $aboveFrom && $belowTo;
    }

    private function load(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        try {
            $decoded = json_decode((string) file_get_contents($this->indexPath), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->index = [];
        }

        if (! is_array($decoded) || $decoded === []) {
            return $this->index = [];
        }

        foreach (['core', 'plugin', 'theme'] as $type) {
            $group = $decoded[$type] ?? [];
            if (! is_array($group)) {
                continue;
            }
            foreach ($group as $entries) {
                if (! is_array($entries) || $entries === []) {
                    continue;
                }

                foreach ($entries as $entry) {
                    if (is_array($entry) && isset($entry['id'], $entry['title'], $entry['patched'], $entry['ranges'])
                        && is_array($entry['ranges']) && $entry['ranges'] !== []) {
                        return $this->index = $decoded;
                    }
                }
            }
        }

        return $this->index = [];
    }
}
