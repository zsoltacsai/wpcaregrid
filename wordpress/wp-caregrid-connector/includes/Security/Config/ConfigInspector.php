<?php

namespace WPCareGrid\Connector\Security\Config;

use WPCareGrid\Connector\Security\Scan\ReportedFinding;

/**
 * Security-relevant settings the site can report safely: constants and
 * file permissions, never the values of secrets. Nothing is changed.
 */
final class ConfigInspector
{
    /** @return list<ReportedFinding> */
    public function inspect(): array
    {
        $findings = [];
        $environment = wp_get_environment_type();
        $debug = defined('WP_DEBUG') && WP_DEBUG;

        if ($debug && (! defined('WP_DEBUG_DISPLAY') || WP_DEBUG_DISPLAY) && $environment === 'production') {
            $findings[] = $this->finding('CONFIG_DEBUG_DISPLAY', 'medium', 'high', 'PHP errors are shown to visitors on a production site', ['setting' => 'WP_DEBUG_DISPLAY', 'environment' => $environment]);
        }

        // The default debug log is inside wp-content, readable over the web.
        if ($debug && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG === true && is_file(WP_CONTENT_DIR.'/debug.log')) {
            $findings[] = $this->finding('CONFIG_PUBLIC_DEBUG_LOG', 'medium', 'high', 'The debug log is stored in a publicly reachable location', ['setting' => 'WP_DEBUG_LOG', 'environment' => $environment]);
        }

        if (! (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) && ! (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS)) {
            $findings[] = $this->finding('CONFIG_FILE_EDITOR_ENABLED', 'low', 'high', 'The theme and plugin file editor is enabled', ['setting' => 'DISALLOW_FILE_EDIT']);
        }

        $config = $this->wp_config();
        if ($config && self::world_writable($config)) {
            $findings[] = $this->finding('CONFIG_WPCONFIG_WORLD_WRITABLE', 'high', 'high', 'wp-config.php can be changed by any user on the server', ['setting' => 'wp-config.php', 'permissions' => substr(sprintf('%o', (int) fileperms($config)), -4)]);
        }

        foreach (['.htaccess' => ABSPATH.'.htaccess', 'wp-content' => WP_CONTENT_DIR] as $label => $path) {
            if (file_exists($path) && self::world_writable($path)) {
                $findings[] = $this->finding('CONFIG_WORLD_WRITABLE', 'medium', 'high', 'A sensitive location can be changed by any user on the server', ['setting' => $label, 'permissions' => substr(sprintf('%o', (int) fileperms($path)), -4)]);
            }
        }

        return $findings;
    }

    private function wp_config(): ?string
    {
        foreach ([ABSPATH.'wp-config.php', dirname(ABSPATH).'/wp-config.php'] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /** Only meaningful where POSIX permissions exist. */
    private static function world_writable(string $path): bool
    {
        return DIRECTORY_SEPARATOR === '/' && ((int) @fileperms($path) & 0o002) === 0o002;
    }

    private function finding(string $code, string $severity, string $confidence, string $title, array $evidence): ReportedFinding
    {
        return new ReportedFinding('configuration', $code, $severity, $confidence, $title, 'config', null, $evidence);
    }
}
