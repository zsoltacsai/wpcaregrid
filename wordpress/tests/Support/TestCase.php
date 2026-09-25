<?php

namespace WPCareGrid\Connector\Tests\Support;

use WPCareGrid\Connector\Core\Plugin;
use WPCareGrid\Connector\Core\Scheduler;
use WPCareGrid\Connector\Core\Settings;

/**
 * Base for connector tests. Every test starts from an unpaired site with
 * the connector active; HTTP goes to FakeHttp.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    public const CREDENTIAL = 'cgc_Zx8Kq2Lm4Np6Rs8Tu0Vw2Xy4Za6Bc8De0Fg2Hi4Jk6Lm8Np0';

    public const WEBSITE = '9b2f6c1e-4d3a-4f5b-8c7d-1e2f3a4b5c6d';

    public const CONNECTOR = '0a1b2c3d-4e5f-4a6b-9c8d-7e6f5a4b3c2d';

    public const PAIRING_CODE = 'ABCD-EFGH-JK23';

    protected FakeHttp $http;

    protected ?string $redirected_to = null;

    /** @var string[] Paths removed after the test. */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();

        Settings::delete_all();
        Scheduler::unschedule();
        Plugin::instance()->reset();
        foreach (['update_plugins', 'update_themes', 'update_core'] as $transient) {
            delete_site_transient($transient);
        }
        delete_transient('health-check-site-status-result');
        wp_set_current_user(0);
        $_GET = $_POST = $_REQUEST = [];

        $this->http = new FakeHttp;
        $this->http->register();

        add_filter('wp_die_handler', [$this, 'die_handler']);
        add_filter('wp_redirect', [$this, 'capture_redirect']);
    }

    protected function tearDown(): void
    {
        $this->http->unregister();
        remove_filter('wp_die_handler', [$this, 'die_handler']);
        remove_filter('wp_redirect', [$this, 'capture_redirect']);
        remove_all_filters('wp_caregrid_license_providers');

        foreach ($this->cleanup as $path) {
            // A fixture plugin left active would make every later health check critical.
            if (str_starts_with($path, WP_PLUGIN_DIR.'/')) {
                deactivate_plugins(basename($path).'/'.basename($path).'.php', true);
            }
            self::remove($path);
        }
        wp_clean_plugins_cache(false);
        wp_clean_themes_cache(false);

        parent::tearDown();
    }

    public function die_handler(): callable
    {
        return static function ($message, $title = '', $args = []) {
            $status = is_array($args) ? (int) ($args['response'] ?? 500) : 500;
            $text = $message instanceof \WP_Error ? $message->get_error_message() : (string) $message;

            throw new WPDieException($text, $status);
        };
    }

    public function capture_redirect(string $location): bool
    {
        $this->redirected_to = $location;

        return false; // No header() call in the test process.
    }

    protected function plugin(): Plugin
    {
        return Plugin::instance();
    }

    /** A successful pairing response from WP CareGrid. */
    protected function pair_response(array $overrides = []): array
    {
        return [
            'status' => 201,
            'body' => array_merge(
                [
                    'website_uuid' => self::WEBSITE,
                    'connector_uuid' => self::CONNECTOR,
                    'credential' => self::CREDENTIAL,
                    'capabilities' => ['inventory', 'health', 'updates', 'license_check'],
                    'heartbeat_interval' => 900,
                    'poll_interval' => 300,
                    'latest_connector_version' => '0.1.0',
                ],
                $overrides
            ),
        ];
    }

    /** Pair through the real pairing service. */
    protected function pair(): void
    {
        $this->http->on('POST pair', $this->pair_response());
        $result = $this->plugin()->pairing()->pair(self::PAIRING_CODE);
        $this->assertTrue($result['ok'], 'Pairing failed: '.$result['message']);
        $this->http->requests = [];
    }

    protected function job(string $type, array $payload = [], ?string $uuid = null): array
    {
        return ['uuid' => $uuid ?? wp_generate_uuid4(), 'type' => $type, 'payload' => (object) $payload, 'created_at' => gmdate('c'), 'attempt' => 1];
    }

    /** Make GET jobs return these jobs once, then nothing. */
    protected function serve_jobs(array ...$jobs): void
    {
        $this->http->on('GET jobs', ['status' => 200, 'body' => ['jobs' => $jobs, 'poll_interval' => 300]], ['status' => 200, 'body' => ['jobs' => []]]);
        $this->http->on('POST jobs/*/result', ['status' => 202, 'body' => ['accepted' => true]]);
    }

    /** The result body posted for a job. */
    protected function posted_result(string $uuid): ?array
    {
        foreach ($this->http->api_requests('POST jobs/*/result') as $request) {
            if ($request['path'] === "jobs/{$uuid}/result") {
                return $request['body'];
            }
        }

        return null;
    }

    protected function admin(): \WP_User
    {
        return $this->user('administrator');
    }

    protected function user(string $role): \WP_User
    {
        $id = wp_insert_user(['user_login' => $role.'_'.wp_generate_password(6, false), 'user_pass' => wp_generate_password(24), 'user_email' => wp_generate_password(8, false).'@site.example', 'role' => $role]);
        $this->assertIsInt($id);
        wp_set_current_user($id);

        return wp_get_current_user();
    }

    /* ----- Plugin and theme fixtures for update tests ----- */

    protected function install_plugin(string $slug, string $version, string $headers = ''): string
    {
        $dir = WP_PLUGIN_DIR.'/'.$slug;
        $this->cleanup[] = $dir;
        self::write_plugin($dir, $slug, $version, $headers);
        wp_clean_plugins_cache(false);

        return $slug.'/'.$slug.'.php';
    }

    /** A zip of the plugin at a new version. WordPress deletes the package after installing it. */
    protected function plugin_package(string $slug, string $version, string $headers = ''): string
    {
        $build = sys_get_temp_dir().'/wp-caregrid-pkg-'.wp_generate_password(8, false);
        self::write_plugin($build.'/'.$slug, $slug, $version, $headers);

        return $this->zip($build, $slug);
    }

    protected function offer_plugin_update(string $file, string $version, string $package, string $source = 'vendor.example'): void
    {
        $slug = dirname($file);
        $updates = new \stdClass;
        $updates->last_checked = time();
        $updates->checked = [$file => get_plugins()[$file]['Version'] ?? '0'];
        $updates->response = [$file => (object) ['id' => $source.'/'.$slug, 'slug' => $slug, 'plugin' => $file, 'new_version' => $version, 'url' => 'https://'.$source.'/'.$slug, 'package' => $package]];
        $updates->no_update = [];
        $updates->translations = [];
        set_site_transient('update_plugins', $updates);
    }

    protected function install_theme(string $stylesheet, string $version): void
    {
        $dir = get_theme_root().'/'.$stylesheet;
        $this->cleanup[] = $dir;
        self::write_theme($dir, $stylesheet, $version);
        wp_clean_themes_cache(false);
    }

    protected function theme_package(string $stylesheet, string $version): string
    {
        $build = sys_get_temp_dir().'/wp-caregrid-pkg-'.wp_generate_password(8, false);
        self::write_theme($build.'/'.$stylesheet, $stylesheet, $version);

        return $this->zip($build, $stylesheet);
    }

    protected function offer_theme_update(string $stylesheet, string $version, string $package): void
    {
        $updates = new \stdClass;
        $updates->last_checked = time();
        $updates->checked = [$stylesheet => wp_get_theme($stylesheet)->get('Version')];
        $updates->response = [$stylesheet => ['theme' => $stylesheet, 'new_version' => $version, 'url' => 'https://themes.example/'.$stylesheet, 'package' => $package]];
        $updates->no_update = [];
        $updates->translations = [];
        set_site_transient('update_themes', $updates);
    }

    protected function offer_core_update(string $version): void
    {
        global $wp_version;

        $offer = (object) ['response' => 'upgrade', 'download' => 'https://downloads.example/wordpress-'.$version.'.zip', 'locale' => 'en_US', 'packages' => (object) ['full' => 'https://downloads.example/wordpress-'.$version.'.zip', 'no_content' => '', 'new_bundled' => '', 'partial' => '', 'rollback' => ''], 'current' => $version, 'version' => $version, 'php_version' => '7.4', 'mysql_version' => '5.5.5', 'new_bundled' => '7.0', 'partial_version' => ''];

        set_site_transient('update_core', (object) ['updates' => [$offer], 'last_checked' => time(), 'version_checked' => $wp_version, 'translations' => []]);
    }

    private function zip(string $build, string $folder): string
    {
        $zip_path = $build.'.zip';
        $zip = new \ZipArchive;
        $zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($build.'/'.$folder, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $zip->addFile($file->getPathname(), $folder.'/'.substr($file->getPathname(), strlen($build.'/'.$folder) + 1));
        }
        $zip->close();
        self::remove($build);
        $this->cleanup[] = $zip_path;

        return $zip_path;
    }

    private static function write_plugin(string $dir, string $slug, string $version, string $headers): void
    {
        wp_mkdir_p($dir);
        file_put_contents($dir.'/'.$slug.'.php', "<?php\n/**\n * Plugin Name: Sample {$slug}\n * Version: {$version}\n{$headers} */\n");
    }

    private static function write_theme(string $dir, string $stylesheet, string $version): void
    {
        wp_mkdir_p($dir);
        file_put_contents($dir.'/style.css', "/*\nTheme Name: Sample {$stylesheet}\nVersion: {$version}\n*/\n");
        file_put_contents($dir.'/index.php', "<?php\n");
    }

    protected static function remove(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (scandir($path) as $item) {
                if ($item !== '.' && $item !== '..') {
                    self::remove($path.'/'.$item);
                }
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }
}
