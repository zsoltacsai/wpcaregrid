<?php

namespace WPCareGrid\Connector\Backups;

use WPCareGrid\Connector\Core\Lock;

/**
 * Restores files from a backup archive in two steps:
 *   1. extract() unpacks the whole archive into a private staging
 *      directory, validating every entry (TarReader + PathGuard). A bad
 *      archive is refused before anything live is touched.
 *   2. apply() copies the staged files into place, only where
 *      FilePolicy::restore_target() allows (wp-content and the root extras;
 *      never wp-config.php or the connector itself), never writing through
 *      a symlink or outside the site.
 *
 * Files added to the site after the backup are left in place.
 */
final class FileRestorer
{
    public array $stats = ['files' => 0, 'dirs' => 0, 'noop' => 0, 'skipped' => 0, 'unsafe' => 0];

    /** @var list<array{path: string, operation: string, destination_type: string, exists: bool, comparison: string, mutation_required: bool, permission_allowed: ?bool, failure_reason: ?string, source_sha256?: string, destination_sha256?: string}> */
    private array $restore_plan = [];

    /** @var array<string, array> */
    private array $plan_by_path = [];

    private bool $plan_ready = false;

    /** @var array<string, true> */
    private array $visited = [];

    public function extract(string $archive, string $staging): void
    {
        if (is_link($staging) || (! is_dir($staging) && ! wp_mkdir_p($staging)) || ! is_dir($staging) || is_link($staging)
            || ! PathGuard::contained($staging, $staging)) {
            throw new BackupFailure('UNSAFE_PATH', 'The private restore staging directory is unsafe.');
        }
        @chmod($staging, 0700);

        (new TarReader($archive))->each(function (string $name, string $type, int $size, callable $read) use ($staging) {
            $target = PathGuard::join($staging, $name);

            if ($type === 'dir') {
                $this->ensure_staging_directory($staging, $name);

                return;
            }

            $directory = dirname($name);
            $this->ensure_staging_directory($staging, $directory === '.' ? '' : $directory);
            if (is_link($target) || file_exists($target) || ! PathGuard::contained($staging, $target)
                || PathGuard::has_symlink_component($staging, $target)) {
                throw new BackupFailure('UNSAFE_PATH', 'An unsafe or duplicate restore staging path was refused.');
            }
            // Exclusive creation prevents overwrite/following a final-component symlink.
            $out = @fopen($target, 'x+b');
            if (! $out) {
                throw new BackupFailure('INSUFFICIENT_DISK_SPACE', 'A file could not be extracted.');
            }
            @chmod($target, 0600);
            while (($data = $read()) !== '') {
                if (fwrite($out, $data) !== strlen($data)) {
                    fclose($out);
                    @unlink($target);
                    throw new BackupFailure('INSUFFICIENT_DISK_SPACE', 'A file could not be extracted.');
                }
            }
            fclose($out);
        });
    }

    /** Create staging parents one component at a time and recheck each resolution. */
    private function ensure_staging_directory(string $staging, string $relative): void
    {
        if (is_link($staging) || ! is_dir($staging) || ! PathGuard::contained($staging, $staging)) {
            throw new BackupFailure('UNSAFE_PATH', 'The private restore staging directory is unsafe.');
        }

        $current = realpath($staging);
        if ($current === false) {
            throw new BackupFailure('UNSAFE_PATH', 'The private restore staging directory is unavailable.');
        }

        if ($relative === '') {
            return;
        }

        foreach (explode('/', PathGuard::relative($relative)) as $part) {
            $current .= DIRECTORY_SEPARATOR.$part;
            if (is_link($current) || (file_exists($current) && ! is_dir($current))) {
                throw new BackupFailure('UNSAFE_PATH', 'An unsafe restore staging path was refused.');
            }
            if (! is_dir($current) && ! @mkdir($current, 0700)) {
                throw new BackupFailure('INSUFFICIENT_DISK_SPACE', 'A restore staging directory could not be created.');
            }
            if (is_link($current) || ! PathGuard::contained($staging, $current)
                || PathGuard::has_symlink_component($staging, $current)) {
                throw new BackupFailure('UNSAFE_PATH', 'An unsafe restore staging path was refused.');
            }
        }
    }

