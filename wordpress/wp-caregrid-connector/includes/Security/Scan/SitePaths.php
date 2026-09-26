<?php

namespace WPCareGrid\Connector\Security\Scan;

use WPCareGrid\Connector\Backups\PathGuard;

/**
 * Paths as WP CareGrid sees them: relative to the WordPress root, with
 * wp-content always spelled "wp-content/…" even when WP_CONTENT_DIR lives
 * elsewhere. Every conversion back to a real path is checked: no "..", no
 * absolute paths, and the resolved location (symlinks included) must stay
 * inside core or wp-content.
 */
final class SitePaths
{
    /** "wp-content/uploads/x.php" or "wp-includes/load.php" for a real path, or null when outside the site. */
    public static function relative(string $absolute): ?string
    {
        $absolute = wp_normalize_path($absolute);
        $content = rtrim(wp_normalize_path(WP_CONTENT_DIR), '/').'/';
        $root = rtrim(wp_normalize_path(ABSPATH), '/').'/';

        if (str_starts_with($absolute, $content)) {
            return 'wp-content/'.substr($absolute, strlen($content));
        }

        if (str_starts_with($absolute, $root)) {
            return substr($absolute, strlen($root));
        }

        return null;
    }

    /**
     * The real path for a site-relative path from WP CareGrid, or null when it
     * is unsafe or would resolve outside the site.
     */
    public static function absolute(string $relative): ?string
    {
        try {
            $relative = PathGuard::relative($relative);
        } catch (\Throwable) {
            return null;
        }

        if (str_starts_with($relative, 'wp-content/')) {
            $root = WP_CONTENT_DIR;
            $path = PathGuard::join($root, substr($relative, strlen('wp-content/')));
        } elseif (preg_match('#^(wp-admin|wp-includes)/#', $relative) || ! str_contains($relative, '/')) {
            $root = ABSPATH;
            $path = PathGuard::join($root, $relative);
        } else {
            return null;
        }

        // Existing paths (and the nearest existing parent of new ones) must resolve inside the root.
        return self::contained($root, $path) ? wp_normalize_path($path) : null;
    }

    /** Like PathGuard::contained(), with both sides normalised (realpath() returns backslashes on Windows). */
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
        if ($real === false) {
            return false;
        }

        $real = rtrim(wp_normalize_path($real), '/');
        $real_root = rtrim(wp_normalize_path($real_root), '/');

        return $real === $real_root || str_starts_with($real.'/', $real_root.'/');
    }

    /** The file itself is a symlink, or any directory on the way is. */
    public static function through_link(string $absolute): bool
    {
        $roots = [rtrim(wp_normalize_path(WP_CONTENT_DIR), '/'), rtrim(wp_normalize_path(ABSPATH), '/')];
        $path = wp_normalize_path($absolute);

        while ($path !== '' && ! in_array($path, $roots, true)) {
            if (is_link($path)) {
                return true;
            }
            $parent = dirname($path);
            if ($parent === $path) {
                break;
            }
            $path = $parent;
        }

        return false;
    }
}
