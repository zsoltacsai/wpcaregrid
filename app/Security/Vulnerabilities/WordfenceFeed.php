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

        return $maxAge <= 0 || filemtime($this->indexPath) >= now()->subHours($maxAge)->getTimestamp();
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
        $feed = json_decode((string) file_get_contents($feedFile), true, 64, JSON_BIGINT_AS_STRING);
        if (! is_array($feed)) {
            throw new RuntimeException('The vulnerability feed is not valid JSON.');
        }

        $index = [];
        $count = 0;
        foreach ($feed as $id => $vulnerability) {
            if (! is_array($vulnerability) || ! is_array($vulnerability['software'] ?? null) || ($vulnerability['informational'] ?? false) === true) {
                continue;
            }

            foreach ($vulnerability['software'] as $software) {
                $type = $software['type'] ?? null;
                $slug = strtolower((string) ($software['slug'] ?? ''));
                if (! in_array($type, ['core', 'plugin', 'theme'], true) || $slug === '' || ! is_array($software['affected_versions'] ?? null)) {
                    continue;
                }

                $ranges = [];
                foreach ($software['affected_versions'] as $label => $range) {
                    if (is_array($range) && isset($range['from_version'], $range['to_version'])) {
                        $ranges[] = [
                            'label' => substr((string) $label, 0, 64),
                            'from' => (string) $range['from_version'],
                            'from_inclusive' => (bool) ($range['from_inclusive'] ?? true),
                            'to' => (string) $range['to_version'],
                            'to_inclusive' => (bool) ($range['to_inclusive'] ?? true),
                        ];
                    }
                }
                if ($ranges === []) {
                    continue;
                }

                $index[$type][$type === 'core' ? 'wordpress' : $slug][] = [
                    'id' => substr((string) ($vulnerability['id'] ?? $id), 0, 48),
                    'title' => PlainText::clean((string) ($vulnerability['title'] ?? ''), 191),
                    'cve' => is_string($vulnerability['cve'] ?? null) && preg_match('/^CVE-\d{4}-\d{4,}$/', $vulnerability['cve']) ? $vulnerability['cve'] : null,
                    'cvss' => is_numeric($vulnerability['cvss']['score'] ?? null) ? (float) $vulnerability['cvss']['score'] : null,
                    'rating' => in_array(strtolower((string) ($vulnerability['cvss']['rating'] ?? '')), ['low', 'medium', 'high', 'critical'], true) ? strtolower($vulnerability['cvss']['rating']) : null,
                    'patched' => array_values(array_filter(array_map('strval', (array) ($software['patched_versions'] ?? [])), fn ($v) => preg_match('/^[0-9][0-9A-Za-z.\-]*$/', $v))),
                    'ranges' => $ranges,
                ];
                $count++;
            }
        }

        File::ensureDirectoryExists(dirname($indexPath));
        $temporary = $indexPath.'.tmp';
        file_put_contents($temporary, json_encode($index, JSON_UNESCAPED_SLASHES));
        rename($temporary, $indexPath);

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
        return $this->index ??= (json_decode((string) file_get_contents($this->indexPath), true) ?: []);
    }
}
