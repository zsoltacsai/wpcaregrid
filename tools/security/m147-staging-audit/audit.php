#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Standalone, read-only staging identity audit. This script deliberately does
 * not bootstrap Laravel/WordPress and does not launch subprocesses.
 */
const AUDIT_VERSION = '1.0.0';
const M12_GATES = [
    'M12_DESTRUCTIVE_RETENTION_ENABLED',
    'M12_PROFILE_ANONYMIZATION_ENABLED',
    'M12_BACKUP_DELETION_ENABLED',
    'M12_PROVIDER_DELETION_ENABLED',
    'M12_BULK_RETENTION_EXECUTION_ENABLED',
    'M12_SYNTHETIC_PROFILE_ANONYMIZATION_ENABLED',
];

function fail(string $code): never
{
    fwrite(STDERR, "BLOCKED: {$code}\n");
    exit(2);
}

function parseOptions(array $argv): array
{
    $options = [];
    for ($index = 1, $count = count($argv); $index < $count; $index++) {
        $name = $argv[$index];
        if (! in_array($name, ['--mode', '--format', '--checkout', '--expected-host', '--expected-account'], true)) {
            fail('UNSUPPORTED_OPTION');
        }
        if (isset($options[$name]) || ! isset($argv[$index + 1]) || str_starts_with($argv[$index + 1], '--')) {
            fail('INVALID_OPTIONS');
        }
        $options[$name] = $argv[++$index];
    }

    return $options;
}

function currentIdentity(): array
{
    if (! function_exists('posix_geteuid') || ! function_exists('posix_getpwuid')) {
        fail('POSIX_IDENTITY_UNAVAILABLE');
    }
    $record = posix_getpwuid(posix_geteuid());
    if (! is_array($record) || ! isset($record['name']) || ! is_string($record['name'])) {
        fail('EFFECTIVE_ACCOUNT_UNAVAILABLE');
    }

    return ['name' => $record['name'], 'uid' => posix_geteuid()];
}

function readSafeFile(string $path, int $maximumBytes): string
{
    if (is_link($path) || ! is_file($path)) {
        fail('UNSAFE_OR_MISSING_GIT_METADATA');
    }
    $size = filesize($path);
    if (! is_int($size) || $size < 1 || $size > $maximumBytes) {
        fail('INVALID_GIT_METADATA_SIZE');
    }
    $contents = file_get_contents($path);
    if (! is_string($contents) || strlen($contents) > $maximumBytes) {
        fail('GIT_METADATA_READ_FAILED');
    }

    return trim($contents);
}

function rejectSymlinkComponents(string $path): void
{
    $absolute = str_starts_with($path, DIRECTORY_SEPARATOR);
    $segments = explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR));
    $cursor = $absolute ? DIRECTORY_SEPARATOR : '';
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        $cursor = $cursor === DIRECTORY_SEPARATOR ? $cursor.$segment : ($cursor === '' ? $segment : $cursor.DIRECTORY_SEPARATOR.$segment);
        if (is_link($cursor)) {
            fail('GIT_METADATA_SYMLINK');
        }
    }
}

function resolveGitDirectory(string $checkout): string
{
    $marker = $checkout.'/.git';
    if (is_link($marker)) {
        fail('GIT_METADATA_SYMLINK');
    }
    if (is_dir($marker)) {
        $resolved = realpath($marker);
    } elseif (is_file($marker)) {
        $pointer = readSafeFile($marker, 4096);
        if (! preg_match('/^gitdir: ([^\r\n]+)$/', $pointer, $matches)) {
            fail('INVALID_GIT_WORKTREE_POINTER');
        }
        $gitDirectory = $matches[1];
        if (! str_starts_with($gitDirectory, DIRECTORY_SEPARATOR)) {
            $gitDirectory = $checkout.'/'.$gitDirectory;
        }
        $resolved = realpath($gitDirectory);
    } else {
        fail('GIT_METADATA_UNAVAILABLE');
    }
    if (! is_string($resolved) || ! is_dir($resolved)) {
        fail('GIT_DIRECTORY_UNAVAILABLE');
    }
    rejectSymlinkComponents($resolved);

    return $resolved;
}