    public function apply(string $staging): void
    {
        if (! $this->plan_ready) {
            throw new BackupFailure('RESTORE_PLAN_MISSING', 'The restore plan was not prepared.');
        }

        $this->visited = [];
        $this->apply_tree($staging, '');
        foreach ($this->restore_plan as $entry) {
            if ($entry['operation'] !== 'skip' && ! isset($this->visited[$entry['path']])) {
                throw $this->apply_failure('apply_plan', $entry['path'], 'restore_plan_changed');
            }
        }
    }

    /** Refuse known destination conflicts before RestoreHandler swaps database tables. */
    public function preflight(string $staging): void
    {
        $this->restore_plan = [];
        $this->plan_by_path = [];
        $this->plan_ready = false;
        $this->plan_tree($staging, '');
        usort($this->restore_plan, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
        $this->plan_by_path = array_column($this->restore_plan, null, 'path');
        $this->plan_ready = true;

        foreach ($this->restore_plan as $entry) {
            if ($entry['failure_reason'] !== null && $entry['mutation_required']) {
                if ($entry['failure_reason'] === 'unsafe_parent') {
                    throw new BackupFailure('UNSAFE_PATH', 'An unsafe restore destination was refused.', [
                        'operation' => 'preflight',
                        'relative_path' => $entry['path'],
                        'error_category' => 'unsafe_parent',
                        'restore_plan' => $this->plan_summary(),
                    ]);
                }
                throw new BackupFailure('RESTORE_FILES_FAILED', 'A file could not be restored.', [
                    'operation' => 'preflight',
                    'relative_path' => $entry['path'],
                    'error_category' => $entry['failure_reason'],
                    'restore_plan' => $this->plan_summary(),
                ]);
            }
        }
    }

    /** @return list<array> */
    public function plan(): array
    {
        return $this->restore_plan;
    }

    /** @return array{entries: int, operations: array<string, int>} */
    public function plan_summary(): array
    {
        $operations = array_count_values(array_column($this->restore_plan, 'operation'));
        ksort($operations, SORT_STRING);

        return ['entries' => count($this->restore_plan), 'operations' => $operations];
    }

    private function plan_tree(string $staging, string $relative, bool $forceSkip = false): void
    {
        $dir = $relative === '' ? $staging : $staging.'/'.$relative;

        foreach ((array) scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $entry = $relative === '' ? $item : $relative.'/'.$item;
            $source = $dir.'/'.$item;
            $target = FilePolicy::restore_target($entry);

            if (is_dir($source)) {
                if ($forceSkip) {
                    $this->add_plan($entry, 'skip', 'excluded', false, 'not_applicable', false, null, 'outside_restore_scope');
                    $this->plan_tree($staging, $entry, true);

                    continue;
                }

                if ($target === null) {
                    $structuralRoot = in_array($entry, ['wp-content', 'root'], true);
                    $this->add_plan($entry, $structuralRoot ? 'noop' : 'skip', $structuralRoot ? 'directory' : 'excluded', $structuralRoot, 'not_applicable', false, null, $structuralRoot ? null : 'outside_restore_scope');
                    $this->plan_tree($staging, $entry, ! $structuralRoot);

                    continue;
                }

                if (is_link($target)) {
                    $this->add_plan($entry, 'skip', 'symlink', true, 'not_checked', false, null, 'unsafe_destination');
                    $this->plan_tree($staging, $entry, true);

                    continue;
                }

                if (file_exists($target) && ! is_dir($target)) {
                    $this->add_plan($entry, 'mkdir', 'file', true, 'type_conflict', true, false, 'destination_type_conflict');
                    $this->plan_tree($staging, $entry);

                    continue;
                }

                $exists = is_dir($target);
                $operation = $exists ? 'noop' : 'mkdir';
                [$permission, $failure] = $exists ? [null, null] : $this->parent_writable($target);
                $this->add_plan($entry, $operation, $exists ? 'directory' : 'missing', $exists, 'not_applicable', ! $exists, $permission, $failure);
                $this->plan_tree($staging, $entry);

                continue;
            }

            if ($forceSkip) {
                $this->add_plan($entry, 'skip', 'excluded', false, 'not_applicable', false, null, 'outside_restore_scope');

                continue;
            }

            if ($target === null) {
                $this->add_plan($entry, 'skip', 'excluded', false, 'not_applicable', false, null, 'outside_restore_scope');

                continue;
            }

            if (is_link($target) || ! $this->safe($target)) {
                $this->add_plan($entry, 'skip', 'symlink_or_unsafe', file_exists($target) || is_link($target), 'not_checked', false, null, 'unsafe_destination');

                continue;
            }

            if (file_exists($target) && ! is_file($target)) {
                $this->add_plan($entry, 'replace', 'directory_or_special', true, 'type_conflict', true, false, 'destination_type_conflict');

                continue;
            }

            $hashWarning = null;
            $sourceHash = $this->sha256_file($source, $hashWarning);
            if (! is_string($sourceHash)) {
                $this->add_plan($entry, 'replace', file_exists($target) ? 'file' : 'missing', file_exists($target), 'unreadable_source', true, false, $this->error_category($hashWarning));

                continue;
            }

            $exists = is_file($target);
            $destinationWarning = null;
            $destinationHash = $exists ? $this->sha256_file($target, $destinationWarning) : null;
            if ($exists && ! is_string($destinationHash)) {
                $this->add_plan($entry, 'replace', 'file', true, 'unreadable_destination', true, false, $this->error_category($destinationWarning), $sourceHash);

                continue;
            }

            $same = $exists && hash_equals($sourceHash, (string) $destinationHash);
            $operation = ! $exists ? 'create' : ($same ? 'noop' : 'replace');
            [$permission, $failure] = $operation === 'noop' ? [null, null] : $this->parent_writable($target);
            $this->add_plan(
                $entry,
                $operation,
                $exists ? 'file' : 'missing',
                $exists,
                ! $exists ? 'missing' : ($same ? 'identical' : 'different'),
                $operation !== 'noop',
                $permission,
                $failure,
                $sourceHash,
                $destinationHash
            );
        }
    }

    private function add_plan(
        string $path,
        string $operation,
        string $destinationType,
        bool $exists,
        string $comparison,
        bool $mutationRequired,
        ?bool $permissionAllowed,
        ?string $failureReason,
        ?string $sourceHash = null,
        ?string $destinationHash = null
    ): void {
        $item = [
            'path' => PathGuard::relative($path),
            'operation' => $operation,
            'destination_type' => $destinationType,
            'exists' => $exists,
            'comparison' => $comparison,
            'mutation_required' => $mutationRequired,
            'permission_allowed' => $permissionAllowed,
            'failure_reason' => $failureReason,
        ];
        if ($sourceHash !== null) {
            $item['source_sha256'] = $sourceHash;
        }
        if ($destinationHash !== null) {
            $item['destination_sha256'] = $destinationHash;
        }
        $this->restore_plan[] = $item;
    }

    /** @return array{?bool, ?string} */
    private function parent_writable(string $target): array
    {
        $parent = dirname($target);
        if (! $this->safe($parent)) {
            return [false, 'unsafe_parent'];
        }
        $ancestor = $parent;
        while (! is_dir($ancestor)) {
            if (is_file($ancestor) || is_link($ancestor)) {
                return [false, 'destination_not_directory'];
            }
            $next = dirname($ancestor);
            if ($next === $ancestor) {
                return [false, 'destination_parent_missing'];
            }
            $ancestor = $next;
        }

        return is_writable($ancestor) ? [true, null] : [false, 'permission_denied'];
    }

    private function apply_tree(string $staging, string $relative): void
    {
        $dir = $relative === '' ? $staging : $staging.'/'.$relative;

        foreach ((array) scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $entry = $relative === '' ? $item : $relative.'/'.$item;
            $source = $dir.'/'.$item;
            $target = FilePolicy::restore_target($entry);
            $plan = $this->plan_by_path[$entry] ?? null;
            if ($plan === null) {
                throw $this->apply_failure('apply_plan', $entry, 'restore_plan_changed');
            }
            $this->visited[$entry] = true;
            Lock::refresh();

            if (is_dir($source)) {
                if ($plan['operation'] === 'skip') {
                    $this->stats['skipped']++;
                    if ($plan['failure_reason'] === 'unsafe_destination') {
                        $this->stats['unsafe']++;
                    }

                    continue;
                }
                if ($target === null && in_array($entry, ['wp-content', 'root'], true) && $plan['operation'] === 'noop') {
                    $this->stats['noop']++;
                    $this->apply_tree($staging, $entry);

                    continue;
                }
                if ($plan['operation'] === 'mkdir') {
                    if (file_exists($target) || is_link($target)) {
                        throw $this->apply_failure('mkdir', $entry, 'restore_plan_changed');
                    }
                    $warning = null;
                    $created = $this->capture_warning(static fn () => wp_mkdir_p($target), $warning);
                    if (! $created || ! is_dir($target) || is_link($target)) {
                        throw $this->apply_failure('mkdir', $entry, $this->error_category($warning));
                    }
                    $this->stats['dirs']++;
                } elseif ($plan['operation'] === 'noop') {
                    if (! is_dir($target) || is_link($target)) {
                        throw $this->apply_failure('directory_check', $entry, 'restore_plan_changed');
                    }
                    $this->stats['noop']++;
                } else {
                    throw $this->apply_failure('apply_plan', $entry, 'restore_plan_changed');
                }
                $this->apply_tree($staging, $entry);

                continue;
            }

            if ($plan['operation'] === 'skip') {
                $this->stats['skipped']++;
                if ($plan['failure_reason'] === 'unsafe_destination') {
                    $this->stats['unsafe']++;
                }

                continue;
            }

            if ($plan['operation'] === 'noop') {
                $sourceWarning = null;
                $sourceHash = $this->sha256_file($source, $sourceWarning);
                $targetWarning = null;
                $targetHash = is_file($target) && ! is_link($target) ? $this->sha256_file($target, $targetWarning) : null;
                if (! is_string($sourceHash) || ! is_string($targetHash)
                    || ! hash_equals((string) $plan['source_sha256'], $sourceHash)
                    || ! hash_equals($sourceHash, $targetHash)) {
                    throw $this->apply_failure('noop_check', $entry, 'restore_plan_changed');
                }
                $this->stats['noop']++;

                continue;
            }

            if (! in_array($plan['operation'], ['create', 'replace'], true) || is_link($target) || ! $this->safe($target)) {
                throw $this->apply_failure('destination_check', $entry, 'restore_plan_changed');
            }
            $sourceWarning = null;
            $sourceHash = $this->sha256_file($source, $sourceWarning);
            if (! is_string($sourceHash) || ! hash_equals((string) $plan['source_sha256'], $sourceHash)) {
                throw $this->apply_failure('source_check', $entry, 'restore_plan_changed');
            }
            $exists = is_file($target);
            $currentTargetHash = null;
            if ($plan['operation'] === 'replace' && $exists) {
                $targetWarning = null;
                $currentTargetHash = $this->sha256_file($target, $targetWarning);
            }
            if (($plan['operation'] === 'create' && (file_exists($target) || is_link($target)))
                || ($plan['operation'] === 'replace' && (! is_string($currentTargetHash) || ! hash_equals((string) $plan['destination_sha256'], $currentTargetHash)))) {
                throw $this->apply_failure('destination_check', $entry, 'restore_plan_changed');
            }

            $this->replace_file($source, $target, $entry);
            $this->stats['files']++;
        }
    }

    /** Replace a file atomically using an exclusive, unpredictable sibling temporary file. */
    private function replace_file(string $source, string $target, string $relative): void
    {
        $parent = dirname($target);
        if (! is_dir($parent)) {
            $this->capture_warning(static fn () => wp_mkdir_p($parent), $warning);
            if (! is_dir($parent)) {
                throw $this->apply_failure('mkdir', $relative, $this->error_category($warning));
            }
        }

        if (! $this->safe($parent)) {
            throw new BackupFailure('UNSAFE_PATH', 'An unsafe restore destination was refused.', [
                'operation' => 'destination_check',
                'relative_path' => $relative,
                'error_category' => 'unsafe_parent',
            ]);
        }

        if ((file_exists($target) || is_link($target)) && ! is_file($target)) {
            throw $this->apply_failure('destination_check', $relative, 'destination_type_conflict');
        }

        $inputWarning = null;
        $input = $this->capture_warning(static fn () => fopen($source, 'rb'), $inputWarning);
        if (! is_resource($input)) {
            throw $this->apply_failure('open_source', $relative, $this->error_category($inputWarning));
        }

        $temporary = null;
        $output = null;
        $committed = false;
        try {
            // x+b maps to exclusive creation; it will not follow an existing symlink.
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $candidate = $parent.'/.caregrid-restore-'.bin2hex(random_bytes(16)).'.tmp';
                $temporaryWarning = null;
                $candidateHandle = $this->capture_warning(static fn () => fopen($candidate, 'x+b'), $temporaryWarning);
                if (is_resource($candidateHandle)) {
                    $temporary = $candidate;
                    $output = $candidateHandle;
                    break;
                }

                $category = $this->error_category($temporaryWarning);
                if ($category !== 'already_exists') {
                    throw $this->apply_failure('create_temporary', $relative, $category);
                }
            }

            if (! is_resource($output) || $temporary === null) {
                throw $this->apply_failure('create_temporary', $relative, 'temporary_name_collision');
            }

            while (! feof($input)) {
                $readWarning = null;
                $chunk = $this->capture_warning(static fn () => fread($input, 1048576), $readWarning);
                if ($chunk === false) {
                    throw $this->apply_failure('read_source', $relative, $this->error_category($readWarning));
                }
                if ($chunk === '') {
                    break;
                }

                $offset = 0;
                $length = strlen($chunk);
                while ($offset < $length) {
                    $writeWarning = null;
                    $written = $this->capture_warning(static fn () => fwrite($output, substr($chunk, $offset)), $writeWarning);
                    if (! is_int($written) || $written < 1) {
                        throw $this->apply_failure('write_temporary', $relative, $this->error_category($writeWarning));
                    }
                    $offset += $written;
                }
            }

            if (! fflush($output)) {
                throw $this->apply_failure('flush_temporary', $relative, 'write_failed');
            }
            fclose($output);
            $output = null;

            // The target may have appeared or changed type while bytes were staged.
            if (is_link($target) || ((file_exists($target)) && ! is_file($target))) {
                throw $this->apply_failure('destination_check', $relative, 'destination_type_conflict');
            }
            if (! $this->safe($parent) || ! $this->safe($target)) {
                throw new BackupFailure('UNSAFE_PATH', 'An unsafe restore destination was refused.', [
                    'operation' => 'destination_check',
                    'relative_path' => $relative,
                    'error_category' => 'unsafe_parent',
                ]);
            }

            $renameWarning = null;
            $renamed = $this->capture_warning(static fn () => rename($temporary, $target), $renameWarning);
            if (! $renamed) {
                throw $this->apply_failure('rename_into_place', $relative, $this->error_category($renameWarning));
            }

            $committed = true;
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            if (! $committed && is_string($temporary) && (is_file($temporary) || is_link($temporary))) {
                @unlink($temporary);
            }
        }
    }

