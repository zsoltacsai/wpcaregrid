<?php

namespace WPCareGrid\Connector\Security\Scan;

use WPCareGrid\Connector\Backups\TempDir;
use WPCareGrid\Connector\Security\Quarantine\QuarantineStore;

/**
 * Walks the files a security scan looks at, in a fixed order, so a scan can
 * stop after N files and continue with file N+1 in its next part.
 *
 * Roots are fixed here, never supplied by the server: WordPress core
 * (root files, wp-admin, wp-includes) and wp-content (plugins, themes,
 * uploads, mu-plugins and the rest). Symlinks are never followed, and every
 * directory must resolve inside its root. Not scanned: version control and
 * node_modules folders, backup archives of backup plugins, and the
 * connector's own temporary and quarantine directories.
 */
final class FileWalker
{
    private const SKIPPED_DIRS = ['.git', '.svn', '.hg', 'node_modules'];

    /** wp-content subdirectories holding other backup plugins' archives. */
    private const SKIPPED_CONTENT_DIRS = ['updraft', 'ai1wm-backups', 'wpvividbackups', 'backups-dup-lite', 'backups-dup-pro', 'backup-db', 'wp-snapshots'];

    /** @var array{links: int, unreadable: int, outside: int} */
    public array $skipped = ['links' => 0, 'unreadable' => 0, 'outside' => 0];

    /**
     * Files in scan order, as [relative path, absolute path].
     *
     * @return \Generator<int, array{0: string, 1: string}>
     */
    public function files(): \Generator
    {
        $abspath = rtrim(wp_normalize_path(ABSPATH), '/');
        $content = rtrim(wp_normalize_path(WP_CONTENT_DIR), '/');

        // Root files of the WordPress installation (not wp-config.php's contents: see ConfigInspector).
        foreach ($this->entries($abspath) as $name) {
            $path = $abspath.'/'.$name;
            if (is_file($path) && ! is_link($path)) {
                yield [$name, $path];
            } elseif (is_link($path)) {
                $this->skipped['links']++;
            }
        }

        foreach (['wp-admin', 'wp-includes'] as $dir) {
            yield from $this->walk($abspath.'/'.$dir, $abspath);
        }

        yield from $this->walk($content, $content);
    }

    /** @return \Generator<int, array{0: string, 1: string}> */
    private function walk(string $dir, string $root): \Generator
    {
        if (is_link($dir)) {
            $this->skipped['links']++;

            return;
        }

        $real = realpath($dir);
        $real_root = realpath($root);
        if ($real === false || $real_root === false || ! ($real === $real_root || str_starts_with(wp_normalize_path($real).'/', rtrim(wp_normalize_path($real_root), '/').'/'))) {
            $this->skipped['outside']++;

            return;
        }

        if ($this->skipped_dir($dir)) {
            return;
        }

        $subdirs = [];
        foreach ($this->entries($dir) as $name) {
            $path = $dir.'/'.$name;

            if (is_link($path)) {
                $this->skipped['links']++;

                continue;
            }

            if (is_dir($path)) {
                $subdirs[] = $path;
            } elseif (is_file($path)) {
                $relative = SitePaths::relative($path);
                if ($relative !== null) {
                    yield [$relative, $path];
                }
            }
        }

        foreach ($subdirs as $subdir) {
            yield from $this->walk($subdir, $root);
        }
    }

    /** @return list<string> Sorted, so the order is the same on every part. */
    private function entries(string $dir): array
    {
        $items = @scandir($dir);
        if ($items === false) {
            $this->skipped['unreadable']++;

            return [];
        }

        return array_values(array_filter($items, fn ($item) => $item !== '.' && $item !== '..'));
    }

    private function skipped_dir(string $dir): bool
    {
        $name = basename($dir);
        if (in_array($name, self::SKIPPED_DIRS, true)) {
            return true;
        }

        $relative = SitePaths::relative($dir);
        if ($relative !== null && str_starts_with($relative, 'wp-content/') && in_array(substr($relative, strlen('wp-content/')), self::SKIPPED_CONTENT_DIRS, true)) {
            return true;
        }

        return TempDir::inside($dir, TempDir::path()) || TempDir::inside($dir, QuarantineStore::path());
    }
}
