<?php

namespace WPCareGrid\Connector\Tests\Integration;

use WPCareGrid\Connector\Jobs\Handlers\UpdateCoreHandler;
use WPCareGrid\Connector\Jobs\JobRegistry;
use WPCareGrid\Connector\Jobs\JobRunner;
use WPCareGrid\Connector\Tests\Support\TestCase;
use WPCareGrid\Connector\Updates\UpdateGuard;

/**
 * Plugin and theme updates run WordPress's real upgraders against local
 * packages. Core updates use a fake upgrade step (replacing WordPress
 * itself is out of reach for a test), but the real policy and checks.
 */
final class UpdatesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pair();

        // WordPress re-checks for core updates after every upgrade; that check
        // runs a MySQL-only query the SQLite test database cannot answer.
        remove_action('upgrader_process_complete', 'wp_version_check');
    }

    protected function tearDown(): void
    {
        add_action('upgrader_process_complete', 'wp_version_check', 10, 0);

        parent::tearDown();
    }

    public function test_plugin_update_installs_the_offered_version_and_keeps_it_active(): void
    {
        $file = $this->install_plugin('caregrid-sample', '1.0.0');
        activate_plugin($file);
        $this->offer_plugin_update($file, '1.1.0', $this->plugin_package('caregrid-sample', '1.1.0'));

        $result = $this->run_job('UPDATE_PLUGIN', ['plugin' => $file, 'target_version' => '1.1.0']);

        $this->assertSame('success', $result['status'], wp_json_encode($result['error']));
        $this->assertSame('PLUGIN_UPDATED', $result['result_code']);
        $this->assertSame('1.0.0', $result['data']['previous_version']);
        $this->assertSame('1.1.0', $result['data']['new_version']);
        $this->assertTrue($result['data']['active']);
        $this->assertSame('not_available', $result['data']['backup']['status'], 'No backup is claimed.');
        $this->assertContains($result['data']['health']['status'], ['healthy', 'attention']);
        $this->assertSame('1.1.0', array_column($result['data']['inventory']['plugins'], 'version', 'file')[$file]);

        wp_clean_plugins_cache(false);
        $this->assertSame('1.1.0', get_plugins()[$file]['Version']);
        $this->assertTrue(is_plugin_active($file));
        deactivate_plugins($file);
    }

    public function test_plugin_that_is_not_installed(): void
    {
        $result = $this->run_job('UPDATE_PLUGIN', ['plugin' => 'missing/missing.php']);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('PLUGIN_NOT_INSTALLED', $result['result_code']);
    }

    public function test_plugin_without_an_update_is_skipped(): void
    {
        $file = $this->install_plugin('caregrid-sample', '1.0.0');
        set_site_transient('update_plugins', (object) ['last_checked' => time(), 'checked' => [$file => '1.0.0'], 'response' => [], 'no_update' => [], 'translations' => []]);

        $result = $this->run_job('UPDATE_PLUGIN', ['plugin' => $file]);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('NO_UPDATE_AVAILABLE', $result['result_code']);
    }

    public function test_plugin_update_to_a_version_that_is_not_offered_is_skipped(): void
    {
        $file = $this->install_plugin('caregrid-sample', '1.0.0');
        $this->offer_plugin_update($file, '1.2.0', $this->plugin_package('caregrid-sample', '1.2.0'));

        $result = $this->run_job('UPDATE_PLUGIN', ['plugin' => $file, 'target_version' => '1.1.0']);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('TARGET_VERSION_UNAVAILABLE', $result['result_code']);
        $this->assertSame('1.0.0', get_plugins()[$file]['Version']);
    }

    public function test_failed_plugin_update_is_reported_as_failed(): void
    {
        $file = $this->install_plugin('caregrid-sample', '1.0.0');
        // The download fails (no network), so nothing is installed.
        $this->offer_plugin_update($file, '1.1.0', 'https://vendor.example/caregrid-sample-1.1.0.zip');

        $result = $this->run_job('UPDATE_PLUGIN', ['plugin' => $file]);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('PLUGIN_UPDATE_FAILED', $result['result_code']);
        $this->assertSame('1.0.0', $result['error']['details']['new_version']);
        wp_clean_plugins_cache(false);
        $this->assertSame('1.0.0', get_plugins()[$file]['Version']);
    }

    public function test_package_that_does_not_raise_the_version_is_not_reported_as_success(): void
    {
        $file = $this->install_plugin('caregrid-sample', '1.0.0');
        // WordPress offers 1.1.0 but the package still contains 1.0.0.
        $this->offer_plugin_update($file, '1.1.0', $this->plugin_package('caregrid-sample', '1.0.0'));

        $result = $this->run_job('UPDATE_PLUGIN', ['plugin' => $file]);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('PLUGIN_UPDATE_FAILED', $result['result_code']);
    }

    public function test_theme_update_installs_the_offered_version(): void
    {
        $this->install_theme('caregrid-theme', '1.0');
        $this->offer_theme_update('caregrid-theme', '1.1', $this->theme_package('caregrid-theme', '1.1'));

        $result = $this->run_job('UPDATE_THEME', ['stylesheet' => 'caregrid-theme', 'target_version' => '1.1']);

        $this->assertSame('success', $result['status'], wp_json_encode($result['error']));
        $this->assertSame('THEME_UPDATED', $result['result_code']);
        $this->assertSame('1.0', $result['data']['previous_version']);
        $this->assertSame('1.1', $result['data']['new_version']);
        wp_clean_themes_cache(false);
        $this->assertSame('1.1', wp_get_theme('caregrid-theme')->get('Version'));
    }

    public function test_theme_that_is_not_installed(): void
    {
        $result = $this->run_job('UPDATE_THEME', ['stylesheet' => 'missing-theme']);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('THEME_NOT_INSTALLED', $result['result_code']);
    }

    public function test_core_minor_update_within_the_policy(): void
    {
        $installed = '7.1.2';
        $this->offer_core_update('7.1.3');
        $this->use_core_handler(function (object $offer) use (&$installed) {
            $installed = $offer->current;

            return $offer->current;
        }, function () use (&$installed) {
            return $installed;
        });

        $result = $this->run_job('UPDATE_CORE', ['policy' => 'minor', 'target_version' => '7.1.3']);

        $this->assertSame('success', $result['status'], wp_json_encode($result['error']));
        $this->assertSame('CORE_UPDATED', $result['result_code']);
        $this->assertSame('7.1.2', $result['data']['previous_version']);
        $this->assertSame('7.1.3', $result['data']['new_version']);
        $this->assertSame('7.1.3', $result['data']['inventory']['core']['version']);
        $this->assertSame('not_available', $result['data']['backup']['status']);
    }

    public function test_core_major_update_is_refused_under_the_minor_policy(): void
    {
        $ran = false;
        $this->offer_core_update('7.2.0');
        $this->use_core_handler(function () use (&$ran) {
            $ran = true;

            return true;
        }, fn () => '7.1.2');

        $result = $this->run_job('UPDATE_CORE', ['policy' => 'minor']);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('MAJOR_UPDATE_NOT_ALLOWED', $result['result_code']);
        $this->assertFalse($ran);
    }

    public function test_core_update_that_does_not_change_the_version_is_a_failure(): void
    {
        $this->offer_core_update('7.1.3');
        $this->use_core_handler(fn () => '7.1.3', fn () => '7.1.2'); // Claims success, nothing changed.

        $result = $this->run_job('UPDATE_CORE', ['policy' => 'minor']);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('CORE_UPDATE_FAILED', $result['result_code']);
        $this->assertSame('version_not_changed', $result['error']['details']['error']);
    }

    public function test_core_upgrader_error_is_a_failure(): void
    {
        $this->offer_core_update('7.1.3');
        $this->use_core_handler(fn () => new \WP_Error('download_failed', 'Download failed.'), fn () => '7.1.2');

        $result = $this->run_job('UPDATE_CORE', ['policy' => 'minor']);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('download_failed', $result['error']['details']['error']);
    }

    public function test_core_without_an_update_is_skipped(): void
    {
        global $wp_version;
        set_site_transient('update_core', (object) ['updates' => [(object) ['response' => 'latest', 'current' => $wp_version, 'locale' => 'en_US']], 'last_checked' => time(), 'version_checked' => $wp_version, 'translations' => []]);

        $result = $this->run_job('UPDATE_CORE', ['policy' => 'minor']);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('NO_UPDATE_AVAILABLE', $result['result_code']);
    }

    public function test_core_branch(): void
    {
        $this->assertSame('7.1', UpdateCoreHandler::branch('7.1.2'));
        $this->assertSame('7.1', UpdateCoreHandler::branch('7.1'));
        $this->assertSame('7.2', UpdateCoreHandler::branch('7.2.0'));
    }

    /** Run one job through polling and return the posted result. */
    private function run_job(string $type, array $payload): array
    {
        $job = $this->job($type, $payload);
        $this->serve_jobs($job);

        $this->plugin()->runner()->poll();

        $result = $this->posted_result($job['uuid']);
        $this->assertNotNull($result, 'No result was posted.');

        return $result;
    }

    private function use_core_handler(callable $runner, callable $version): void
    {
        $registry = $this->plugin()->registry();
        $handlers = [];
        foreach (JobRegistry::TYPES as $type) {
            $handlers[$type] = $registry->handler($type);
        }
        $handlers['UPDATE_CORE'] = new UpdateCoreHandler(new UpdateGuard($this->plugin()->health(), $this->plugin()->inventory()), $runner, $version);

        $this->plugin()->set('registry', new JobRegistry($handlers));
        $this->plugin()->set('runner', new JobRunner($this->plugin()->api(), $this->plugin()->registry(), $this->plugin()->jobs(), $this->plugin()->connection()));
    }
}
