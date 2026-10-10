<?php

namespace WPCareGrid\Connector\Tests\Integration;

use WPCareGrid\Connector\Auth\CredentialStore;
use WPCareGrid\Connector\Core\Settings;
use WPCareGrid\Connector\Tests\Support\TestCase;

final class ConnectorPackageUpgradeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        remove_action('upgrader_process_complete', 'wp_version_check');
    }

    protected function tearDown(): void
    {
        add_action('upgrader_process_complete', 'wp_version_check', 10, 0);
        parent::tearDown();
    }

    public function test_generated_release_upgrades_through_wordpress_upgrader_and_preserves_pairing(): void
    {
        $package = (string) getenv('WP_CAREGRID_CONNECTOR_PACKAGE');
        if (! is_file($package)) {
            $this->environment_limitation('CI must provide the generated Connector release ZIP for the upgrade test.');
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $this->environment_limitation('WordPress direct filesystem upgrader validation runs on Linux CI.');
        }

        $this->http->on('POST pair', $this->pair_response([
            'synthetic_site' => true,
            'controlled_execution' => true,
            'latest_connector_version' => WP_CAREGRID_VERSION,
        ]));
        $this->assertTrue($this->plugin()->pairing()->pair(self::PAIRING_CODE)['ok']);
        $credential = (new CredentialStore)->get();
        $this->assertSame(self::CREDENTIAL, $credential);
        $before = Settings::all();

        // Model the existing 0.4.1 installation while retaining its real options.
        $main = WP_PLUGIN_DIR.'/wp-caregrid-connector/wp-caregrid-connector.php';
        $contents = (string) file_get_contents($main);
        $contents = preg_replace('/(\*\s*Version:\s*)'.preg_quote(WP_CAREGRID_VERSION, '/').'/', '${1}0.4.1', $contents, 1);
        file_put_contents($main, $contents, LOCK_EX);
        wp_clean_plugins_cache(false);
        $this->assertSame('0.4.1', get_plugins()['wp-caregrid-connector/wp-caregrid-connector.php']['Version']);

        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        $skin = new \Automatic_Upgrader_Skin;
        $upgrader = new \Plugin_Upgrader($skin);
        $result = $upgrader->run([
            'package' => $package,
            'destination' => WP_PLUGIN_DIR,
            'clear_destination' => true,
            'clear_working' => true,
            'hook_extra' => ['plugin' => 'wp-caregrid-connector/wp-caregrid-connector.php'],
        ]);

        $this->assertFalse(is_wp_error($result), method_exists($skin, 'get_errors') ? wp_json_encode($skin->get_errors()) : 'WordPress plugin update failed.');
        $this->assertNotFalse($result);
        wp_clean_plugins_cache(false);
        $this->assertSame(WP_CAREGRID_VERSION, get_plugins()['wp-caregrid-connector/wp-caregrid-connector.php']['Version']);
        $this->assertTrue(is_plugin_active('wp-caregrid-connector/wp-caregrid-connector.php'));
        $this->assertFileExists(WP_PLUGIN_DIR.'/wp-caregrid-connector/bin/caregrid-controlled-poll.php');
        $this->assertSame($before, Settings::all());
        $this->assertSame($credential, (new CredentialStore)->get());

        $this->http->on('POST heartbeat', ['status' => 200, 'body' => ['ok' => true, 'heartbeat_interval' => 900, 'poll_interval' => 300]]);
        $this->assertTrue($this->plugin()->heartbeat()->send());
        $inventory = $this->plugin()->inventory()->collect();
        $this->assertSame(WP_CAREGRID_VERSION, array_column($inventory['plugins'], 'version', 'file')['wp-caregrid-connector/wp-caregrid-connector.php']);
    }
}
