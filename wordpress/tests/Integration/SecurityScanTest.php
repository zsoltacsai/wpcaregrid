<?php

namespace WPCareGrid\Connector\Tests\Integration;

use WPCareGrid\Connector\Jobs\Handlers\SecurityScanHandler;
use WPCareGrid\Connector\Jobs\InvalidPayload;
use WPCareGrid\Connector\Jobs\JobRegistry;
use WPCareGrid\Connector\Jobs\JobResult;
use WPCareGrid\Connector\Security\Malware\RuleSet;
use WPCareGrid\Connector\Security\Scan\ScanState;
use WPCareGrid\Connector\Tests\Support\FakeWordPressOrg;
use WPCareGrid\Connector\Tests\Support\TestCase;

/**
 * SECURITY_SCAN inside a real WordPress: integrity against (fake)
 * WordPress.org checksums, malware indicators, database, administrators,
 * cron, configuration, resumable parts, and what leaves the site.
 */
final class SecurityScanTest extends TestCase
{
    private FakeWordPressOrg $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = new FakeWordPressOrg;
        $this->org->register();
        self::flush_checksum_cache();
    }

    protected function tearDown(): void
    {
        $this->org->unregister();
        remove_all_filters('wp_caregrid_security_check_limit');
        parent::tearDown();
    }

    /* ----- Integrity ----- */

    public function test_a_clean_site_verifies_against_the_official_checksums(): void
    {
        $security = $this->scan('full');

        $this->assertTrue($security['complete']);
        $this->assertSame('verified', $security['coverage']['integrity']['core']);
        $this->assertSame('completed', $security['coverage']['integrity']['status']);
        $this->assertGreaterThan(1000, $security['coverage']['integrity']['verified']);
        $this->assertSame(0, $security['coverage']['integrity']['modified']);
        $this->assertSame([], $this->of_type($security, 'integrity'));
        $this->assertSame([], $this->of_type($security, 'suspicious_file'), 'A clean WordPress with its default theme has no suspicious files.');
        $this->assertSame(RuleSet::VERSION, $security['rules_version']);
        $this->assertSame(WP_CAREGRID_VERSION, $security['scanner_version']);
    }

    public function test_a_modified_core_file_is_an_integrity_finding_not_malware(): void
    {
        $this->org->core_overrides['wp-includes/version.php'] = md5('not the installed file');

        $security = $this->scan('full');

        $finding = $this->finding($security, 'CORE_FILE_MODIFIED');
        $this->assertSame('integrity', $finding['type']);
        $this->assertSame('wp-includes/version.php', $finding['path']);
        $this->assertSame('high', $finding['severity']);
        $this->assertSame('high', $finding['confidence']);
        $this->assertSame('core', $finding['component']);
        $this->assertSame(hash_file('sha256', ABSPATH.'wp-includes/version.php'), $finding['evidence']['sha256']);
        $this->assertSame('modified', $security['coverage']['integrity']['core']);
        // The file's content is ordinary WordPress code: no malware indicator.
        $this->assertNull($this->finding($security, null, 'wp-includes/version.php', 'suspicious_file'));
    }

    public function test_missing_and_extra_core_files(): void
    {
        $this->org->core_overrides['wp-includes/removed-by-someone.php'] = md5('x');
        $this->org->core_overrides['wp-includes/class-wp.php'] = null; // not in the official list → extra

        $security = $this->scan('integrity');

        $this->assertSame('wp-includes/removed-by-someone.php', $this->finding($security, 'CORE_FILE_MISSING')['path']);
        $extra = $this->finding($security, 'CORE_FILE_EXTRA');
        $this->assertSame('wp-includes/class-wp.php', $extra['path']);
        $this->assertSame('medium', $extra['confidence']);
        $this->assertSame(1, $security['coverage']['integrity']['missing']);
        $this->assertSame(1, $security['coverage']['integrity']['extra']);
    }

    public function test_without_a_reference_integrity_is_unavailable_never_guessed(): void
    {
        $this->org->core_available = false;

        $security = $this->scan('integrity');

        $this->assertSame('unavailable', $security['coverage']['integrity']['core']);
        $this->assertSame('unavailable', $security['coverage']['integrity']['status']);
        $this->assertSame(0, $security['coverage']['integrity']['verified']);
        $this->assertSame([], $security['findings']);
    }

    public function test_plugin_integrity_with_and_without_an_official_reference(): void
    {
        $files = ['acme-forms.php' => "<?php\n/**\n * Plugin Name: Acme Forms\n * Version: 1.0\n */\n", 'includes/form.php' => "<?php\nfunction acme_form() { return 'form'; }\n"];
        $this->fixture_plugin('acme-forms', $files);
        $this->org->plugin('acme-forms', '1.0', $files);
        $this->fixture_plugin('premium-thing', ['premium-thing.php' => "<?php\n/**\n * Plugin Name: Premium Thing\n * Version: 2.0\n */\n"]);

        $clean = $this->scan('integrity');
        $this->assertSame([], $clean['findings']);
        $this->assertGreaterThanOrEqual(1, $clean['coverage']['integrity']['unavailable'], 'A plugin WordPress.org does not know is "unavailable", not "verified".');

        self::flush_checksum_cache();
        file_put_contents(WP_PLUGIN_DIR.'/acme-forms/includes/form.php', "<?php\nfunction acme_form() { return 'changed'; }\n");
        file_put_contents(WP_PLUGIN_DIR.'/acme-forms/includes/extra.php', "<?php\n// not shipped\n");

        $changed = $this->scan('integrity');
        $modified = $this->finding($changed, 'PLUGIN_FILE_MODIFIED');
        $this->assertSame('wp-content/plugins/acme-forms/includes/form.php', $modified['path']);
        $this->assertSame('plugin:acme-forms', $modified['component']);
        $this->assertSame('wp-content/plugins/acme-forms/includes/extra.php', $this->finding($changed, 'PLUGIN_FILE_EXTRA')['path']);
        $this->assertNull($this->finding($changed, null, 'wp-content/plugins/premium-thing/premium-thing.php'));
    }

    /* ----- Malware indicators ----- */

    public function test_suspicious_php_is_reported_as_suspicious_with_confidence_and_metadata_only(): void
    {
        $payload = 'ZWNobyAiaGFja2VkIjs='.str_repeat('QUJD', 10);
        $this->fixture_plugin('shady', [
            'shady.php' => "<?php\n/**\n * Plugin Name: Shady\n * Version: 1.0\n */\n",
            // Harmless samples (the payload decodes to an echo), one folder down so
            // WordPress does not read them for plugin headers.
            'inc/loader.php' => "<?php\n\$x = 1;\neval(base64_decode('{$payload}'));\n",
            'inc/shell.php' => "<?php\nif (isset(\$_REQUEST['q'])) { assert(\$_REQUEST['q']); }\neval(gzinflate(base64_decode('{$payload}')));\n",
        ]);

        $security = $this->scan('malware');
        $this->samples_present(WP_PLUGIN_DIR.'/shady/inc/loader.php', WP_PLUGIN_DIR.'/shady/inc/shell.php');

        $loader = $this->finding($security, null, 'wp-content/plugins/shady/inc/loader.php');
        $this->assertSame('suspicious_file', $loader['type']);
        $this->assertSame('PHP_EVAL_DECODED', $loader['code']);
        $this->assertSame('high', $loader['severity']);
        $this->assertSame('medium', $loader['confidence'], 'One indicator: medium, never confirmed.');
        $this->assertSame([3], $loader['evidence']['lines']);
        $this->assertSame(hash_file('sha256', WP_PLUGIN_DIR.'/shady/inc/loader.php'), $loader['evidence']['sha256']);
        $this->assertSame('plugin:shady', $loader['component']);

        $shell = $this->finding($security, null, 'wp-content/plugins/shady/inc/shell.php');
        $this->assertSame('PHP_REQUEST_EXECUTION', $shell['code']);
        $this->assertSame('critical', $shell['severity']);
        $this->assertSame('high', $shell['confidence']);
        $this->assertContains('PHP_EVAL_DECODED', $shell['evidence']['rules']);

        foreach ($security['findings'] as $finding) {
            $this->assertNotSame('confirmed', $finding['confidence']);
            $this->assertNotSame('malware_confirmed', $finding['type']);
        }
        // No file content leaves the site.
        $json = wp_json_encode($security);
        $this->assertStringNotContainsString($payload, $json);
        $this->assertStringNotContainsString("assert(\$_REQUEST['q'])", $json);
    }

    public function test_a_single_weak_indicator_is_low_confidence(): void
    {
        $this->fixture_plugin('packed', ['packed.php' => "<?php\n/**\n * Plugin Name: Packed\n */\n\$data = '".str_repeat('QUJDRA', 600)."';\n"]);

        $finding = $this->finding($this->scan('malware'), null, 'wp-content/plugins/packed/packed.php');

        $this->assertSame('PHP_LONG_ENCODED_STRING', $finding['code']);
        $this->assertSame('low', $finding['confidence']);
    }

    public function test_an_integrity_change_raises_the_confidence_of_an_indicator(): void
    {
        $files = ['lib.php' => "<?php\n/**\n * Plugin Name: Lib\n * Version: 3.1\n */\nfunction lib() {}\n"];
        $this->fixture_plugin('lib', $files);
        $this->org->plugin('lib', '3.1', $files);
        file_put_contents(WP_PLUGIN_DIR.'/lib/lib.php', $files['lib.php']."eval(str_rot13('rpub 1;'));\n");

        $security = $this->scan('full');
        $this->samples_present(WP_PLUGIN_DIR.'/lib/lib.php');
        $finding = $this->finding($security, null, 'wp-content/plugins/lib/lib.php', 'suspicious_file');

        $this->assertSame('PHP_EVAL_DECODED', $finding['code']);
        $this->assertSame('modified', $finding['evidence']['integrity']);
        $this->assertSame('high', $finding['confidence'], 'medium + modified official file → high');
    }

    public function test_uploads_directory_checks(): void
    {
        $uploads = WP_CONTENT_DIR.'/uploads/2026/10';
        wp_mkdir_p($uploads);
        $this->cleanup_later(WP_CONTENT_DIR.'/uploads/2026');
        file_put_contents($uploads.'/cache.php', "<?php echo 'hi';\n");
        file_put_contents($uploads.'/index.php', "<?php\n// Silence is golden.\n");
        file_put_contents($uploads.'/invoice.php.jpg', 'JPEG');
        file_put_contents($uploads.'/photo.jpg', "\xFF\xD8\xFF\xE0<?php echo 1; ?>");
        file_put_contents($uploads.'/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        file_put_contents($uploads.'/.htaccess', "AddHandler application/x-httpd-php .jpg\n");
        file_put_contents($uploads.'/real.jpg', "\xFF\xD8\xFF\xE0JFIF");

        $security = $this->scan('malware');
        $this->samples_present($uploads.'/cache.php', $uploads.'/photo.jpg', $uploads.'/logo.svg', $uploads.'/.htaccess', $uploads.'/invoice.php.jpg');
        $at = fn (string $name) => $this->finding($security, null, 'wp-content/uploads/2026/10/'.$name);

        $this->assertSame('UPLOADS_EXECUTABLE_FILE', $at('cache.php')['code']);
        $this->assertSame('high', $at('cache.php')['severity']);
        $this->assertNull($at('index.php'), '"Silence is golden" index files are normal.');
        $this->assertSame('UPLOADS_DOUBLE_EXTENSION', $at('invoice.php.jpg')['code']);
        $this->assertSame('IMAGE_CONTAINS_PHP', $at('photo.jpg')['code']);
        $this->assertSame('SVG_SCRIPT', $at('logo.svg')['code']);
        $this->assertContains('HTACCESS_PHP_HANDLER', $at('.htaccess')['evidence']['rules']);
        $this->assertNull($at('real.jpg'));
        $this->assertSame('uploads', $at('cache.php')['component']);
    }

    public function test_legitimate_code_with_suspicious_looking_constructs_is_not_flagged(): void
    {
        $this->fixture_plugin('legit-framework', [
            'legit-framework.php' => "<?php\n/**\n * Plugin Name: Legit Framework\n */\nadd_action('init', 'legit_init');\n",
            'config.php' => "<?php\n\$settings = json_decode(base64_decode(get_option('legit_settings', 'e30=')), true);\n\$token = base64_encode(random_bytes(16));\n",
            'templates.php' => "<?php\nfunction legit_render(\$compiled, \$vars) { extract(\$vars); eval('?>' . \$compiled); }\n",
            'cli.php' => "<?php\nfunction legit_convert(\$file) { exec('convert ' . escapeshellarg(\$file) . ' out.png', \$out); return \$out; }\n",
            'http.php' => "<?php\n\$response = wp_remote_get('https://api.example.com/v1/items');\n\$items = json_decode(wp_remote_retrieve_body(\$response), true);\n",
            'regex.php' => "<?php\n\$slug = preg_replace_callback('/[^a-z]/', fn (\$m) => '-', 'Hello World');\n\$clean = preg_replace('/\\s+/', ' ', \$slug);\n",
            'cron.php' => "<?php\nadd_action('legit_daily_cleanup', 'legit_cleanup');\nfunction legit_cleanup() { delete_expired_transients(); }\n",
            'assets/app.min.js' => 'function(){var a="\\x41\\x42";eval("1")}',
            'json.php' => "<?php\n\$export = wp_json_encode(['items' => [1, 2], 'html' => '<b>x</b>']);\n\$import = json_decode(base64_decode(defined('LEGIT_CONFIG') ? LEGIT_CONFIG : 'eyJhIjoxfQ=='), true);\n\$key = base64_encode(hash_hmac('sha256', 'a', 'b', true));\n",
            'compat.php' => "<?php\nif (! function_exists('legit_polyfill')) { eval('function legit_polyfill() { return 1; }'); }\n",
            'vendor/composer/autoload_real.php' => "<?php\nspl_autoload_register(function (\$class) { require __DIR__ . '/../' . \$class . '.php'; });\n",
        ]);

        $security = $this->scan('malware');

        $this->assertSame([], array_values(array_filter($security['findings'], fn ($f) => str_contains((string) ($f['path'] ?? ''), 'legit-framework'))));
    }

    public function test_real_wordpress_core_is_not_flagged_even_without_checksums(): void
    {
        // Without a reference nothing is skipped: every core file is read by the rules.
        $this->org->core_available = false;

        $security = $this->scan('malware');

        $core = array_filter($this->of_type($security, 'suspicious_file'), fn ($f) => ($f['component'] ?? null) === 'core');
        $this->assertSame([], array_values(array_filter($core, fn ($f) => $f['confidence'] !== 'low')), 'No medium or high confidence finding in unmodified WordPress '.get_bloginfo('version').'.');
        $this->assertGreaterThan(1000, $security['coverage']['malware']['files_scanned']);
    }

    public function test_the_connector_does_not_flag_its_own_rules(): void
    {
        $security = $this->scan('malware');

        $this->assertSame([], array_values(array_filter($security['findings'], fn ($f) => str_contains((string) ($f['path'] ?? ''), 'wp-caregrid-connector'))));
    }

    public function test_symlinks_are_never_followed(): void
    {
        $outside = sys_get_temp_dir().'/wp-caregrid-outside-'.wp_generate_password(6, false);
        wp_mkdir_p($outside);
        $this->cleanup_later($outside);
        file_put_contents($outside.'/evil.php', "<?php system(\$_GET['c']);\n");
        $link = WP_CONTENT_DIR.'/uploads/linked';
        if (! @symlink($outside, $link)) {
            $this->markTestSkipped('This system cannot create symlinks.');
        }
        $this->cleanup_later($link);

        $security = $this->scan('malware');

        $this->assertNull($this->finding($security, null, 'wp-content/uploads/linked/evil.php'));
        $this->assertGreaterThanOrEqual(1, $security['coverage']['malware']['skipped']);
    }

    /* ----- Database, administrators, cron, configuration ----- */

    public function test_database_scan_reports_names_never_values(): void
    {
        $secret = 'MALICIOUS-PAYLOAD-'.wp_generate_password(8, false);
        update_option('acme_footer', '<script>eval(atob("'.$secret.'"))</script>');
        update_option('acme_settings', base64_encode('{"a":1}'));
        update_option('widget_custom_html', ['<script src="https://cdn.example.com/x.js"></script>']);
        // As an attacker would store it: straight past kses.
        kses_remove_filters();
        $post = wp_insert_post(['post_title' => 'Hello', 'post_status' => 'publish', 'post_content' => 'Text <script>document.write(String.fromCharCode(60,115))</script>']);
        kses_init_filters();

        $security = $this->scan('malware');

        $this->assertSame('option:acme_footer', $this->finding($security, 'DB_OPTION_SCRIPT_EVAL')['component']);
        $this->assertNull($this->finding($security, null, null, 'suspicious_database', 'option:acme_settings'), 'Base64 in an option is normal.');
        $this->assertSame([$post], $this->finding($security, 'DB_POST_OBFUSCATED_SCRIPT')['evidence']['post_ids']);
        $this->assertStringNotContainsString($secret, wp_json_encode($security));
        $this->assertStringNotContainsString('fromCharCode(60', wp_json_encode($security));

        delete_option('acme_footer');
        delete_option('acme_settings');
        delete_option('widget_custom_html');
        wp_delete_post($post, true);
    }

    public function test_administrators_are_reported_without_secrets(): void
    {
        $admin = $this->admin();
        $editor = $this->user('editor');
        $editor->add_cap('manage_options');

        $security = $this->scan('malware');

        $listed = array_values(array_filter($security['admins'], fn ($a) => $a['id'] === $admin->ID))[0];
        $this->assertSame(['id', 'login', 'registered', 'roles', 'managed'], array_keys($listed));
        $this->assertSame(['administrator'], $listed['roles']);
        $this->assertFalse($listed['managed']);

        $hidden = $this->finding($security, 'ADMIN_CAPABILITY_WITHOUT_ROLE');
        $this->assertSame('user:'.$editor->ID, $hidden['component']);

        $json = wp_json_encode($security);
        $this->assertStringNotContainsString($admin->user_email, $json);
        $this->assertStringNotContainsString($admin->user_pass, $json);
        $this->assertStringNotContainsString($editor->user_pass, $json);
    }

    public function test_cron_events_are_inspected_but_never_run(): void
    {
        $runs = 0;
        add_action('legit_nightly', function () use (&$runs) {
            $runs++;
        });
        add_action('qwertyuiopasdfghjkl1', function () use (&$runs) {
            $runs++;
        });
        wp_schedule_event(time() + 3600, 'daily', 'legit_nightly');
        wp_schedule_single_event(time() + 3600, 'zxcvbnmasdfghjklqw12');
        wp_schedule_single_event(time() + 7200, 'acme_task', ['eval(base64_decode("x"))']);

        $security = $this->scan('malware');
        $cron = $this->of_type($security, 'suspicious_cron');

        $this->assertSame(0, $runs);
        $this->assertNotNull($this->finding($security, null, null, 'suspicious_cron', 'cron:zxcvbnmasdfghjklqw12'));
        $withCode = $this->finding($security, null, null, 'suspicious_cron', 'cron:acme_task');
        $this->assertContains('code_in_arguments', $withCode['evidence']['indicators']);
        $this->assertSame('medium', $withCode['confidence']);
        $this->assertNull($this->finding($security, null, null, 'suspicious_cron', 'cron:legit_nightly'), 'A normal custom hook is not suspicious.');
        $this->assertNull($this->finding($security, null, null, 'suspicious_cron', 'cron:wp_version_check'));
        $this->assertLessThanOrEqual(2, count($cron));

        wp_clear_scheduled_hook('legit_nightly');
        wp_clear_scheduled_hook('zxcvbnmasdfghjklqw12');
        wp_clear_scheduled_hook('acme_task', ['eval(base64_decode("x"))']);
    }

    public function test_configuration_findings(): void
    {
        $security = $this->scan('malware');

        // The test site does not define DISALLOW_FILE_EDIT.
        $this->assertSame('low', $this->finding($security, 'CONFIG_FILE_EDITOR_ENABLED')['severity']);
        // WP_DEBUG is on but WP_DEBUG_DISPLAY is off: nothing shown to visitors.
        $this->assertNull($this->finding($security, 'CONFIG_DEBUG_DISPLAY'));
    }

    /* ----- Parts, budget, safety ----- */

    public function test_a_scan_stops_at_its_budget_and_continues_in_parts_with_the_same_result(): void
    {
        $this->fixture_plugin('shady', ['shady.php' => "<?php\n/**\n * Plugin Name: Shady\n */\neval(base64_decode('ZWNobyAxOw=='));\n"]);
        $single = $this->scan('full');
        $this->samples_present(WP_PLUGIN_DIR.'/shady/shady.php');

        self::flush_checksum_cache();
        add_filter('wp_caregrid_security_check_limit', fn () => 800);
        $uuid = wp_generate_uuid4();
        $findings = [];
        $parts = 0;
        do {
            $parts++;
            $result = $this->run_part(['scan_uuid' => $uuid, 'scan_type' => 'full', 'part' => $parts, 'time_budget' => 600]);
            $security = $result->data['security'];
            array_push($findings, ...$security['findings']);
            $this->assertSame($security['complete'] ? JobResult::SUCCESS : JobResult::PARTIAL, $result->status);
            $this->assertSame($security['complete'] ? 'SCAN_COMPLETED' : 'SCAN_INCOMPLETE', $result->code);
        } while (! $security['complete'] && $parts < 50);

        $this->assertGreaterThan(2, $parts);
        $this->assertTrue($security['complete']);
        $key = fn (array $f) => $f['type'].'|'.$f['code'].'|'.($f['path'] ?? $f['component'] ?? '');
        $this->assertEqualsCanonicalizing(array_map($key, $single['findings']), array_values(array_unique(array_map($key, $findings))));
        $this->assertSame($single['coverage']['malware']['files_scanned'], $security['coverage']['malware']['files_scanned']);
        $this->assertNull(ScanState::load($uuid), 'Progress is removed when the scan is done.');
    }

    public function test_a_later_part_without_saved_progress_fails_cleanly(): void
    {
        $result = $this->run_part(['scan_uuid' => wp_generate_uuid4(), 'scan_type' => 'malware', 'part' => 2, 'time_budget' => 60]);

        $this->assertSame(JobResult::FAILED, $result->status);
        $this->assertSame('SCAN_STATE_LOST', $result->code);
    }

    public function test_a_scan_changes_nothing_and_only_reads_the_database(): void
    {
        $before = $this->snapshot();
        $queries = [];
        $collect = function ($query) use (&$queries) {
            $queries[] = $query;

            return $query;
        };
        add_filter('query', $collect);

        $this->scan('full');

        remove_filter('query', $collect);
        $this->assertSame($before, $this->snapshot(), 'No file on the site changed.');
        $writes = array_filter($queries, fn ($q) => preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER|CREATE|TRUNCATE)\b/i', $q) && ! preg_match('/_transient_|wp_caregrid_/', $q));
        $this->assertSame([], array_values($writes), 'The scan only reads site data.');
    }

    public function test_invalid_payloads_are_refused(): void
    {
        $handler = new SecurityScanHandler;

        foreach ([
            ['scan_uuid' => wp_generate_uuid4(), 'scan_type' => 'full', 'part' => 1, 'time_budget' => 60, 'path' => '/etc'],
            ['scan_uuid' => wp_generate_uuid4(), 'scan_type' => 'everything', 'part' => 1, 'time_budget' => 60],
            ['scan_uuid' => 'not-a-uuid', 'scan_type' => 'full', 'part' => 1, 'time_budget' => 60],
            ['scan_uuid' => wp_generate_uuid4(), 'scan_type' => 'full', 'part' => 1, 'time_budget' => 99999],
            ['scan_uuid' => wp_generate_uuid4(), 'scan_type' => 'full', 'part' => 1, 'time_budget' => 60, 'rules' => '/./'],
        ] as $payload) {
            try {
                $handler->validate($payload);
                $this->fail('Accepted: '.wp_json_encode($payload));
            } catch (InvalidPayload) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_scan_through_the_job_runner_with_no_secrets_in_the_result(): void
    {
        $this->pair();
        $this->assertContains('security_scan', JobRegistry::capabilities());
        $job = $this->job('SECURITY_SCAN', ['scan_uuid' => wp_generate_uuid4(), 'scan_type' => 'full', 'part' => 1, 'time_budget' => 600]);
        $this->serve_jobs($job);

        $this->plugin()->runner()->poll();

        $result = $this->posted_result($job['uuid']);
        $this->assertSame('success', $result['status']);
        $this->assertSame('SCAN_COMPLETED', $result['result_code']);
        $json = wp_json_encode($result);
        foreach ([self::CREDENTIAL, AUTH_KEY, SECURE_AUTH_SALT, NONCE_KEY, (string) DB_PASSWORD ?: 'no-db-password-set'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertStringNotContainsString("define('AUTH_KEY'", $json);
    }

    /* ----- Helpers ----- */

    private function run_part(array $payload): JobResult
    {
        $handler = new SecurityScanHandler;

        return $handler->handle($handler->validate($payload));
    }

    private function scan(string $type): array
    {
        $result = $this->run_part(['scan_uuid' => wp_generate_uuid4(), 'scan_type' => $type, 'part' => 1, 'time_budget' => 600]);
        $this->assertSame(JobResult::SUCCESS, $result->status, 'The scan did not complete in one part: '.$result->code);

        return $result->data['security'];
    }

    private function of_type(array $security, string $type): array
    {
        return array_values(array_filter($security['findings'], fn ($f) => $f['type'] === $type));
    }

    private function finding(array $security, ?string $code, ?string $path = null, ?string $type = null, ?string $component = null): ?array
    {
        foreach ($security['findings'] as $finding) {
            if (($code === null || $finding['code'] === $code) && ($path === null || ($finding['path'] ?? null) === $path)
                && ($type === null || $finding['type'] === $type) && ($component === null || ($finding['component'] ?? null) === $component)) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * Malware test samples are harmless, but a desktop antivirus may still
     * quarantine them from the test directory while the test runs. Then the
     * test cannot say anything: it is skipped, not failed.
     */
    private function samples_present(string ...$paths): void
    {
        clearstatcache();
        foreach ($paths as $path) {
            if (! is_file($path)) {
                $this->markTestSkipped('A local antivirus removed the test sample '.basename($path).'; exclude the test directory (wp-caregrid-tests-*) to run this test.');
            }
        }
    }

    /** @param array<string, string> $files */
    private function fixture_plugin(string $slug, array $files): void
    {
        $dir = WP_PLUGIN_DIR.'/'.$slug;
        $this->cleanup_later($dir);
        foreach ($files as $relative => $content) {
            wp_mkdir_p(dirname($dir.'/'.$relative));
            file_put_contents($dir.'/'.$relative, $content);
        }
        wp_clean_plugins_cache(false);
    }

    /** @return array<string, string> path => size:mtime:md5 for every file in wp-content except the connector's own temp files */
    private function snapshot(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(WP_CONTENT_DIR, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = wp_normalize_path($file->getPathname());
            if ($file->isFile() && ! str_contains($path, '/wp-caregrid-') && ! str_contains($path, '/database/')) {
                $files[$path] = $file->getSize().':'.md5_file($file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }

    private static function flush_checksum_cache(): void
    {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_site\\_transient\\_wp\\_caregrid\\_%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_wp\\_caregrid\\_%'");
        wp_cache_flush();
    }
}
