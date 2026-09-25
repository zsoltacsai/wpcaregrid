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

            wp_mkdir_p(dirname($target));
            if (! @copy($source, $target.'.caregrid-restore') || ! @rename($target.'.caregrid-restore', $target)) {
                @unlink($target.'.caregrid-restore');
                throw new BackupFailure('RESTORE_FILES_FAILED', 'A file could not be restored.');
            }
            $this->stats['files']++;
        }
    }

    /** Inside wp-content or the installation root, following any existing symlinks. */
    private function safe(string $target): bool
    {
        return PathGuard::contained(WP_CONTENT_DIR, $target) || (dirname($target) === rtrim(ABSPATH, '/') && PathGuard::contained(ABSPATH, $target));
    }
}
