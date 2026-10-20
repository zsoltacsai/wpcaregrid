<?php

namespace App\Security\Vulnerabilities;

use App\Security\PlainText;
use App\Security\Severity;
use Illuminate\Support\Facades\File;
use Throwable;

/** Streaming Wordfence Intelligence v3 Scanner Feed importer and index reader. */
final class WordfenceFeed implements VulnerabilitySource
{
    private ?array $manifest = null;

    private ?array $catalog = null;

    private ?string $validatedGeneration = null;

    private bool $invalidIndex = false;

    /** @var array<string, list<array<string, mixed>>> */
    private array $loadedGroups = [];

    public function __construct(private readonly string $indexPath) {}

    public static function fromConfig(): self
    {
        return new self(self::indexPathFor((string) config('security.vulnerabilities.feed_path')));
    }

    public static function indexPathFor(string $feedPath): string
    {
        return preg_replace('/\.json$/', '', $feedPath).'.index.json';
    }

    public static function dataRootFor(string $indexPath): string
    {
        return $indexPath.'.data';
    }

    public function name(): string
    {
        return 'wordfence';
    }

    public function unavailableReason(): string
    {
        $manifest = $this->readManifest();
        if ($manifest === null) {
            return 'VULNERABILITY_FEED_UNAVAILABLE';
        }
        if ((int) config('security.vulnerabilities.max_age_hours') > 0
            && $manifest['created_at'] < now()->subHours((int) config('security.vulnerabilities.max_age_hours'))->getTimestamp()) {
            return 'VULNERABILITY_FEED_STALE';
        }

        return 'VULNERABILITY_FEED_INVALID';
    }

    public function available(): bool
    {
        $manifest = $this->readManifest();
        if ($this->invalidIndex || $manifest === null || $manifest['entries'] < 1) {
            return false;
        }
        $maxAge = (int) config('security.vulnerabilities.max_age_hours');
        if ($maxAge > 0 && $manifest['created_at'] < now()->subHours($maxAge)->getTimestamp()) {
            return false;
        }
        if ($this->validatedGeneration === $manifest['generation']) {
            return true;
        }

        $generationPath = self::dataRootFor($this->indexPath).DIRECTORY_SEPARATOR.$manifest['generation'];
        if (is_link($generationPath) || ! is_dir($generationPath)) {
            return false;
        }
        $catalog = $this->readCatalog($generationPath, $manifest['groups']);
        if ($catalog === null) {
            return false;
        }

        $expected = ['core' => 0, 'plugin' => 0, 'theme' => 0];
        foreach ($catalog as $group) {
            [$type, $slug] = explode('/', $group, 2);
            $expected[$type]++;
            $groupFile = $generationPath.DIRECTORY_SEPARATOR.$type.DIRECTORY_SEPARATOR.$slug.'.jsonl';
            if (is_link($groupFile) || ! is_file($groupFile)) {
                return false;
            }
        }
        foreach ($expected as $type => $number) {
            $directory = $generationPath.DIRECTORY_SEPARATOR.$type;
            if (! is_dir($directory)) {
                if ($number > 0) {
                    return false;
                }

                continue;
            }
            if (is_link($directory)) {
                return false;
            }
            $actual = 0;
            foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $item) {
                if ($item->isLink() || ! $item->isFile() || ! preg_match('/^[a-z0-9][a-z0-9_-]{0,99}\.jsonl$/', $item->getFilename())) {
                    return false;
                }
                $actual++;
            }
            if ($actual !== $number) {
                return false;
            }
        }

        $this->validatedGeneration = $manifest['generation'];

