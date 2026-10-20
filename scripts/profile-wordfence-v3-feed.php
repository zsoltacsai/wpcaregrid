<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/Security/Vulnerabilities/WordfenceSyncFailure.php';
require dirname(__DIR__).'/app/Security/Vulnerabilities/WordfenceJsonRecords.php';

use App\Security\Vulnerabilities\WordfenceJsonRecords;

const MAXIMUM_FEED_BYTES = 160 * 1024 * 1024;
const MAXIMUM_RECORD_BYTES = 2 * 1024 * 1024;
const PREVIOUS_VERSION_TOKEN_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9.+_-]{0,63}$/';

function jsonType(mixed $value): string
{
    return match (true) {
        $value instanceof stdClass => 'object',
        is_array($value) => 'array',
        is_string($value) => 'string',
        is_bool($value) => 'boolean',
        is_int($value) => 'integer',
        is_float($value) => 'number',
        is_null($value) => 'null',
        default => 'other',
    };
}

function shapeKey(mixed $value): string
{
    if (! $value instanceof stdClass) {
        return 'not-object:'.jsonType($value);
    }

    $keys = array_keys(get_object_vars($value));
    sort($keys, SORT_STRING);

    return 'object:'.substr(hash('sha256', implode("\0", $keys)), 0, 12);
}

function increment(array &$counts, string $key, int $amount = 1): void
{
    $counts[$key] = ($counts[$key] ?? 0) + $amount;
}

function profileVersion(mixed $value, array &$counts): void
{
    increment($counts['types'], jsonType($value));
    $counts['endpoints']++;

    if (! is_string($value)) {
        if ($value === null) {
            $counts['null']++;
        } else {
            $counts['unexpected_type']++;
        }

        return;
    }

    if ($value === '') {
        $counts['empty']++;
    }
    if ($value === '*') {
        $counts['standalone_wildcard']++;
    }
    if (trim($value) !== $value) {
        $counts['leading_or_trailing_whitespace']++;
    }
    if (strlen($value) > 64) {
        $counts['over_64_bytes']++;
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
        $counts['control_characters']++;
    }
    if (preg_match('/[^\x00-\x7F]/', $value)) {
        $counts['non_ascii']++;
    }
    if (preg_match('/^\d+(?:\.\d+)*$/D', $value)) {
        $counts['numeric_dotted']++;
    }
    if (preg_match('/[A-Za-z]/', $value) && preg_match('/\d/', $value)) {
        $counts['mixed_alphanumeric']++;
    }
    if (preg_match('/(?:^|[._+\-])(?:alpha|beta|rc|pre|preview|dev)(?:[._+\-]?\d*)?$/i', $value)) {
        $counts['prerelease_like_suffix']++;
    }
    if (preg_match('/[-+]/', $value)) {
        $counts['hyphen_or_plus_suffix_or_separator']++;
    }
    if ($value !== '*' && preg_match('/[^A-Za-z0-9.+_-]/', $value)) {
        $counts['outside_previous_token_alphabet']++;
    }
    if ($value !== '*' && preg_match(PREVIOUS_VERSION_TOKEN_PATTERN, $value) !== 1) {
        $counts['rejected_by_previous_version_token_regex']++;
    }
    if ($value !== '*' && preg_match('/[^0-9.]/', $value)) {
        $counts['version_compare_semantics_need_review']++;
    }
}

function printCounts(string $title, array $counts): void
{
    ksort($counts, SORT_STRING);
    echo "\n{$title}\n";
    foreach ($counts as $name => $count) {
        echo "- {$name}: {$count}\n";
    }
    if ($counts === []) {
        echo "- none: 0\n";
    }
}

