<?php

namespace WPCareGrid\Connector\Tests\Integration;

use WPCareGrid\Connector\Jobs\Handlers\SecurityDisablePluginHandler;
use WPCareGrid\Connector\Jobs\Handlers\SecurityQuarantineHandler;
use WPCareGrid\Connector\Jobs\Handlers\SecurityRepairFileHandler;
use WPCareGrid\Connector\Jobs\Handlers\SecurityRestoreQuarantineHandler;
use WPCareGrid\Connector\Jobs\InvalidPayload;
use WPCareGrid\Connector\Jobs\JobHandler;
use WPCareGrid\Connector\Jobs\JobResult;
use WPCareGrid\Connector\Security\Quarantine\QuarantineStore;
use WPCareGrid\Connector\Tests\Support\FakeWordPressOrg;
use WPCareGrid\Connector\Tests\Support\TestCase;

/**
 * Quarantine, undo, file repair and plugin deactivation inside a real
 * WordPress: reversible, hash-checked, never outside the site, never twice.
 */
final class SecurityRemediationTest extends TestCase
{
    private FakeWordPressOrg $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = new FakeWordPressOrg;
        $this->org->register();
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_site\\_transient\\_%wp\\_caregrid\\_%'");
        wp_cache_flush();
        $this->cleanup_later(QuarantineStore::path());
    }

    protected function tearDown(): void
    {
        $this->org->unregister();
        parent::tearDown();
    }

    /* ----- Quarantine ----- */

    public function test_quarantine_moves_the_file_and_keeps_everything_needed_to_undo_it(): void
    {
        [$relative, $absolute] = $this->upload('2026/cache.php', "<?php echo 'x';\n");
        touch($absolute, 1767225600);
        $sha = hash_file('sha256', $absolute);

        $result = $this->quarantine($relative, $sha, $remediation = wp_generate_uuid4());

        $this->assertSame(JobResult::SUCCESS, $result->status, (string) ($result->error['message'] ?? ''));
        $this->assertSame('FILE_QUARANTINED', $result->code);
        $this->assertFileDoesNotExist($absolute);
        $record = $result->data['remediation']['quarantine'];
        $this->assertSame(['original_path', 'sha256', 'size', 'modified_at', 'permissions'], array_keys($record));
        $this->assertSame($relative, $record['original_path']);
        $this->assertSame($sha, $record['sha256']);
        $this->assertSame('2026-01-01T00:00:00+00:00', $record['modified_at']);
        $this->assertFalse($result->data['remediation']['after']['exists']);

        // Stored without an executable extension, behind deny rules, with its metadata.
        $dir = QuarantineStore::path();
        $this->assertFileExists($dir.'/'.$remediation.'.quarantined');
        $this->assertSame($sha, hash_file('sha256', $dir.'/'.$remediation.'.quarantined'));
        $this->assertFileExists($dir.'/.htaccess');
        $this->assertFileExists($dir.'/index.php');
        $meta = json_decode((string) file_get_contents($dir.'/'.$remediation.'.json'), true);
        $this->assertSame('quarantined', $meta['status']);
        $this->assertSame($remediation, $meta['remediation_uuid']);
        $this->assertSame([$dir.'/index.php'], glob($dir.'/*.php'), 'Nothing executable besides the silence stub.');

        // Undo: back exactly where it was, with its time.
        $undo = $this->handle(new SecurityRestoreQuarantineHandler, ['remediation_uuid' => wp_generate_uuid4(), 'quarantine_uuid' => wp_generate_uuid4(), 'path' => $relative, 'sha256' => $sha]);
        $this->assertSame(JobResult::SUCCESS, $undo->status);
        $this->assertSame($sha, hash_file('sha256', $absolute));
        clearstatcache();
        $this->assertSame(1767225600, filemtime($absolute));
    }

    public function test_quarantine_refuses_a_file_that_changed_since_the_scan(): void
    {
        [$relative, $absolute] = $this->upload('2026/changed.php', "<?php echo 'x';\n");

        $result = $this->quarantine($relative, hash('sha256', 'what the scan saw'));

        $this->assertSame('FILE_CHANGED', $result->code);
        $this->assertFileExists($absolute);
    }

    public function test_undo_never_overwrites_a_file_that_is_there_now(): void
    {
        [$relative, $absolute] = $this->upload('2026/again.php', "<?php echo 'old';\n");
        $sha = hash_file('sha256', $absolute);
        $this->quarantine($relative, $sha);
        file_put_contents($absolute, "<?php echo 'new';\n");

        $undo = $this->handle(new SecurityRestoreQuarantineHandler, ['remediation_uuid' => wp_generate_uuid4(), 'quarantine_uuid' => wp_generate_uuid4(), 'path' => $relative, 'sha256' => $sha]);

        $this->assertSame('QUARANTINE_TARGET_EXISTS', $undo->code);
        $this->assertSame("<?php echo 'new';\n", file_get_contents($absolute));

        $unknown = $this->handle(new SecurityRestoreQuarantineHandler, ['remediation_uuid' => wp_generate_uuid4(), 'quarantine_uuid' => wp_generate_uuid4(), 'path' => 'wp-content/uploads/nothing.php', 'sha256' => str_repeat('a', 64)]);
        $this->assertSame('QUARANTINE_NOT_FOUND', $unknown->code);
    }

    public function test_quarantine_never_touches_protected_or_core_files(): void
    {
        foreach ([
            'wp-content/plugins/wp-caregrid-connector/wp-caregrid-connector.php' => 'UNSAFE_PATH',
            'wp-includes/version.php' => 'CORE_FILE_USE_REPAIR',
            'wp-content/uploads/does-not-exist.php' => 'FILE_NOT_FOUND',
        ] as $path => $code) {
            $absolute = $path === 'wp-includes/version.php' ? ABSPATH.$path : WP_CONTENT_DIR.substr($path, 10);
            $sha = is_file($absolute) ? hash_file('sha256', $absolute) : str_repeat('b', 64);

            $this->assertSame($code, $this->quarantine($path, $sha)->code, $path);
            if (is_file($absolute)) {
                $this->assertSame($sha, hash_file('sha256', $absolute));
            }
        }

        $this->assertTrue(QuarantineStore::protected_path('wp-config.php'));
    }

    public function test_path_traversal_and_absolute_paths_are_refused_before_anything_runs(): void
    {
        foreach (['../wp-config.php', 'wp-content/../../etc/passwd', '/etc/passwd', 'C:/Windows/win.ini', "wp-content/uploads/a.php\0.jpg", 'wp-content\\uploads\\a.php', 'wp-content/./uploads/a.php'] as $path) {
            $payloads = [
                [new SecurityQuarantineHandler, ['remediation_uuid' => wp_generate_uuid4(), 'finding_uuid' => wp_generate_uuid4(), 'path' => $path, 'sha256' => str_repeat('c', 64)]],
                [new SecurityRestoreQuarantineHandler, ['remediation_uuid' => wp_generate_uuid4(), 'quarantine_uuid' => wp_generate_uuid4(), 'path' => $path, 'sha256' => str_repeat('c', 64)]],
                [new SecurityRepairFileHandler, ['remediation_uuid' => wp_generate_uuid4(), 'source' => 'core', 'path' => $path]],
            ];
            foreach ($payloads as [$handler, $payload]) {
                try {
                    $handler->validate($payload);
                    $this->fail('Accepted: '.$path);
                } catch (InvalidPayload) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_a_symlink_is_never_quarantined_or_followed(): void
    {
        $outside = sys_get_temp_dir().'/wp-caregrid-target-'.wp_generate_password(6, false).'.php';
        file_put_contents($outside, "<?php echo 1;\n");
        $this->cleanup_later($outside);
        $link = WP_CONTENT_DIR.'/uploads/link.php';
        if (! @symlink($outside, $link)) {
            $this->markTestSkipped('This system cannot create symlinks.');
        }
        $this->cleanup_later($link);

        $this->assertSame('UNSAFE_PATH', $this->quarantine('wp-content/uploads/link.php', hash_file('sha256', $outside))->code);
        $this->assertFileExists($outside);
    }

    public function test_a_remediation_is_never_applied_twice(): void
    {
        [$relative, $absolute] = $this->upload('2026/once.php', "<?php echo 'x';\n");
        $sha = hash_file('sha256', $absolute);
        $uuid = wp_generate_uuid4();

        $this->assertSame(JobResult::SUCCESS, $this->quarantine($relative, $sha, $uuid)->status);
        file_put_contents($absolute, "<?php echo 'x';\n"); // the same file appears again

        $replay = $this->quarantine($relative, $sha, $uuid);

        $this->assertSame('REMEDIATION_ALREADY_APPLIED', $replay->code);
        $this->assertFileExists($absolute);
    }

    /* ----- Repair ----- */

    public function test_repair_restores_the_official_core_file_and_quarantines_the_changed_one(): void
    {
        FakeWordPressOrg::core_sums(); // the reference is the pristine file
        $path = 'readme.html';
        $original = (string) file_get_contents(ABSPATH.$path);
        $this->org->svn_core[$path] = $original;

        try {
            file_put_contents(ABSPATH.$path, $original.'<script src="https://evil.example/x.js"></script>');
            $changed = hash_file('sha256', ABSPATH.$path);

            $result = $this->handle(new SecurityRepairFileHandler, ['remediation_uuid' => $uuid = wp_generate_uuid4(), 'source' => 'core', 'path' => $path, 'sha256' => $changed]);

            $this->assertSame(JobResult::SUCCESS, $result->status, (string) ($result->error['message'] ?? ''));
            $this->assertSame('FILE_REPAIRED', $result->code);
            $this->assertSame($original, file_get_contents(ABSPATH.$path));
            $this->assertSame($changed, $result->data['remediation']['quarantine']['sha256'], 'The changed version is kept in quarantine.');
            $this->assertFileExists(QuarantineStore::path().'/'.$uuid.'.quarantined');
            $this->assertContains('https://core.svn.wordpress.org/tags/'.get_bloginfo('version').'/readme.html', $this->org->requested);
        } finally {
            file_put_contents(ABSPATH.$path, $original);
        }
    }

    public function test_repair_refuses_a_download_that_does_not_match_the_official_checksum(): void
    {
        FakeWordPressOrg::core_sums();
        $path = 'readme.html';
        $original = (string) file_get_contents(ABSPATH.$path);
        $this->org->svn_core[$path] = 'tampered in transit';

        try {
            file_put_contents(ABSPATH.$path, $original.'<!-- changed -->');
            $before = hash_file('sha256', ABSPATH.$path);

            $result = $this->handle(new SecurityRepairFileHandler, ['remediation_uuid' => wp_generate_uuid4(), 'source' => 'core', 'path' => $path, 'sha256' => $before]);

            $this->assertSame('SOURCE_VERIFICATION_FAILED', $result->code);
            $this->assertSame($before, hash_file('sha256', ABSPATH.$path), 'Nothing was changed.');
        } finally {
            file_put_contents(ABSPATH.$path, $original);
        }
    }

    public function test_repair_only_for_official_files_and_never_from_a_supplied_url(): void
    {
        [$relative, $absolute] = $this->upload('2026/custom.php', "<?php echo 'x';\n");
        $result = $this->handle(new SecurityRepairFileHandler, ['remediation_uuid' => wp_generate_uuid4(), 'source' => 'core', 'path' => $relative, 'sha256' => hash_file('sha256', $absolute)]);
        $this->assertSame('NOT_AN_OFFICIAL_FILE', $result->code);

        foreach ([['url' => 'https://evil.example/clean.php'], ['source' => 'https://evil.example/'], ['content' => '<?php evil();']] as $extra) {
            try {
                (new SecurityRepairFileHandler)->validate($extra + ['remediation_uuid' => wp_generate_uuid4(), 'source' => 'core', 'path' => 'readme.html']);
                $this->fail('Accepted: '.wp_json_encode($extra));
            } catch (InvalidPayload) {
                $this->addToAssertionCount(1);
            }
        }

        foreach ($this->org->requested as $url) {
            $this->assertStringNotContainsString('evil.example', $url);
        }
    }

    public function test_repair_restores_a_wordpress_org_plugin_file(): void
    {
        $clean = ['acme.php' => "<?php\n/**\n * Plugin Name: Acme\n * Version: 1.2\n */\n", 'lib/tools.php' => "<?php\nfunction acme_tool() { return 1; }\n"];
        $dir = WP_PLUGIN_DIR.'/acme';
        $this->cleanup_later($dir);
        foreach ($clean as $file => $content) {
            wp_mkdir_p(dirname($dir.'/'.$file));
            file_put_contents($dir.'/'.$file, $content);
        }
        wp_clean_plugins_cache(false);
        $this->org->plugin('acme', '1.2', $clean);
        file_put_contents($dir.'/lib/tools.php', "<?php\nfunction acme_tool() { return eval(\$_GET['x']); }\n");

        $result = $this->handle(new SecurityRepairFileHandler, ['remediation_uuid' => wp_generate_uuid4(), 'source' => 'plugin', 'plugin' => 'acme/acme.php', 'path' => 'wp-content/plugins/acme/lib/tools.php', 'sha256' => hash_file('sha256', $dir.'/lib/tools.php')]);

        $this->assertSame(JobResult::SUCCESS, $result->status, (string) ($result->error['message'] ?? ''));
        $this->assertSame($clean['lib/tools.php'], file_get_contents($dir.'/lib/tools.php'));

        $other = $this->handle(new SecurityRepairFileHandler, ['remediation_uuid' => wp_generate_uuid4(), 'source' => 'plugin', 'plugin' => 'acme/acme.php', 'path' => 'wp-content/plugins/other/x.php', 'sha256' => str_repeat('d', 64)]);
        $this->assertSame('NOT_AN_OFFICIAL_FILE', $other->code);
    }

    /* ----- Plugin deactivation ----- */

    public function test_disable_plugin_deactivates_without_running_its_code_and_never_the_connector(): void
    {
        $dir = WP_PLUGIN_DIR.'/compromised';
        $this->cleanup_later($dir);
        wp_mkdir_p($dir);
        file_put_contents($dir.'/compromised.php', "<?php\n/**\n * Plugin Name: Compromised\n */\nregister_deactivation_hook(__FILE__, function () { update_option('compromised_ran', 1); });\n");
        wp_clean_plugins_cache(false);
        activate_plugin('compromised/compromised.php');
        $this->assertTrue(is_plugin_active('compromised/compromised.php'));

        $result = $this->handle(new SecurityDisablePluginHandler, ['remediation_uuid' => wp_generate_uuid4(), 'plugin' => 'compromised/compromised.php']);

        $this->assertSame('PLUGIN_DISABLED', $result->code);
        $this->assertFalse(is_plugin_active('compromised/compromised.php'));
        $this->assertFalse(get_option('compromised_ran'), 'Deactivated silently: its own code did not run.');
        $this->assertFileExists($dir.'/compromised.php', 'Files stay; it can be activated again.');

        $self = $this->handle(new SecurityDisablePluginHandler, ['remediation_uuid' => wp_generate_uuid4(), 'plugin' => 'wp-caregrid-connector/wp-caregrid-connector.php']);
        $this->assertSame('PLUGIN_NOT_FOUND', $self->code);
        $this->assertTrue(is_plugin_active('wp-caregrid-connector/wp-caregrid-connector.php'));
    }

    /* ----- Through the job runner ----- */

    public function test_security_jobs_run_once_and_nothing_else_is_accepted(): void
    {
        $this->pair();
        [$relative, $absolute] = $this->upload('2026/runner.php', "<?php echo 'x';\n");
        $job = $this->job('SECURITY_QUARANTINE', ['remediation_uuid' => wp_generate_uuid4(), 'finding_uuid' => wp_generate_uuid4(), 'path' => $relative, 'sha256' => hash_file('sha256', $absolute)]);
        $this->serve_jobs($job, $job, $this->job('RUN_SQL', ['query' => 'DROP TABLE wp_users']), $this->job('SECURITY_SHELL', ['cmd' => 'id']));

        $this->plugin()->runner()->poll();

        $this->assertSame('FILE_QUARANTINED', $this->posted_result($job['uuid'])['result_code']);
        $this->assertFileDoesNotExist($absolute);
        global $wpdb;
        $this->assertNotEmpty($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"));
    }

    /* ----- Helpers ----- */

    /** @return array{0: string, 1: string} relative, absolute */
    private function upload(string $name, string $content): array
    {
        $absolute = WP_CONTENT_DIR.'/uploads/'.$name;
        wp_mkdir_p(dirname($absolute));
        file_put_contents($absolute, $content);
        $this->cleanup_later(WP_CONTENT_DIR.'/uploads/'.explode('/', $name)[0]);

        return ['wp-content/uploads/'.$name, $absolute];
    }

    private function quarantine(string $path, string $sha256, ?string $remediation = null): JobResult
    {
        return $this->handle(new SecurityQuarantineHandler, ['remediation_uuid' => $remediation ?? wp_generate_uuid4(), 'finding_uuid' => wp_generate_uuid4(), 'path' => $path, 'sha256' => $sha256]);
    }

    private function handle(JobHandler $handler, array $payload): JobResult
    {
        return $handler->handle($handler->validate($payload));
    }
}
