<?php

namespace WPCareGrid\Connector\Tests\Integration;

use WPCareGrid\Connector\Tests\Support\TestCase;

final class InventoryTest extends TestCase
{
    public function test_inventory_job_reports_core_plugins_and_themes(): void
    {
        $this->pair();
        $file = $this->install_plugin('caregrid-sample', '1.0.0');
        activate_plugin($file);
        $this->offer_plugin_update($file, '1.2.0', 'https://vendor.example/caregrid-sample.zip');
        $this->offer_core_update('7.1.3');
        $job = $this->job('GET_INVENTORY');
        $this->serve_jobs($job);

        $this->plugin()->runner()->poll();

        $result = $this->posted_result($job['uuid']);
        $this->assertSame('success', $result['status']);
        $this->assertSame('INVENTORY_COLLECTED', $result['result_code']);
        $inventory = $result['data']['inventory'];

        $this->assertSame(['core', 'php_version', 'plugins', 'themes', 'woocommerce', 'collected_at'], array_keys($inventory));
        $this->assertSame(['version' => '7.1.2', 'update_available' => true, 'latest_version' => '7.1.3'], $inventory['core']);
        $this->assertSame(PHP_VERSION, $inventory['php_version']);
        $this->assertSame(['installed' => false, 'active' => false, 'version' => null], $inventory['woocommerce']);

        $plugins = array_column($inventory['plugins'], null, 'file');
        $this->assertSame(
            ['file', 'slug', 'name', 'version', 'active', 'update_available', 'new_version', 'update_source', 'license'],
            array_keys($plugins[$file])
        );
        $this->assertSame('caregrid-sample', $plugins[$file]['slug']);
        $this->assertSame('1.0.0', $plugins[$file]['version']);
        $this->assertTrue($plugins[$file]['active']);
        $this->assertTrue($plugins[$file]['update_available']);
        $this->assertSame('1.2.0', $plugins[$file]['new_version']);
        $this->assertSame('vendor.example', $plugins[$file]['update_source'], 'Only the host, never the package URL.');
        $this->assertTrue($plugins['wp-caregrid-connector/wp-caregrid-connector.php']['active']);

        $themes = array_column($inventory['themes'], null, 'stylesheet');
        $this->assertTrue($themes['twentytwentyfive']['active']);
        $this->assertSame(['stylesheet', 'name', 'version', 'active', 'update_available', 'new_version'], array_keys($themes['twentytwentyfive']));

        deactivate_plugins($file);
    }

    public function test_inventory_contains_no_settings_content_or_package_urls(): void
    {
        $this->pair();
        $file = $this->install_plugin('caregrid-sample', '1.0.0');
        $this->offer_plugin_update($file, '1.2.0', 'https://vendor.example/download?license_key=SECRET-LICENSE-KEY');
        update_option('caregrid_sample_settings', ['api_key' => 'sample-plugin-api-secret']);
        wp_insert_post(['post_title' => 'Draft', 'post_content' => 'UNPUBLISHED-CONTENT', 'post_status' => 'draft']);

        $sent = wp_json_encode($this->plugin()->inventory()->collect());

        foreach (['SECRET-LICENSE-KEY', 'license_key', 'sample-plugin-api-secret', 'UNPUBLISHED-CONTENT', 'download?', 'admin@site.example', WP_CONTENT_DIR] as $secret) {
            $this->assertStringNotContainsString($secret, $sent);
        }
    }

    public function test_unchecked_updates_are_reported_as_unknown_not_up_to_date(): void
    {
        $offered = $this->install_plugin('offered-plugin', '1.0.0');
        $current = $this->install_plugin('current-plugin', '2.0.0');
        $unchecked = $this->install_plugin('unchecked-plugin', '3.0.0');
        set_site_transient('update_plugins', (object) [
            'last_checked' => time(),
            'checked' => [$offered => '1.0.0', $current => '2.0.0'],
            'response' => [$offered => (object) ['slug' => 'offered-plugin', 'plugin' => $offered, 'new_version' => '1.1.0', 'package' => '']],
            'no_update' => [],
            'translations' => [],
        ]);

        $inventory = $this->plugin()->inventory()->collect();

        $states = array_column($inventory['plugins'], 'update_available', 'file');
        $this->assertTrue($states[$offered]);
        $this->assertFalse($states[$current]);
        $this->assertNull($states[$unchecked]);
        $this->assertNull($inventory['core']['update_available'], 'WordPress has not checked for core updates.');
        $this->assertNull(array_column($inventory['themes'], 'update_available', 'stylesheet')['twentytwentyfive']);
    }