function readPackedRef(string $gitDirectory, string $ref): ?string
{
    $packedRefs = $gitDirectory.'/packed-refs';
    if (! file_exists($packedRefs)) {
        return null;
    }
    $contents = readSafeFile($packedRefs, 2_000_000);
    foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
        if (preg_match('/^([a-f0-9]{40}|[a-f0-9]{64}) '.preg_quote($ref, '/').'$/', $line, $matches)) {
            return $matches[1];
        }
    }

    return null;
}

function readGitHead(string $gitDirectory): string
{
    $head = readSafeFile($gitDirectory.'/HEAD', 1024);
    if (preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/', $head)) {
        return $head;
    }
    if (! preg_match('/^ref: (refs\/(?:heads|tags)\/[A-Za-z0-9._\/-]+)$/', $head, $matches)
        || str_contains($matches[1], '..')
        || str_contains($matches[1], '//')) {
        fail('INVALID_GIT_HEAD');
    }
    $ref = $matches[1];
    $refPath = $gitDirectory.'/'.$ref;
    rejectSymlinkComponents($refPath);
    if (is_file($refPath)) {
        $commit = readSafeFile($refPath, 1024);
    } else {
        $commit = readPackedRef($gitDirectory, $ref) ?? '';
    }
    if (! preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/', $commit)) {
        fail('GIT_COMMIT_UNAVAILABLE');
    }

    return $commit;
}

function auditIdentity(array $options): array
{
    foreach (['--checkout', '--expected-host', '--expected-account'] as $required) {
        if (! isset($options[$required]) || $options[$required] === '') {
            fail('REQUIRED_IDENTITY_BOUNDARY_MISSING');
        }
    }
    $requestedPath = $options['--checkout'];
    if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/', $options['--expected-host'])
        || ! preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $options['--expected-account'])) {
        fail('INVALID_EXPECTED_IDENTITY_FORMAT');
    }
    if (! str_starts_with($requestedPath, DIRECTORY_SEPARATOR) || str_contains($requestedPath, "\0")) {
        fail('CHECKOUT_PATH_MUST_BE_ABSOLUTE');
    }
    if (is_link($requestedPath) || ! is_dir($requestedPath)) {
        fail('CHECKOUT_NOT_A_REAL_DIRECTORY');
    }
    $checkout = realpath($requestedPath);
    if (! is_string($checkout) || $checkout !== rtrim($requestedPath, DIRECTORY_SEPARATOR)) {
        fail('CHECKOUT_PATH_IDENTITY_MISMATCH');
    }
    $hostname = gethostname();
    if (! is_string($hostname) || $hostname !== $options['--expected-host']) {
        fail('HOST_IDENTITY_MISMATCH');
    }
    $identity = currentIdentity();
    if ($identity['name'] !== $options['--expected-account'] || $identity['uid'] === 0) {
        fail('ACCOUNT_IDENTITY_MISMATCH_OR_PRIVILEGED_ACCOUNT');
    }
    $freeBytes = disk_free_space($checkout);
    if (! is_float($freeBytes) && ! is_int($freeBytes)) {
        fail('FILESYSTEM_CAPACITY_UNAVAILABLE');
    }
    $gitDirectory = resolveGitDirectory($checkout);

    return [
        'schema_version' => '1.0.0',
        'audit_version' => AUDIT_VERSION,
        'audit_id' => bin2hex(random_bytes(16)),
        'scope' => 'RO-01-RO-04-read-only-identity-runtime-capacity',
        'classification' => 'candidate-host-and-account-matched',
        'executed_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        'host' => ['hostname' => $hostname, 'expected_match' => true],
        'account' => ['name' => $identity['name'], 'uid' => $identity['uid'], 'expected_match' => true, 'privileged' => false],
        'application' => ['git_head' => readGitHead($gitDirectory)],
        'runtime' => ['php_version' => PHP_VERSION],
        'filesystem' => ['available_bytes' => (int) $freeBytes],
        'm12_gates' => array_fill_keys(M12_GATES, ['effective_value' => null, 'verification' => 'UNKNOWN', 'provenance' => 'no-approved-effective-runtime-source']),
        'side_effects' => ['application_bootstrap' => false, 'subprocesses' => false, 'database_writes' => false, 'filesystem_writes' => false],
    ];
}