        return true;
    }

    public function affecting(string $type, string $slug, string $version): array
    {
        if (! $this->available() || $version === '' || ! in_array($type, ['core', 'plugin', 'theme'], true)) {
            return [];
        }
        $slug = $type === 'core' ? 'wordpress' : strtolower($slug);
        if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $slug)) {
            return [];
        }

        $groupKey = $type.'/'.$slug;
        if (! array_key_exists($groupKey, $this->loadedGroups)) {
            $manifest = $this->readManifest();
            $generationPath = self::dataRootFor($this->indexPath).DIRECTORY_SEPARATOR.$manifest['generation'];
            $catalog = $this->readCatalog($generationPath, $manifest['groups']);
            if ($catalog === null) {
                $this->invalidIndex = true;

                return [];
            }
            if (! in_array($groupKey, $catalog, true)) {
                $this->loadedGroups[$groupKey] = [];

                return [];
            }
            $path = $generationPath.DIRECTORY_SEPARATOR.$type.DIRECTORY_SEPARATOR.$slug.'.jsonl';
            $size = @filesize($path);
            if (is_link($path) || ! is_file($path) || $size === false || $size > 8 * 1024 * 1024) {
                $this->invalidIndex = true;

                return [];
            }

            $entries = [];
            if (is_file($path) && ($handle = @fopen($path, 'rb')) !== false) {
                while (($line = fgets($handle, 65537)) !== false) {
                    if (strlen($line) > 65536) {
                        $this->invalidIndex = true;
                        $entries = [];
                        break;
                    }
                    try {
                        $entry = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        $this->invalidIndex = true;
                        $entries = [];
                        break;
                    }
                    if (! is_array($entry) || ! isset($entry['id'], $entry['title'], $entry['ranges'], $entry['patched'])
                        || ! array_key_exists('cve', $entry) || ! array_key_exists('cvss', $entry)
                        || ! array_key_exists('rating', $entry) || ! array_key_exists('reference', $entry)
                        || ! is_array($entry['ranges']) || $entry['ranges'] === []) {
                        $this->invalidIndex = true;
                        $entries = [];
                        break;
                    }
                    $entries[] = $entry;
                }
                fclose($handle);
            }
            $this->loadedGroups[$groupKey] = $entries;
        }

        $matches = [];
        foreach ($this->loadedGroups[$groupKey] as $entry) {
            foreach ($entry['ranges'] as $range) {
                if (self::inRange($version, $range)) {
                    $matches[] = new Vulnerability(
                        id: $entry['id'],
                        title: $entry['title'],
                        cve: null,
                        cvssScore: null,
                        severity: $entry['rating'] ? Severity::tryFrom(strtolower($entry['rating'])) : null,
                        patchedVersions: $entry['patched'],
                        affected: $range['label'],
                        reference: $entry['reference'],
                        copyrightNotice: $entry['copyright_notice'],
                        copyrightLicense: $entry['copyright_license'],
                        copyrightLicenseUrl: $entry['copyright_license_url'],
                        mitreNotice: $entry['mitre_notice'],
                        mitreLicense: $entry['mitre_license'],
                        mitreLicenseUrl: $entry['mitre_license_url'],
                    );

                    continue 2;
                }
            }
        }

        return $matches;
    }

    /** Stream a complete JSON feed into a private generation; publish only its manifest atomically. */
    public static function import(string $feedFile, string $indexPath, ?AtomicIndexPublisher $publisher = null, ?string $legacyFeedPath = null, ?int $startedAt = null): int
    {
        $maxBytes = min(160 * 1024 * 1024, max(1, (int) config('security.vulnerabilities.max_bytes', 160 * 1024 * 1024)));
        $size = @filesize($feedFile);
        if (! is_file($feedFile) || $size === false || $size < 2 || $size > $maxBytes) {
            throw new WordfenceSyncFailure('feed_size_limit', 'The vulnerability feed is empty, unavailable, or exceeds its size limit.');
        }

        $dataRoot = self::dataRootFor($indexPath);
        File::ensureDirectoryExists(dirname($indexPath));
        File::ensureDirectoryExists($dataRoot);
        $generation = bin2hex(random_bytes(16));
        $staging = $dataRoot.DIRECTORY_SEPARATOR.'.stage-'.$generation;
        if (! @mkdir($staging, 0700) || is_link($staging)) {
            throw new WordfenceSyncFailure('staging_filesystem_failure', 'The vulnerability index generation could not be prepared safely.');
        }

        $groups = [];
        $groupBytes = [];
        $records = 0;
        $entries = 0;
        $indexBytes = 0;
        $maxIndexBytes = (int) config('security.vulnerabilities.max_index_bytes', 128 * 1024 * 1024);
        $startedAt ??= (int) floor(microtime(true) * 1000);

        try {
            foreach (WordfenceJsonRecords::fromFile($feedFile) as $key => $record) {
                if (++$records > 100_000) {
                    throw new WordfenceSyncFailure('feed_record_limit', 'The vulnerability feed exceeds the supported record count.');
                }
                self::validateRecord($key, $record);
                if ($record['informational']) {
                    continue;
                }
                $cvss = [];
                foreach ($record['software'] as $software) {
                    $entry = self::normalizeSoftware($software, $record, $key, $cvss);
                    $type = $entry['type'];
                    $slug = $entry['slug'];
                    unset($entry['type'], $entry['slug']);
                    $groupKey = $type.'/'.$slug;
                    if (! isset($groups[$groupKey])) {
                        if (count($groups) >= 50_000) {
                            throw new WordfenceSyncFailure('feed_group_limit', 'The vulnerability feed exceeds the supported software-group count.');
                        }
                        $path = $staging.DIRECTORY_SEPARATOR.$type.DIRECTORY_SEPARATOR.$slug.'.jsonl';
                        File::ensureDirectoryExists(dirname($path));
                        $groups[$groupKey] = $path;
                    }
                    $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
                    $indexBytes += strlen($line);
                    $groupBytes[$groupKey] = ($groupBytes[$groupKey] ?? 0) + strlen($line);
                    if ($indexBytes > $maxIndexBytes || $groupBytes[$groupKey] > 8 * 1024 * 1024
                        || file_put_contents($groups[$groupKey], $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
                        throw new WordfenceSyncFailure('index_storage_failure', 'The vulnerability index exceeds its storage limit or could not be written.');
                    }
                    $entries++;
                }
            }
            if ($entries === 0) {
                throw new WordfenceSyncFailure('feed_empty_or_unsupported', 'The vulnerability feed contains no supported vulnerability records.');
            }

            $current = self::readManifestFile($indexPath);
            $currentStartedAt = $current['started_at'] ?? (isset($current['created_at']) ? $current['created_at'] * 1000 : null);
            if (is_int($currentStartedAt) && $currentStartedAt >= $startedAt) {
                throw new WordfenceSyncFailure('stale_generation', 'A newer synchronization generation is already active.');
            }
            if ($legacyFeedPath !== null && (file_exists($legacyFeedPath) || is_link($legacyFeedPath))) {
                if (is_link($legacyFeedPath) || ! is_file($legacyFeedPath) || ! @unlink($legacyFeedPath)) {
                    throw new WordfenceSyncFailure('index_publication_failure', 'The legacy raw feed cache could not be removed safely.');
                }
            }

            $catalog = json_encode(array_keys($groups), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($catalog) > 8 * 1024 * 1024 || file_put_contents($staging.DIRECTORY_SEPARATOR.'catalog.json', $catalog, LOCK_EX) !== strlen($catalog)) {
                throw new WordfenceSyncFailure('index_publication_failure', 'The vulnerability index catalog could not be written safely.');
            }
            $publishedGeneration = $dataRoot.DIRECTORY_SEPARATOR.$generation;
            if (! @rename($staging, $publishedGeneration)) {
                throw new WordfenceSyncFailure('index_publication_failure', 'The vulnerability index generation could not be installed.');
            }

            $manifest = [
                'format' => 'wordfence-intelligence-v3-scanner',
                'source' => 'wordfence',
                'endpoint' => 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/scanner',
                'generation' => $generation,
                'started_at' => $startedAt,
                'created_at' => now()->getTimestamp(),
                'records' => $records,
                'entries' => $entries,
                'groups' => count($groups),
                'feed_bytes' => $size,
            ];
            $temporary = tempnam(dirname($indexPath), 'wpcg-index-');
            if ($temporary === false || realpath(dirname($temporary)) !== realpath(dirname($indexPath))) {
                throw new WordfenceSyncFailure('index_publication_failure', 'The vulnerability index manifest could not be prepared safely.');
            }
            try {
                $json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
                    throw new WordfenceSyncFailure('index_publication_failure', 'The vulnerability index manifest could not be written.');
                }
                try {
                    ($publisher ?? new AtomicIndexPublisher)->publish($temporary, $indexPath);
                } catch (Throwable $exception) {
                    throw new WordfenceSyncFailure('index_publication_failure', 'The validated vulnerability index could not be published.', $exception);
                }
            } finally {
                File::delete($temporary);
            }
            self::pruneOldGenerations($dataRoot, $generation);

            return $entries;
        } catch (\JsonException) {
            throw new WordfenceSyncFailure('feed_json_invalid', 'The vulnerability feed contains data that cannot be indexed safely.');
        } finally {
            if (is_dir($staging) && ! is_link($staging)) {
                File::deleteDirectory($staging);
            }
        }
    }

    private static function validateRecord(string $key, array $record): void
    {
        if (! preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $key)
            || ! is_string($record['id'] ?? null) || strcasecmp($record['id'], $key) !== 0
            || ! is_string($record['title'] ?? null) || trim($record['title']) === ''
            || ! is_array($record['software'] ?? null) || ! array_is_list($record['software']) || $record['software'] === []
            || ! is_bool($record['informational'] ?? null)
            || ! is_array($record['references'] ?? null) || ! array_is_list($record['references'])
            || array_filter($record['references'], fn ($reference) => ! is_string($reference))
            || ! (is_null($record['published'] ?? null) || is_string($record['published']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $record['published']))) {
            throw new WordfenceSyncFailure('feed_schema_invalid', 'The vulnerability feed record does not match the v3 Scanner Feed schema.');
        }
        if (isset($record['copyrights']) && ! is_null($record['copyrights']) && ! is_array($record['copyrights'])) {
            throw new WordfenceSyncFailure('feed_schema_invalid', 'The vulnerability feed has invalid copyright metadata.');
        }
    }

    private static function normalizeSoftware(mixed $software, array $record, string $id, array $cvss): array
    {
        if (! is_array($software)) {
            throw new WordfenceSyncFailure('feed_schema_invalid', 'The vulnerability feed contains invalid software metadata.');
        }
        $type = $software['type'] ?? null;
        $slug = $software['slug'] ?? null;
        if (! is_string($type) || ! in_array($type, ['core', 'plugin', 'theme'], true) || ! is_string($slug)) {
            throw new WordfenceSyncFailure('feed_schema_invalid', 'The vulnerability feed contains unsupported software metadata.');
        }
        $slug = strtolower($slug);
        if ($type === 'core') {
            if (! in_array($slug, ['', 'wordpress'], true)) {
                throw new WordfenceSyncFailure('feed_schema_invalid', 'The vulnerability feed contains unsupported core software metadata.');
            }
            $slug = 'wordpress';
        } elseif (! preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $slug)) {
            throw new WordfenceSyncFailure('feed_schema_invalid', 'The vulnerability feed contains an invalid software slug.');
        }
        $versions = $software['affected_versions'] ?? null;
        if (! is_array($versions) || $versions === [] || array_is_list($versions)
            || ! is_bool($software['patched'] ?? null)
            || ! is_array($software['patched_versions'] ?? null) || ! array_is_list($software['patched_versions'])) {
            throw new WordfenceSyncFailure('feed_schema_invalid', 'The vulnerability feed contains incomplete software metadata.');
        }

        $ranges = [];
        foreach ($versions as $label => $range) {
            if (! is_array($range) || ! is_string($range['from_version'] ?? null) || ! is_string($range['to_version'] ?? null)
                || ! is_bool($range['from_inclusive'] ?? null) || ! is_bool($range['to_inclusive'] ?? null)) {
                throw new WordfenceSyncFailure('feed_schema_invalid', 'The vulnerability feed contains an invalid affected-version range.');
            }
            foreach (['from_version', 'to_version'] as $key) {
                if ($range[$key] !== '*' && ! preg_match('/^[0-9][0-9A-Za-z.+_-]{0,63}$/', $range[$key])) {
                    throw new WordfenceSyncFailure('feed_schema_invalid', 'The vulnerability feed contains an unsupported software version.');
                }
            }
            $ranges[] = [
                'label' => substr((string) $label, 0, 128),
                'from' => $range['from_version'],
                'from_inclusive' => $range['from_inclusive'],
                'to' => $range['to_version'],
                'to_inclusive' => $range['to_inclusive'],
            ];
        }
        foreach ($software['patched_versions'] as $patched) {
            if (! is_string($patched) || ! preg_match('/^[0-9][0-9A-Za-z.+_-]{0,63}$/', $patched)) {
                throw new WordfenceSyncFailure('feed_schema_invalid', 'The vulnerability feed contains invalid patched-version metadata.');
            }
        }

        $reference = null;
        foreach ($record['references'] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            $candidate = preg_replace('#^http://www\.wordfence\.com/#i', 'https://www.wordfence.com/', $candidate);
            $parts = parse_url($candidate);
            if (($parts['scheme'] ?? null) === 'https' && ($parts['host'] ?? null) === 'www.wordfence.com'
                && str_starts_with($parts['path'] ?? '', '/threat-intel/vulnerabilities/')) {
                $reference = $candidate;
                break;
            }
        }
        $copyrights = is_array($record['copyrights'] ?? null) ? $record['copyrights'] : [];
        $defiant = is_array($copyrights['defiant'] ?? null) ? $copyrights['defiant'] : [];
        $mitre = is_array($copyrights['mitre'] ?? null) ? $copyrights['mitre'] : [];
        if ($defiant !== [] && (! is_string($defiant['notice'] ?? null) || ! is_string($defiant['license'] ?? null)
            || ! is_string($defiant['license_url'] ?? null) || $reference === null)) {
            throw new WordfenceSyncFailure('feed_schema_invalid', 'Wordfence copyright attribution is incomplete.');
        }
        if ($mitre !== [] && (! is_string($mitre['notice'] ?? null) || ! is_string($mitre['license'] ?? null)
            || ! is_string($mitre['license_url'] ?? null))) {
            throw new WordfenceSyncFailure('feed_schema_invalid', 'MITRE copyright attribution is incomplete.');
        }

        return [
            'type' => $type,
            'slug' => $slug,
            'id' => substr($id, 0, 48),
            'title' => PlainText::clean($record['title'], 191),
            'cve' => null,
            'cvss' => null,
            'rating' => null,
            'patched' => $software['patched_versions'],
            'ranges' => $ranges,
            'reference' => $reference,
            'copyright_notice' => $defiant['notice'] ?? null,
            'copyright_license' => $defiant['license'] ?? null,
            'copyright_license_url' => $defiant['license_url'] ?? null,
            'mitre_notice' => $mitre['notice'] ?? null,
            'mitre_license' => $mitre['license'] ?? null,
            'mitre_license_url' => $mitre['license_url'] ?? null,
        ];
    }

    public static function inRange(string $version, array $range): bool
    {
        $from = $range['from'] === '*' ? null : $range['from'];
        $to = $range['to'] === '*' ? null : $range['to'];

        return ($from === null || version_compare($version, $from, $range['from_inclusive'] ? '>=' : '>'))
            && ($to === null || version_compare($version, $to, $range['to_inclusive'] ? '<=' : '<'));
    }

    private function readManifest(): ?array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        return $this->manifest = self::readManifestFile($this->indexPath);
    }

    private static function readManifestFile(string $path): ?array
    {
        $size = @filesize($path);
        if (is_link($path) || ! is_file($path) || $size === false || $size > 4096) {
            return null;
        }
        try {
            $manifest = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($manifest) || ($manifest['format'] ?? null) !== 'wordfence-intelligence-v3-scanner'
            || ($manifest['source'] ?? null) !== 'wordfence'
            || ($manifest['endpoint'] ?? null) !== 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/scanner'
            || ! preg_match('/^[a-f0-9]{32}$/', $manifest['generation'] ?? '')
            || ! is_int($manifest['created_at'] ?? null) || ! is_int($manifest['started_at'] ?? null)
            || ! is_int($manifest['entries'] ?? null) || ! is_int($manifest['records'] ?? null) || ! is_int($manifest['groups'] ?? null)) {
            return null;
        }

        return $manifest;
    }

    private function readCatalog(string $generationPath, int $expectedGroups): ?array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }
        $path = $generationPath.DIRECTORY_SEPARATOR.'catalog.json';
        $size = @filesize($path);
        if (is_link($path) || ! is_file($path) || $size === false || $size < 2 || $size > 8 * 1024 * 1024) {
            return null;
        }
        try {
            $catalog = json_decode((string) file_get_contents($path), true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($catalog) || ! array_is_list($catalog) || count($catalog) !== $expectedGroups) {
            return null;
        }
        $seen = [];
        foreach ($catalog as $group) {
            if (! is_string($group) || ! preg_match('#^(core|plugin|theme)/[a-z0-9][a-z0-9_-]{0,99}$#', $group) || isset($seen[$group])) {
                return null;
            }
            $seen[$group] = true;
        }

        return $this->catalog = $catalog;
    }

    private static function pruneOldGenerations(string $dataRoot, string $active): void
    {
        try {
            $root = realpath($dataRoot);
            if ($root === false || is_link($dataRoot)) {
                return;
            }
            foreach (new \DirectoryIterator($root) as $item) {
                $name = $item->getFilename();
                if ($item->isDot() || $item->isLink() || ! $item->isDir() || $name === $active
                    || ! preg_match('/^[a-f0-9]{32}$/', $name) || $item->getMTime() >= time() - 96 * 3600) {
                    continue;
                }
                $target = realpath($item->getPathname());
                if ($target !== false && dirname($target) === $root) {
                    $links = false;
                    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $child) {
                        if ($child->isLink()) {
                            $links = true;
                            break;
                        }
                    }
                    if (! $links) {
                        File::deleteDirectory($target);
                    }
                }
            }
        } catch (Throwable) {
            // Cleanup is best-effort and never changes the outcome of a published refresh.
        }
    }
}