function profileFeed(string $path, int $maximumBytes): array
{
    if (is_link($path) || ! is_file($path)) {
        throw new RuntimeException('Input must be a regular, non-symlink file.');
    }
    $size = @filesize($path);
    if ($size === false || $size < 2 || $size > $maximumBytes) {
        throw new RuntimeException('Input is empty, unreadable, or exceeds the configured size limit.');
    }

    $profile = [
        'input_bytes' => $size,
        'records' => 0,
        'software_entries' => 0,
        'ranges' => 0,
        'record_shapes' => [],
        'software_shapes' => [],
        'affected_versions_types' => [],
        'range_shapes' => [],
        'endpoint' => [
            'from_version' => [
                'types' => [],
                'endpoints' => 0,
                'null' => 0,
                'unexpected_type' => 0,
                'empty' => 0,
                'standalone_wildcard' => 0,
                'leading_or_trailing_whitespace' => 0,
                'over_64_bytes' => 0,
                'control_characters' => 0,
                'non_ascii' => 0,
                'numeric_dotted' => 0,
                'mixed_alphanumeric' => 0,
                'prerelease_like_suffix' => 0,
                'hyphen_or_plus_suffix_or_separator' => 0,
                'outside_previous_token_alphabet' => 0,
                'rejected_by_previous_version_token_regex' => 0,
                'version_compare_semantics_need_review' => 0,
            ],
            'to_version' => [
                'types' => [],
                'endpoints' => 0,
                'null' => 0,
                'unexpected_type' => 0,
                'empty' => 0,
                'standalone_wildcard' => 0,
                'leading_or_trailing_whitespace' => 0,
                'over_64_bytes' => 0,
                'control_characters' => 0,
                'non_ascii' => 0,
                'numeric_dotted' => 0,
                'mixed_alphanumeric' => 0,
                'prerelease_like_suffix' => 0,
                'hyphen_or_plus_suffix_or_separator' => 0,
                'outside_previous_token_alphabet' => 0,
                'rejected_by_previous_version_token_regex' => 0,
                'version_compare_semantics_need_review' => 0,
            ],
        ],
        'boundary' => [
            'from_inclusive' => [],
            'to_inclusive' => [],
        ],
        'missing_fields' => [],
    ];

    try {
        foreach (WordfenceJsonRecords::fromFile($path, MAXIMUM_RECORD_BYTES) as $record) {
            $profile['records']++;
            increment($profile['record_shapes'], shapeKey((object) $record));
            $softwareList = $record['software'] ?? null;
            if (! is_array($softwareList)) {
                increment($profile['missing_fields'], 'record.software_not_list');
                continue;
            }

            foreach ($softwareList as $software) {
                $profile['software_entries']++;
                increment($profile['software_shapes'], shapeKey($software));
                if (! $software instanceof stdClass) {
                    continue;
                }
                $software = get_object_vars($software);
                if (! array_key_exists('affected_versions', $software)) {
                    increment($profile['missing_fields'], 'software.affected_versions');
                    continue;
                }
                $affected = $software['affected_versions'];
                increment($profile['affected_versions_types'], jsonType($affected));
                if (! $affected instanceof stdClass) {
                    continue;
                }

                foreach (get_object_vars($affected) as $range) {
                    $profile['ranges']++;
                    increment($profile['range_shapes'], shapeKey($range));
                    if (! $range instanceof stdClass) {
                        continue;
                    }
                    $range = get_object_vars($range);
                    foreach (['from_version', 'to_version'] as $field) {
                        if (! array_key_exists($field, $range)) {
                            increment($profile['missing_fields'], "range.{$field}");
                            continue;
                        }
                        profileVersion($range[$field], $profile['endpoint'][$field]);
                    }
                    foreach (['from_inclusive', 'to_inclusive'] as $field) {
                        if (! array_key_exists($field, $range)) {
                            increment($profile['missing_fields'], "range.{$field}");
                        } else {
                            increment($profile['boundary'][$field], jsonType($range[$field]).':'.(is_bool($range[$field]) ? ($range[$field] ? 'true' : 'false') : 'value'));
                        }
                    }
                }
            }
        }
    } catch (Throwable) {
        // Parser errors can contain untrusted data. Keep diagnostics category-only.
        throw new RuntimeException('Input could not be profiled: invalid JSON, encoding, record shape, or record size limit.');
    }

    return $profile;
}

try {
    $arguments = array_slice($argv, 1);
    $maximumBytes = MAXIMUM_FEED_BYTES;
    $path = null;
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--max-bytes=')) {
            $requested = substr($argument, strlen('--max-bytes='));
            if (! ctype_digit($requested) || (int) $requested < 2 || (int) $requested > MAXIMUM_FEED_BYTES) {
                throw new RuntimeException('Maximum size must be between 2 bytes and 160 MiB.');
            }
            $maximumBytes = (int) $requested;
        } elseif ($path === null && ! str_starts_with($argument, '--')) {
            $path = $argument;
        } else {
            throw new RuntimeException('Usage: php scripts/profile-wordfence-v3-feed.php <local-feed.json> [--max-bytes=N]');
        }
    }
    if ($path === null) {
        throw new RuntimeException('Usage: php scripts/profile-wordfence-v3-feed.php <local-feed.json> [--max-bytes=N]');
    }

    $profile = profileFeed($path, $maximumBytes);
    echo "Wordfence v3 feed profile (aggregate only; no identifiers or provider values emitted)\n";
    echo 'Input size: '.$profile['input_bytes']." bytes\n";
    echo 'Top-level vulnerability records: '.$profile['records']."\n";
    echo 'Software entries: '.$profile['software_entries']."\n";
    echo 'Affected version ranges: '.$profile['ranges']."\n";
    printCounts('Top-level record key shapes', $profile['record_shapes']);
    printCounts('Software entry key shapes', $profile['software_shapes']);
    printCounts('affected_versions JSON types', $profile['affected_versions_types']);
    printCounts('Range key shapes', $profile['range_shapes']);
    foreach (['from_version', 'to_version'] as $field) {
        printCounts("{$field} JSON types", $profile['endpoint'][$field]['types']);
        unset($profile['endpoint'][$field]['types']);
        printCounts("{$field} categories (categories may overlap)", $profile['endpoint'][$field]);
    }
    printCounts('from_inclusive field values', $profile['boundary']['from_inclusive']);
    printCounts('to_inclusive field values', $profile['boundary']['to_inclusive']);
    printCounts('Missing or structurally unexpected fields', $profile['missing_fields']);
    echo "\nReview note\n- version_compare_semantics_need_review identifies non-numeric-dotted tokens only; it does not establish comparison safety.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Profile failed: '.$error->getMessage()."\n");
    exit(2);
}