    /** @template T @param callable(): T $operation @return T */
    private function capture_warning(callable $operation, ?string &$warning): mixed
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    private function error_category(?string $warning): string
    {
        $message = strtolower((string) $warning);

        return match (true) {
            str_contains($message, 'permission denied') => 'permission_denied',
            str_contains($message, 'read-only file system') => 'read_only_filesystem',
            str_contains($message, 'no space left') => 'insufficient_space',
            str_contains($message, 'not a directory') => 'destination_not_directory',
            str_contains($message, 'is a directory') => 'destination_type_conflict',
            str_contains($message, 'file exists') => 'already_exists',
            str_contains($message, 'cross-device link') => 'cross_device',
            str_contains($message, 'no such file') => 'path_missing',
            $warning === null || $warning === '' => 'operation_failed',
            default => 'filesystem_error',
        };
    }

    private function sha256_file(string $path, ?string &$warning): ?string
    {
        $hash = $this->capture_warning(static fn () => hash_file('sha256', $path), $warning);

        return is_string($hash) ? $hash : null;
    }

    private function apply_failure(string $operation, string $relative, string $category): BackupFailure
    {
        return new BackupFailure('RESTORE_FILES_FAILED', 'A file could not be restored.', [
            'operation' => $operation,
            'relative_path' => $relative,
            'error_category' => $category,
        ]);
    }

    /** Inside wp-content or the installation root, following any existing symlinks. */
    private function safe(string $target): bool
    {
        $normalized = rtrim(wp_normalize_path($target), '/');
        $contentRoot = rtrim(wp_normalize_path(WP_CONTENT_DIR), '/');
        $siteRoot = rtrim(wp_normalize_path(ABSPATH), '/');
        $root = str_starts_with($normalized.'/', $contentRoot.'/') ? WP_CONTENT_DIR
            : (dirname($target) === rtrim(ABSPATH, '/') ? ABSPATH : null);

        return is_string($root)
            && PathGuard::contained($root, $target)
            && ! PathGuard::has_symlink_component($root, $target);
    }
}
