<?php

namespace WPCareGrid\Connector\Tests\Integration;

use WPCareGrid\Connector\Auth\CredentialStore;
use WPCareGrid\Connector\Core\Scheduler;
use WPCareGrid\Connector\Core\Settings;
use WPCareGrid\Connector\Tests\Support\TestCase;

/** Activation, deactivation and uninstall, through WordPress's own plugin functions. */
final class LifecycleTest extends TestCase
{
    private const FILE = 'wp-caregrid-connector/wp-caregrid-connector.php';

    protected function tearDown(): void
    {
        if (! is_plugin_active(self::FILE)) {
            activate_plugin(self::FILE);
        }

        parent::tearDown();
    }

    public function test_activation_prepares_an_unpaired_site_without_contacting_anyone(): void
    {
        deactivate_plugins(self::FILE);
        Settings::delete_all();

        $this->assertNull(activate_plugin(self::FILE));

        $this->assertTrue(is_plugin_active(self::FILE));
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', get_option('wp_caregrid_installation_id'));
        $this->assertSame(Settings::DISCONNECTED, Settings::get('status'));
        $this->assertFalse(wp_next_scheduled(Scheduler::POLL), 'Nothing is scheduled before pairing.');
        $this->assertFalse(wp_next_scheduled(Scheduler::HEARTBEAT));
        $this->assertSame([], $this->http->requests, 'Activation makes no network requests.');
    }

    public function test_connector_options_are_not_autoloaded(): void
    {
        $this->pair();

        global $wpdb;
        foreach (['wp_caregrid_state', 'wp_caregrid_credential', 'wp_caregrid_installation_id'] as $option) {
            $autoload = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option));
            $this->assertContains($autoload, ['no', 'off'], "{$option} must not be autoloaded.");
        }
    }

    public function test_deactivation_stops_scheduled_work_but_keeps_the_pairing(): void
    {
        $this->pair();
        $this->assertNotFalse(wp_next_scheduled(Scheduler::POLL));

        deactivate_plugins(self::FILE);

        $this->assertFalse(wp_next_scheduled(Scheduler::POLL));
        $this->assertFalse(wp_next_scheduled(Scheduler::HEARTBEAT));
        $this->assertTrue(Settings::is_connected());
        $this->assertSame(self::CREDENTIAL, (new CredentialStore)->get());
        $this->assertSame([], $this->http->api_requests(), 'Deactivation does not revoke the credential.');
    }

    public function test_reactivation_resumes_a_paired_site(): void
    {
        $this->pair();
        deactivate_plugins(self::FILE);

        activate_plugin(self::FILE);

        $this->assertNotFalse(wp_next_scheduled(Scheduler::POLL));
        $this->assertNotFalse(wp_next_scheduled(Scheduler::HEARTBEAT));
    }

    public function test_uninstall_revokes_remotely_and_removes_every_local_trace(): void
    {
        $this->pair();
        $this->http->on('POST disconnect', ['status' => 200, 'body' => ['disconnected' => true]]);
        deactivate_plugins(self::FILE);

        uninstall_plugin(self::FILE);

        $this->assertCount(1, $this->http->api_requests('POST disconnect'));
        foreach (Settings::OPTIONS as $option) {
            $this->assertFalse(get_option($option), "{$option} should be deleted.");
        }
    }
}
