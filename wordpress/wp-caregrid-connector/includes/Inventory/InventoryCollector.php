<?php

namespace WPCareGrid\Connector\Inventory;

use WPCareGrid\Connector\Licenses\LicenseDetector;

/**
 * What is installed, and what can be updated. Names, versions and update
 * availability only: no plugin settings, options, content or files.
 */
final class InventoryCollector
{
    public function __construct(private readonly LicenseDetector $licenses) {}

    public function collect(): array
    {
        $this->load_admin_functions();

        return [
            'core' => $this->core(),
            'php_version' => PHP_VERSION,
            'plugins' => $this->plugins(),
            'themes' => $this->themes(),
            'woocommerce' => self::woocommerce(),
            'collected_at' => gmdate('c'),
        ];
    }

    /** @return array{installed: bool, active: bool, version: ?string} */
    public static function woocommerce(): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        $file = 'woocommerce/woocommerce.php';
        $plugins = get_plugins();

        return [
            'installed' => isset($plugins[$file]),
            'active' => is_plugin_active($file),
            'version' => $plugins[$file]['Version'] ?? null,
        ];
    }

    private function core(): array
    {
        $offer = null;
        foreach ((array) (get_site_transient('update_core')->updates ?? []) as $update) {
            if ('upgrade' === ($update->response ?? null)) {
                $offer = $update;
                break;
            }
        }

        return [
            'version' => get_bloginfo('version'),
            'update_available' => $offer !== null,
            'latest_version' => $offer->current ?? null,
        ];
    }

    private function plugins(): array
    {
        $updates = get_site_transient('update_plugins');
        $list = [];

        foreach (get_plugins() as $file => $data) {
            $update = $updates->response[$file] ?? null;
            $known = $update ?? ($updates->no_update[$file] ?? null);
            $source = $known ? self::update_source($known) : null;

            $list[] = [
                'file' => $file,
                'slug' => $known->slug ?? dirname($file),
                'name' => wp_strip_all_tags((string) $data['Name']),
                'version' => (string) $data['Version'],
                'active' => is_plugin_active($file),
                'update_available' => $update !== null,
                'new_version' => $update->new_version ?? null,
                'update_source' => $source,
                'license' => $this->licenses->detect($file, $data, $source),
            ];
        }

        return $list;
    }

    private function themes(): array
    {
        $updates = get_site_transient('update_themes');
        $active = get_stylesheet();
        $list = [];

        foreach (wp_get_themes() as $stylesheet => $theme) {
            $update = $updates->response[$stylesheet] ?? null;

            $list[] = [
                'stylesheet' => (string) $stylesheet,
                'name' => wp_strip_all_tags((string) $theme->get('Name')),
                'version' => (string) $theme->get('Version'),
                'active' => $stylesheet === $active,
                'update_available' => $update !== null,
                'new_version' => is_array($update) ? ($update['new_version'] ?? null) : null,
            ];
        }

        return $list;
    }

    /** Where updates come from: just the host, never the package URL. */
    private static function update_source(object $update): string
    {
        $id = (string) ($update->id ?? '');

        if (str_starts_with($id, 'w.org/')) {
            return 'wordpress.org';
        }

        $host = wp_parse_url((string) ($update->url ?? $update->package ?? ''), PHP_URL_HOST);

        return $host ? substr(strtolower((string) $host), 0, 64) : 'unknown';
    }

    private function load_admin_functions(): void
    {
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        require_once ABSPATH.'wp-admin/includes/update.php';
        require_once ABSPATH.'wp-admin/includes/theme.php';
    }
}
