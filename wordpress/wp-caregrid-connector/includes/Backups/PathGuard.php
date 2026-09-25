<?php

namespace WPCareGrid\Connector\Backups;

/**
 * Keeps every file operation inside the site. Relative paths from archives
 * or payloads are checked before use: no absolute paths, no "..", no NUL,
 * no backslashes or drive letters; and the resolved location of existing
 * files must stay inside the root (so symlinks cannot lead outside).
 */
final class PathGuard
{
    /** A safe relative path ("wp-content/uploads/a.jpg"), or an exception. */
    public static function relative(string $path): string
    {
        if ($path === '' || strlen($path) > 4096 || str_contains($path, "\0") || str_contains($path, '\\')
            || str_starts_with($path, '/') || preg_match('#^[A-Za-z]:#', $path)) {
            throw new BackupFailure('UNSAFE_PATH', 'An unsafe path was refused.');
        }

        $parts = explode('/', rtrim($path, '/'));
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new BackupFailure('UNSAFE_PATH', 'An unsafe path was refused.');
            }
        }

        return implode('/', $parts);
    }

    /** Join a checked relative path to a root; the result is inside the root. */
    public static function join(string $root, string $relative): string
    {
        return rtrim($root, '/').'/'.self::relative($relative);
    }

    /**
     * An existing path resolves inside the root (symlinks included).
     * Paths that do not exist yet are checked through their nearest existing parent.
     */
    public static function contained(string $root, string $path): bool
    {
        $real_root = realpath($root);
        if ($real_root === false) {
            return false;
        }

        $probe = $path;
        while (! file_exists($probe) && ! is_link($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                return false;
            }
            $probe = $parent;
        }

        $real = realpath($probe);

        return $real !== false && ($real === $real_root || str_starts_with($real.'/', rtrim($real_root, '/').'/'));
    }
}