function markdownReport(array $evidence): string
{
    if (($evidence['scope'] ?? null) === 'M12-effective-gates-read-only') {
        $lines = [
            '# M12 effective gate audit',
            '',
            '> No verified side-effect-free source for effective Laravel configuration is currently approved.',
            '',
            '| Gate | Effective boolean | Provenance | Status |',
            '|---|---|---|---|',
        ];
        foreach ($evidence['gates'] as $name => $gate) {
            $lines[] = '| `'.$name.'` | UNKNOWN | `'.$gate['provenance'].'` | UNKNOWN |';
        }

        return implode("\n", [...$lines, '', 'No `.env`, cached configuration, Laravel bootstrap, or live setting was read.', '']);
    }

    return implode("\n", [
        '# M147 staging read-only audit evidence',
        '',
        '> Machine-generated from the same allowlisted data as the JSON record.',
        '',
        '| Field | Value |',
        '|---|---|',
        '| Audit ID | `'.$evidence['audit_id'].'` |',
        '| UTC | `'.$evidence['executed_at'].'` |',
        '| Host | `'.$evidence['host']['hostname'].'` (expected identity matched) |',
        '| Account | `'.$evidence['account']['name'].'` (UID '.$evidence['account']['uid'].', non-root) |',
        '| Application Git HEAD | `'.$evidence['application']['git_head'].'` |',
        '| PHP | `'.$evidence['runtime']['php_version'].'` |',
        '| Filesystem available bytes | `'.$evidence['filesystem']['available_bytes'].'` |',
        '',
        '## M12 effective gates',
        '',
        'All six values are `UNKNOWN`. This runner does not load Laravel or read `.env`/cached configuration.',
        '',
        '## Side-effect boundary',
        '',
        'No application bootstrap, subprocess, database write, or filesystem write was performed by the audit runner.',
        '',
    ]);
}

function m12Evidence(): array
{
    $gates = [];
    foreach (M12_GATES as $name) {
        $gates[$name] = [
            'effective_boolean' => 'UNKNOWN',
            'provenance' => 'unavailable-no-approved-effective-config-source',
            'verification_status' => 'UNKNOWN',
        ];
    }

    return [
        'schema_version' => '1.0.0',
        'audit_version' => AUDIT_VERSION,
        'scope' => 'M12-effective-gates-read-only',
        'executed_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        'gates' => $gates,
        'side_effects' => ['application_bootstrap' => false, 'subprocesses' => false, 'configuration_reads' => false, 'writes' => false],
    ];
}

$options = parseOptions($argv);
$mode = $options['--mode'] ?? 'identity';
$format = $options['--format'] ?? 'json';
if (! in_array($mode, ['identity', 'm12'], true) || ! in_array($format, ['json', 'markdown'], true) || array_diff(array_keys($options), ['--mode', '--format', '--checkout', '--expected-host', '--expected-account']) !== []) {
    fail('UNSUPPORTED_MODE');
}

try {
    if ($mode === 'm12') {
        if (array_diff(array_keys($options), ['--mode', '--format']) !== []) {
            fail('M12_MODE_ACCEPTS_NO_TARGET_OR_SOURCE_OVERRIDES');
        }
        $evidence = m12Evidence();
    } else {
        $evidence = auditIdentity($options);
    }
    echo $format === 'markdown'
        ? markdownReport($evidence)
        : json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable) {
    fail('AUDIT_FAILED');
}
