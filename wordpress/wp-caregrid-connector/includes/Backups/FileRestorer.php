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
    public array $stats = ['files' => 0, 'dirs' => 0, 'skipped' => 0, 'unsafe' => 0];

    public function extract(string $archive, string $staging): void
    {
        wp_mkdir_p($staging);
        @chmod($staging, 0700);

        (new TarReader($archive))->each(function (string $name, string $type, int $size, callable $read) use ($staging) {
            $target = PathGuard::join($staging, $name);

            if ($type === 'dir') {
                wp_mkdir_p($target);

                return;
            }

            wp_mkdir_p(dirname($target));
            $out = @fopen($target, 'wb');
            if (! $out) {
                throw new BackupFailure('INSUFFICIENT_DISK_SPACE', 'A file could not be extracted.');
            }
            while (($data = $read()) !== '') {
                if (fwrite($out, $data) !== strlen($data)) {
                    fclose($out);
                    throw new BackupFailure('INSUFFICIENT_DISK_SPACE', 'A file could not be extracted.');
                }
            }
            fclose($out);
        });
    }

    public function apply(string $staging): void
    {
        $this->copy_tree($staging, '');
    }

    /** Refuse known destination conflicts before RestoreHandler swaps database tables. */
    public function preflight(string $staging): void
    {
        $this->check_tree($staging, '');
    }

    private function check_tree(string $staging, string $relative): void
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
                if ($target !== null && ! is_link($target)) {
                    if ((file_exists($target) && ! is_dir($target))) {
                        throw $this->apply_failure('preflight', $entry, 'destination_type_conflict');
                    }
                    if (! is_dir($target)) {
                        $this->assert_parent_writable($target, $entry);
                    }
                }
                if ($target !== null || $entry === 'wp-content' || $entry === 'root') {
                    $this->check_tree($staging, $entry);
                }

                continue;
            }

            if ($target === null || is_link($target) || ! $this->safe($target)) {
                continue;
            }
            if (file_exists($target) && ! is_file($target)) {
                throw $this->apply_failure('preflight', $entry, 'destination_type_conflict');
            }

            $this->assert_parent_writable($target, $entry);
        }
    }

    private function assert_parent_writable(string $target, string $relative): void
    {
        $parent = dirname($target);
        if (! $this->safe($parent)) {
            throw new BackupFailure('UNSAFE_PATH', 'An unsafe restore destination was refused.', [
                'operation' => 'preflight',
                'relative_path' => $relative,
                'error_category' => 'unsafe_parent',
            ]);
        }
        $ancestor = $parent;
        while (! is_dir($ancestor)) {
            if (is_file($ancestor) || is_link($ancestor)) {
                throw $this->apply_failure('preflight', $relative, 'destination_not_directory');
            }
            $next = dirname($ancestor);
            if ($next === $ancestor) {
                throw $this->apply_failure('preflight', $relative, 'destination_parent_missing');
            }
            $ancestor = $next;
        }

        if (! is_writable($ancestor)) {
            throw $this->apply_failure('preflight', $relative, 'permission_denied');
        }
    }

    private function copy_tree(string $staging, string $relative): void
    {
        $dir = $relative === '' ? $staging : $staging.'/'.$relative;

        foreach ((array) scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $entry = $relative === '' ? $item : $relative.'/'.$item;
            $source = $dir.'/'.$item;
            $target = FilePolicy::restore_target($entry);
            Lock::refresh();

            if (is_dir($source)) {
                if ($target !== null && ! is_dir($target) && ! is_link($target) && $this->safe($target)) {
                    wp_mkdir_p($target);
                    $this->stats['dirs']++;
                }
                // Descend even when the directory itself maps to nothing (e.g. "root/").
                if ($target !== null || $entry === 'wp-content' || $entry === 'root') {
                    $this->copy_tree($staging, $entry);
                }

                continue;
            }

            if ($target === null) {
                $this->stats['skipped']++;

                continue;
            }

            if (is_link($target) || ! $this->safe($target)) {
                $this->stats['unsafe']++;

                continue;
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
        $root = rtrim(wp_normalize_path(ABSPATH), '/');

        return $normalized === $root
            || PathGuard::contained(WP_CONTENT_DIR, $target)
            || (dirname($target) === rtrim(ABSPATH, '/') && PathGuard::contained(ABSPATH, $target));
    }
}