    public function test_core_is_up_to_date_only_after_a_completed_check(): void
    {
        global $wp_version;

        // What WordPress stores when its first check failed.
        set_site_transient('update_core', (object) ['updates' => [], 'version_checked' => $wp_version, 'last_checked' => time()]);
        $this->assertNull($this->plugin()->inventory()->collect()['core']['update_available']);

        set_site_transient('update_core', (object) ['updates' => [(object) ['response' => 'latest', 'current' => $wp_version, 'locale' => 'en_US']], 'version_checked' => $wp_version, 'last_checked' => time()]);
        $this->assertFalse($this->plugin()->inventory()->collect()['core']['update_available']);
    }

    public function test_checked_version_that_changed_since_is_unknown_again(): void
    {
        $file = $this->install_plugin('caregrid-sample', '1.1.0');
        // The last check saw 1.0.0; the site has changed since.
        set_site_transient('update_plugins', (object) ['last_checked' => time(), 'checked' => [$file => '1.0.0'], 'response' => [], 'no_update' => [], 'translations' => []]);

        $this->assertNull(array_column($this->plugin()->inventory()->collect()['plugins'], 'update_available', 'file')[$file]);
    }

    public function test_woocommerce_is_detected_when_installed(): void
    {
        $file = $this->install_plugin('woocommerce', '9.9.0');
        $this->assertSame('woocommerce/woocommerce.php', $file);

        $inventory = $this->plugin()->inventory()->collect();

        $this->assertSame(['installed' => true, 'active' => false, 'version' => '9.9.0'], $inventory['woocommerce']);
    }

    public function test_license_states_are_never_guessed(): void
    {
        $org = $this->install_plugin('org-plugin', '1.0.0');
        $commercial = $this->install_plugin('pro-plugin', '2.0.0', " * Update URI: https://pro-plugin.example/updates\n");
        $valid = $this->install_plugin('licensed-plugin', '3.0.0', " * Update URI: https://licensed.example\n");
        $expired = $this->install_plugin('expired-plugin', '3.0.0', " * Update URI: https://expired.example\n");
        $this->offer_plugin_update($org, '1.1.0', 'https://downloads.wordpress.org/plugin/org-plugin.zip', 'w.org/plugins');

        add_filter('wp_caregrid_license_providers', fn ($providers) => $providers + [
            'vendor_api' => function (string $file) use ($valid, $expired, $commercial) {
                return match ($file) {
                    $valid => ['status' => 'valid', 'expires_at' => '2027-03-01T00:00:00Z'],
                    $expired => ['status' => 'expired', 'expires_at' => '2026-01-15'],
                    // "Could not check" must stay unknown, never become valid or expired.
                    $commercial => ['status' => 'unavailable'],
                    default => null,
                };
            },
        ]);

        $by_file = array_column($this->plugin()->inventory()->collect()['plugins'], 'license', 'file');

        $this->assertSame(['status' => 'not_available', 'expires_at' => null, 'source' => 'detection'], $by_file[$org]);
        $this->assertSame(['status' => 'unknown', 'expires_at' => null, 'source' => 'detection'], $by_file[$commercial]);
        $this->assertSame(['status' => 'valid', 'expires_at' => '2027-03-01', 'source' => 'vendor_api'], $by_file[$valid]);
        $this->assertSame(['status' => 'expired', 'expires_at' => '2026-01-15', 'source' => 'vendor_api'], $by_file[$expired]);
    }

    public function test_license_check_job_reports_license_states(): void
    {
        $this->pair();
        $file = $this->install_plugin('pro-plugin', '2.0.0', " * Update URI: https://pro-plugin.example/updates\n");
        $job = $this->job('LICENSE_CHECK');
        $this->serve_jobs($job);

        $this->plugin()->runner()->poll();

        $result = $this->posted_result($job['uuid']);
        $this->assertSame('LICENSES_CHECKED', $result['result_code']);
        $licenses = array_column($result['data']['licenses'], null, 'file');
        $this->assertSame('unknown', $licenses[$file]['status']);
    }
}
