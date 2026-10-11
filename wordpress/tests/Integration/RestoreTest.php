<?php

namespace WPCareGrid\Connector\Tests\Integration;

use WPCareGrid\Connector\Auth\CredentialStore;
use WPCareGrid\Connector\Backups\BackupFailure;
use WPCareGrid\Connector\Backups\BackupIndex;
use WPCareGrid\Connector\Backups\DatabaseDumper;
use WPCareGrid\Connector\Backups\Downloader;
use WPCareGrid\Connector\Backups\FileRestorer;
use WPCareGrid\Connector\Backups\FilePolicy;
use WPCareGrid\Connector\Backups\Maintenance;
use WPCareGrid\Connector\Backups\PathGuard;
use WPCareGrid\Connector\Backups\Sink;
use WPCareGrid\Connector\Backups\SqlImporter;
use WPCareGrid\Connector\Backups\TarWriter;
use WPCareGrid\Connector\Backups\TempDir;
use WPCareGrid\Connector\Backups\Uploader;
use WPCareGrid\Connector\Core\Lock;
use WPCareGrid\Connector\Core\Settings;
use WPCareGrid\Connector\Jobs\Handlers\RestoreHandler;
use WPCareGrid\Connector\Jobs\JobRegistry;
use WPCareGrid\Connector\Jobs\JobRunner;
use WPCareGrid\Connector\Tests\Support\FakeBackupServer;
use WPCareGrid\Connector\Tests\Support\FilesystemManifest;
use WPCareGrid\Connector\Tests\Support\OneShotRestoreFault;
use WPCareGrid\Connector\Tests\Support\RestoreHarnessGuard;
use WPCareGrid\Connector\Tests\Support\RestoreVariantIndex;
use WPCareGrid\Connector\Tests\Support\TestCase;

/** RESTORE in a real WordPress, from backups this site made (FakeBackupServer serves them back). */
final class RestoreTest extends TestCase
{
    private FakeBackupServer $server;

    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pair();
        $this->server = (new FakeBackupServer($this->http))->register();
        $this->plugin()->set('uploader', new Uploader($this->plugin()->api(), fn () => null));
        $this->plugin()->set('downloader', new Downloader($this->plugin()->api(), fn () => null));
        delete_option('wp_caregrid_backup_index');
    }

    protected function tearDown(): void
    {
        Maintenance::disable();
        @unlink(ABSPATH.'.maintenance');
        remove_all_actions('wp_caregrid_restore_critical_section');
        foreach ($this->created as $path) {
            TempDir::remove($path);
        }
        parent::tearDown();
    }

    public function test_files_restore_puts_files_back_but_not_config_or_connector(): void
    {
        $this->put(WP_CONTENT_DIR.'/uploads/restore-me/photo.jpg', 'ORIGINAL');
        $this->put(WP_CONTENT_DIR.'/themes/twentytwentyfive/caregrid-note.txt', 'theme v1');
        [$backup, $objects] = $this->backup('BACKUP_FILES');

        unlink(WP_CONTENT_DIR.'/uploads/restore-me/photo.jpg');
        file_put_contents(WP_CONTENT_DIR.'/themes/twentytwentyfive/caregrid-note.txt', 'theme v2 (broken)');
        $this->put(WP_CONTENT_DIR.'/uploads/restore-me/added-later.txt', 'new');
        $connector_file = WP_PLUGIN_DIR.'/wp-caregrid-connector/wp-caregrid-connector.php';
        $connector_before = file_get_contents($connector_file);

        $result = $this->restore($backup, 'files', $objects);

        $this->assertSame('success', $result['status'], wp_json_encode([$result['error'], $result['data']['post_checks'] ?? null]));
        $this->assertSame('RESTORED', $result['result_code']);
        $this->assertSame('ORIGINAL', file_get_contents(WP_CONTENT_DIR.'/uploads/restore-me/photo.jpg'));
        $this->assertSame('theme v1', file_get_contents(WP_CONTENT_DIR.'/themes/twentytwentyfive/caregrid-note.txt'));
        $this->assertFileExists(WP_CONTENT_DIR.'/uploads/restore-me/added-later.txt', 'Files added later stay.');
        $this->assertSame($connector_before, file_get_contents($connector_file));
        $this->assertFileDoesNotExist(ABSPATH.'.maintenance');
        $this->assertSame(['files_present', 'database', 'wordpress_version', 'active_theme'], array_column($result['data']['post_checks'], 'id'));
        $this->assertNotContains(false, array_column($result['data']['post_checks'], 'passed'));
        $this->assertArrayHasKey('status', $result['data']['health']);
        $this->assertSame([], $this->temp_files());
    }

    public function test_file_restore_does_not_follow_predictable_temporary_sidecar_symlink(): void
    {
        $target = WP_CONTENT_DIR.'/uploads/m8-sidecar-link/fixture.txt';
        $this->put($target, 'backup version');
        [$backup, $objects] = $this->backup('BACKUP_FILES');

        $outside = sys_get_temp_dir().'/caregrid-sidecar-outside-'.wp_generate_password(6, false).'.txt';
        file_put_contents($outside, 'outside sentinel');
        $this->created[] = $outside;
        file_put_contents($target, 'live version');
        $sidecar = $target.'.caregrid-restore';
        $this->symlink_or_skip($outside, $sidecar);

        $result = $this->restore($backup, 'files', $objects);

        $this->assertSame('success', $result['status'], wp_json_encode($result['error']));
        $this->assertSame('backup version', file_get_contents($target));
        $this->assertSame('outside sentinel', file_get_contents($outside));
        $this->assertTrue(is_link($sidecar), 'The predictable legacy sidecar is never opened or replaced.');
        $this->assertSame([], $this->temp_files());
    }

    public function test_redacted_wp_config_archive_entry_never_replaces_the_live_file(): void
    {
        $path = ABSPATH.'wp-config.php';
        $hadConfig = is_file($path);
        $original = $hadConfig ? file_get_contents($path) : null;
        file_put_contents($path, "<?php\n/* local test config sentinel */\n");

        try {
            $result = $this->restore_crafted_archive(static function (TarWriter $tar): void {
                $tar->add_string('root/wp-config.php', "<?php\n/* removed-by-wp-caregrid */\n", time());
            });

            $this->assertSame('success', $result['status'], wp_json_encode($result['error']));
            $this->assertSame("<?php\n/* local test config sentinel */\n", file_get_contents($path));
            $this->assertSame(1, $result['data']['restored']['files']['skipped']);
        } finally {
            if ($hadConfig && is_string($original)) {
                file_put_contents($path, $original);
            } else {
                @unlink($path);
            }
        }
    }

    public function test_unwritable_destination_is_refused_before_database_mutation(): void
    {
        $this->requires_mysql();
        $directory = WP_CONTENT_DIR.'/uploads/m8-unwritable-destination';
        $target = $directory.'/fixture.txt';
        $this->put($target, 'before');
        update_option('m8_unwritable_restore_marker', 'before');
        [$backup, $objects] = $this->backup('BACKUP_FULL');
        file_put_contents($target, 'after');
        update_option('m8_unwritable_restore_marker', 'after');
        chmod($directory, 0555);

        try {
            $result = $this->restore($backup, 'full', $objects);
            $this->assertSame('failed', $result['status']);
            $this->assertSame('RESTORE_FILES_FAILED', $result['result_code']);
            $this->assertFalse($result['error']['details']['destructive_mutation_started']);
            $this->assertSame('file_staging', $result['error']['details']['failure_stage']);
            $this->assertSame('preflight', $result['error']['details']['operation']);
            $this->assertSame('permission_denied', $result['error']['details']['error_category']);
            $this->assertSame('after', get_option('m8_unwritable_restore_marker'));
            $this->assertSame('after', file_get_contents($target));
            $this->assertSame([], $this->temp_files());
        } finally {
            chmod($directory, 0755);
        }
    }

    public function test_identical_file_in_unwritable_parent_is_a_noop_and_full_restore_succeeds(): void
    {
        $this->requires_mysql();
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unix directory write permissions are required.');
        }

        $directory = WP_CONTENT_DIR.'/mu-plugins/m8-identical-protected-'.wp_generate_password(6, false);
        $target = $directory.'/m8-safety.php';
        $this->put($target, '<?php return "stable fixture";');
        $this->cleanup_later($directory);
        update_option('m8_identical_protected_marker', 'before');
        [$backup, $objects] = $this->backup('BACKUP_FULL');
        $before = stat($target);
        chmod($directory, 0555);
        clearstatcache(true, $directory);
        if (is_writable($directory)) {
            chmod($directory, 0755);
            $this->markTestSkipped('The current test user can write to a mode-0555 directory.');
        }

        try {
            update_option('m8_identical_protected_marker', 'after');
            $result = $this->restore($backup, 'full', $objects);

            $this->assertSame('success', $result['status'], wp_json_encode($result['error']));
            $this->assertGreaterThan(0, $result['data']['restored']['file_plan']['operations']['noop'] ?? 0);
            wp_cache_flush();
            $this->assertSame('before', get_option('m8_identical_protected_marker'));
            $this->assertSame('<?php return "stable fixture";', file_get_contents($target));
            clearstatcache(true, $target);
            $after = stat($target);
            $this->assertSame($before['ino'], $after['ino'], 'A no-op must not replace the protected file.');
            $this->assertSame($before['mtime'], $after['mtime'], 'A no-op must not rewrite the protected file.');
            $this->assertFalse(is_writable($directory));
        } finally {
            chmod($directory, 0755);
        }
    }

    public function test_different_file_in_unwritable_parent_fails_preflight_before_database_swap(): void
    {
        $this->requires_mysql();
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unix directory write permissions are required.');
        }

        $directory = WP_CONTENT_DIR.'/mu-plugins/m8-different-protected-'.wp_generate_password(6, false);
        $target = $directory.'/m8-safety.php';
        $this->put($target, 'archive version');
        $this->cleanup_later($directory);
        update_option('m8_different_protected_marker', 'before');
        [$backup, $objects] = $this->backup('BACKUP_FULL');
        file_put_contents($target, 'different live version');
        update_option('m8_different_protected_marker', 'after');
        chmod($directory, 0555);
        clearstatcache(true, $directory);
        if (is_writable($directory)) {
            chmod($directory, 0755);
            $this->markTestSkipped('The current test user can write to a mode-0555 directory.');
        }

        try {
            $result = $this->restore($backup, 'full', $objects);
            $this->assertSame('failed', $result['status']);
            $this->assertSame('RESTORE_FILES_FAILED', $result['result_code']);
            $this->assertFalse($result['error']['details']['destructive_mutation_started']);
            $this->assertSame('file_staging', $result['error']['details']['failure_stage']);
            $this->assertSame('preflight', $result['error']['details']['operation']);
            $this->assertSame('wp-content/mu-plugins/'.basename($directory).'/m8-safety.php', $result['error']['details']['relative_path']);
            $this->assertSame('permission_denied', $result['error']['details']['error_category']);
            $this->assertGreaterThan(0, $result['error']['details']['restore_plan']['operations']['replace'] ?? 0);
            wp_cache_flush();
            $this->assertSame('after', get_option('m8_different_protected_marker'));
            $this->assertSame('different live version', file_get_contents($target));
            $this->assertSame([], $this->temp_files());
        } finally {
            chmod($directory, 0755);
        }
    }

    public function test_missing_file_in_unwritable_parent_fails_preflight_before_database_swap(): void
    {
        $this->requires_mysql();
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unix directory write permissions are required.');
        }

        $directory = WP_CONTENT_DIR.'/mu-plugins/m8-missing-protected-'.wp_generate_password(6, false);
        $target = $directory.'/m8-safety.php';
        $this->put($target, 'archive version');
        $this->cleanup_later($directory);
        update_option('m8_missing_protected_marker', 'before');
        [$backup, $objects] = $this->backup('BACKUP_FULL');
        unlink($target);
        update_option('m8_missing_protected_marker', 'after');
        chmod($directory, 0555);
        clearstatcache(true, $directory);
        if (is_writable($directory)) {
            chmod($directory, 0755);
            $this->markTestSkipped('The current test user can write to a mode-0555 directory.');
        }

        try {
            $result = $this->restore($backup, 'full', $objects);
            $this->assertSame('failed', $result['status']);
            $this->assertSame('RESTORE_FILES_FAILED', $result['result_code']);
            $this->assertFalse($result['error']['details']['destructive_mutation_started']);
            $this->assertSame('preflight', $result['error']['details']['operation']);
            $this->assertSame('wp-content/mu-plugins/'.basename($directory).'/m8-safety.php', $result['error']['details']['relative_path']);
            $this->assertSame('permission_denied', $result['error']['details']['error_category']);
            $this->assertGreaterThan(0, $result['error']['details']['restore_plan']['operations']['create'] ?? 0);
            wp_cache_flush();
            $this->assertSame('after', get_option('m8_missing_protected_marker'));
            $this->assertFileDoesNotExist($target);
        } finally {
            chmod($directory, 0755);
        }
    }

    public function test_restore_plan_classifies_noop_replace_and_create_before_mutation(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unix directory write permissions are required.');
        }

        $folder = 'm8-plan-'.wp_generate_password(6, false);
        $liveDirectory = WP_CONTENT_DIR.'/mu-plugins/'.$folder;
        $liveFile = $liveDirectory.'/same.php';
        $staging = TempDir::ensure().'/plan-'.wp_generate_uuid4();
        $stagedDirectory = $staging.'/wp-content/mu-plugins/'.$folder;
        wp_mkdir_p($stagedDirectory);
        $this->cleanup_later($liveDirectory);
        $this->cleanup_later($staging);
        $this->put($liveFile, 'same bytes');
        file_put_contents($stagedDirectory.'/same.php', 'same bytes');
        chmod($liveDirectory, 0555);
        clearstatcache(true, $liveDirectory);
        if (is_writable($liveDirectory)) {
            chmod($liveDirectory, 0755);
            $this->markTestSkipped('The current test user can write to a mode-0555 directory.');
        }

        try {
            $restorer = new FileRestorer;
            $restorer->preflight($staging);
            $plan = array_column($restorer->plan(), null, 'path');
            $same = $plan['wp-content/mu-plugins/'.$folder.'/same.php'];
            $this->assertSame('noop', $same['operation']);
            $this->assertSame('identical', $same['comparison']);
            $this->assertFalse($same['mutation_required']);
            $this->assertNull($same['permission_allowed']);
            $this->assertSame(hash('sha256', 'same bytes'), $same['source_sha256']);
            $this->assertSame($same['source_sha256'], $same['destination_sha256']);

            file_put_contents($stagedDirectory.'/same.php', 'different archive bytes');
            file_put_contents($stagedDirectory.'/missing.php', 'new archive bytes');
            $blocked = new FileRestorer;
            try {
                $blocked->preflight($staging);
                $this->fail('Changed and missing files under a protected parent must fail preflight.');
            } catch (BackupFailure $failure) {
                $this->assertSame('RESTORE_FILES_FAILED', $failure->error_code);
                $this->assertSame('preflight', $failure->details['operation']);
                $this->assertSame('permission_denied', $failure->details['error_category']);
            }

            $blockedPlan = array_column($blocked->plan(), null, 'path');
            $replace = $blockedPlan['wp-content/mu-plugins/'.$folder.'/same.php'];
            $create = $blockedPlan['wp-content/mu-plugins/'.$folder.'/missing.php'];
            $this->assertSame('replace', $replace['operation']);
            $this->assertTrue($replace['mutation_required']);
            $this->assertFalse($replace['permission_allowed']);
            $this->assertSame('permission_denied', $replace['failure_reason']);
            $this->assertSame('create', $create['operation']);
            $this->assertTrue($create['mutation_required']);
            $this->assertFalse($create['permission_allowed']);
            $this->assertSame('permission_denied', $create['failure_reason']);
            $this->assertSame('same bytes', file_get_contents($liveFile), 'Planning never mutates the live file.');
            $this->assertFileDoesNotExist($liveDirectory.'/missing.php');
        } finally {
            chmod($liveDirectory, 0755);
        }
    }

    public function test_writable_identical_file_is_not_rewritten(): void
    {
        $target = WP_CONTENT_DIR.'/uploads/m8-identical-writable-'.wp_generate_password(6, false).'.txt';
        $this->put($target, 'unchanged bytes');
        [$backup, $objects] = $this->backup('BACKUP_FILES');
        chmod($target, 0600);
        clearstatcache(true, $target);
        $before = stat($target);

        $result = $this->restore($backup, 'files', $objects);

        $this->assertSame('success', $result['status'], wp_json_encode($result['error']));
        $this->assertGreaterThan(0, $result['data']['restored']['file_plan']['operations']['noop'] ?? 0);
        clearstatcache(true, $target);
        $after = stat($target);
        $this->assertSame($before['ino'], $after['ino']);
        $this->assertSame($before['mtime'], $after['mtime']);
        $this->assertSame(0600, $after['mode'] & 0777, 'File modes are not restored; a no-op leaves the live mode untouched.');
    }

    public function test_full_restore_matches_a_deterministic_filesystem_manifest_and_is_idempotent(): void
    {
        $this->requires_mysql();
        $this->put(WP_CONTENT_DIR.'/uploads/m8-file-manifest/nested/media.txt', 'media baseline');
        $this->put(WP_PLUGIN_DIR.'/m8-fixture-plugin/nested/plugin-state.php', '<?php return "baseline";');
        $this->put(ABSPATH.'.htaccess', "# m8 root fixture\n");
        $expected = $this->capture_restore_manifest();
        update_option('m8_file_manifest_marker', 'baseline');
        [$backup, $objects] = $this->backup('BACKUP_FULL');

        file_put_contents(WP_CONTENT_DIR.'/uploads/m8-file-manifest/nested/media.txt', 'mutated media');
        unlink(WP_PLUGIN_DIR.'/m8-fixture-plugin/nested/plugin-state.php');
        file_put_contents(WP_PLUGIN_DIR.'/m8-fixture-plugin/nested/plugin-state.php', '<?php return "mutated";');
        update_option('m8_file_manifest_marker', 'mutated');

        foreach ([1, 2] as $attempt) {
            $result = $this->restore($backup, 'full', $objects);
            $this->assertSame('success', $result['status'], wp_json_encode($result['error']));
            $this->assertSame('RESTORED', $result['result_code']);
            wp_cache_flush();
            $this->assertSame('baseline', get_option('m8_file_manifest_marker'));
            $this->assertSame($expected, $this->capture_restore_manifest(), 'Restore attempt '.$attempt.' matches every restorable manifest entry.');
        }
    }

    public function test_compensating_full_restore_applies_the_verified_pre_restore_filesystem_manifest(): void
    {
        $this->requires_mysql();
        $this->with_harness_environment(function (): void {
            $directory = WP_CONTENT_DIR.'/uploads/m8-compensation-manifest/nested';
            $target = $directory.'/state.txt';
            $this->put($target, 'primary baseline');
            update_option('m8_compensation_manifest_marker', 'primary');
            [$primary, $primaryObjects] = $this->backup('BACKUP_FULL');
            $primaryDownloads = $this->server->downloads;

            file_put_contents($target, 'pre-restore safety state');
            $this->put($directory.'/safety-only.txt', 'safety state');
            update_option('m8_compensation_manifest_marker', 'safety');
            $expectedSafetyFilesystem = $this->capture_restore_manifest();
            [$safety, $safetyObjects] = $this->backup('BACKUP_FULL');
            $safetyDownloads = $this->server->downloads;

            file_put_contents($target, 'live damaged state');
            update_option('m8_compensation_manifest_marker', 'live');
            $fault = new OneShotRestoreFault('before_file_apply', 'HARNESS_FILE_APPLY_FAILURE');
            $this->server->downloads = $primaryDownloads;
            $failed = $this->restore_with_fault($primary, $primaryObjects, $fault);

            $this->assertSame('failed', $failed['status']);
            $this->assertSame('HARNESS_FILE_APPLY_FAILURE', $failed['result_code']);
            $this->assertTrue($failed['error']['details']['destructive_mutation_started']);
            $this->assertSame('file_apply', $failed['error']['details']['failure_stage']);
            $this->assertSame('live damaged state', file_get_contents($target), 'The injected stop happens before normal file application.');

            $this->server->downloads = $safetyDownloads;
            $compensation = $this->restore($safety, 'full', $safetyObjects);
            $this->assertSame('success', $compensation['status'], wp_json_encode($compensation['error']));
            $this->assertSame('RESTORED', $compensation['result_code']);
            wp_cache_flush();
            $this->assertSame('safety', get_option('m8_compensation_manifest_marker'));
            $this->assertSame($expectedSafetyFilesystem, $this->capture_restore_manifest());
            $this->assertSame([], $this->temp_files());
        });
    }

    public function test_database_restore_keeps_the_connector_connected(): void
    {
        $this->requires_mysql();
        update_option('caregrid_restore_marker', 'before');
        [$backup, $objects] = $this->backup('BACKUP_DATABASE');

        update_option('caregrid_restore_marker', 'after');
        $post = wp_insert_post(['post_title' => 'Written after the backup', 'post_status' => 'publish']);

        $result = $this->restore($backup, 'database', $objects);

        $this->assertSame('success', $result['status'], wp_json_encode([$result['error'], $result['data']['post_checks'] ?? null]));
        wp_cache_flush();
        $this->assertSame('before', get_option('caregrid_restore_marker'));
        $this->assertNull(get_post($post));
        // The connector's own state survives: same credential, still connected, job recorded.
        $this->assertTrue(Settings::is_connected());
        $this->assertSame(self::CREDENTIAL, (new CredentialStore)->get());
        $this->assertNotNull(BackupIndex::find($backup));
        global $wpdb;
        $this->assertSame([], $wpdb->get_col("SHOW TABLES LIKE 'cg%'"), 'No temporary tables are left.');
        $this->assertTrue($result['data']['destructive_mutation_started']);
    }

    public function test_file_destination_type_conflict_is_rejected_before_database_swap(): void
    {
        $this->requires_mysql();
        $target = WP_CONTENT_DIR.'/uploads/m8-auto-recovery/failure.txt';
        $this->put($target, 'baseline-file');
        $this->created[] = dirname($target);
        update_option('caregrid_restore_marker', 'baseline-db');
        [$backup, $objects] = $this->backup('BACKUP_FULL');

        update_option('caregrid_restore_marker', 'mutated-db');
        $post = wp_insert_post(['post_title' => 'M8 mutation after backup', 'post_status' => 'publish']);
        unlink($target);
        mkdir($target);

        $result = $this->restore($backup, 'full', $objects);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('RESTORE_FILES_FAILED', $result['result_code']);
        $this->assertFalse($result['error']['details']['destructive_mutation_started']);
        $this->assertSame('file_staging', $result['error']['details']['failure_stage']);
        $this->assertSame('preflight', $result['error']['details']['operation']);
        $this->assertSame('wp-content/uploads/m8-auto-recovery/failure.txt', $result['error']['details']['relative_path']);
        $this->assertSame('destination_type_conflict', $result['error']['details']['error_category']);
        wp_cache_flush();
        $this->assertSame('mutated-db', get_option('caregrid_restore_marker'), 'A known file destination conflict is found before the DB table swap.');
        $this->assertNotNull(get_post($post), 'The live database remains unchanged.');
        $this->assertDirectoryExists($target, 'The controlled obstruction remains unchanged.');
        $this->assertFileDoesNotExist(ABSPATH.'.maintenance');
    }

    public function test_full_restore(): void
    {
        $this->requires_mysql();
        $this->put(WP_CONTENT_DIR.'/uploads/restore-me/full.txt', 'full v1');
        update_option('caregrid_restore_marker', 'full-before');
        [$backup, $objects] = $this->backup('BACKUP_FULL');

        file_put_contents(WP_CONTENT_DIR.'/uploads/restore-me/full.txt', 'full v2');
        update_option('caregrid_restore_marker', 'full-after');

        $result = $this->restore($backup, 'full', $objects);

        $this->assertSame('success', $result['status'], wp_json_encode($result['error']));
        wp_cache_flush();
        $this->assertSame('full-before', get_option('caregrid_restore_marker'));
        $this->assertSame('full v1', file_get_contents(WP_CONTENT_DIR.'/uploads/restore-me/full.txt'));
        $this->assertGreaterThan(0, $result['data']['restored']['files']['files']);
    }

    public function test_only_backups_this_site_made_are_restored(): void
    {
        [$backup, $objects] = $this->backup('BACKUP_FILES');
        $this->http->requests = [];

        // Unknown backup.
        $unknown = $this->restore(wp_generate_uuid4(), 'files', $objects);
        // Known backup, other checksum (e.g. swapped by a compromised server).
        $objects_other = $objects;
        $objects_other[0]['sha256'] = str_repeat('a', 64);
        $swapped = $this->restore($backup, 'files', $objects_other);

        $this->assertSame('RESTORE_BACKUP_UNKNOWN', $unknown['result_code']);
        $this->assertSame('RESTORE_BACKUP_UNKNOWN', $swapped['result_code']);
        $this->assertSame([], $this->http->api_requests('GET backups/*/objects/*/content'), 'Nothing was downloaded.');
    }

    public function test_downloaded_data_must_match_its_checksum(): void
    {
        [$backup, $objects] = $this->backup('BACKUP_FILES');
        // Storage returns different bytes with a matching per-range header.
        $tampered = TempDir::ensure().'/tampered.bin';
        $this->created[] = $tampered;
        file_put_contents($tampered, str_repeat('x', $objects[0]['size']));
        $this->server->downloads['files'] = $tampered;

        $result = $this->restore($backup, 'files', $objects, false);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('RESTORE_CHECKSUM_MISMATCH', $result['result_code']);
    }

    public function test_restore_scope_must_match_its_objects(): void
    {
        [$backup, $objects] = $this->backup('BACKUP_FILES');

        foreach ([['database', $objects], ['full', $objects], ['files', []], ['everything', $objects]] as [$scope, $list]) {
            $result = $this->restore($backup, $scope, $list);
            $this->assertSame('INVALID_PAYLOAD', $result['result_code'], $scope);
        }
    }

    public function test_database_restore_needs_mysql(): void
    {
        if (DatabaseDumper::supported()) {
            $this->markTestSkipped('Only meaningful without MySQL.');
        }

        $result = $this->restore(wp_generate_uuid4(), 'database', [['name' => 'database', 'size' => 10, 'sha256' => str_repeat('b', 64)]]);

        $this->assertSame('CAPABILITY_UNAVAILABLE', $result['result_code']);
    }

    public function test_archive_with_path_traversal_or_links_is_refused_before_anything_changes(): void
    {
        foreach (['../../evil.php', 'wp-content/../../evil.php', '/etc/cron.d/evil', 'wp-content/uploads/../../../evil.php'] as $name) {
            $result = $this->restore_crafted_archive(fn (TarWriter $tar) => $tar->add_string($name, '<?php evil();', time()));
            $this->assertSame('UNSAFE_PATH', $result['result_code'], $name);
        }

        $this->assertFileDoesNotExist(dirname(ABSPATH).'/evil.php');
        $this->assertFileDoesNotExist(ABSPATH.'evil.php');
    }

    public function test_restore_never_writes_through_a_symlink_out_of_the_site(): void
    {
        $outside = sys_get_temp_dir().'/caregrid-outside-'.wp_generate_password(6, false);
        mkdir($outside);
        $this->created[] = $outside;
        $this->symlink_or_skip($outside, WP_CONTENT_DIR.'/uploads/link-out');

        $result = $this->restore_crafted_archive(fn (TarWriter $tar) => $tar->add_string('wp-content/uploads/link-out/owned.php', '<?php evil();', time()));

        $this->assertSame('success', $result['status'], wp_json_encode($result['error']));
        $this->assertSame(1, $result['data']['restored']['files']['unsafe']);
        $this->assertFileDoesNotExist($outside.'/owned.php');
    }

    public function test_path_guard(): void
    {
        foreach (['../x', 'a/../../x', '/etc/passwd', 'C:/Windows/x', 'a\\..\\x', "a\0b", '', 'a//b', './a'] as $bad) {
            try {
                PathGuard::relative($bad);
                $this->fail("Accepted: {$bad}");
            } catch (BackupFailure $e) {
                $this->assertSame('UNSAFE_PATH', $e->error_code);
            }
        }
        $this->assertSame('wp-content/uploads/a b.jpg', PathGuard::relative('wp-content/uploads/a b.jpg'));
    }

    public function test_sql_importer_accepts_only_the_dump_grammar(): void
    {
        $importer = new SqlImporter('11111111-2222-4333-8444-555555555555', 'wp_');

        $this->assertStringStartsWith('INSERT INTO `cgr', $importer->translate("INSERT INTO `wp_options` VALUES ('1','x\\'y',NULL),('2','z','on');"));

        foreach ([
            'DROP DATABASE wordpress;',
            "INSERT INTO `wp_options` VALUES ((SELECT user_pass FROM wp_users),'x');",
            "INSERT INTO `wp_options` VALUES ('1',LOAD_FILE('/etc/passwd'));",
            "INSERT INTO `wp_options` VALUES ('1','x'); DROP TABLE `wp_users`;",
            "INSERT INTO `other_table` VALUES ('1');",
            "INSERT INTO `wp_options` VALUES ('1','unterminated);",
            'CREATE TABLE `wp_x` (`a` int) ENGINE=InnoDB SELECT * FROM mysql.user;',
            'CREATE TABLE `wp_x` (`a` int) ENGINE=FEDERATED CONNECTION=\'mysql://evil\';',
            'CREATE TABLE `wp_x` (`a` int) ENGINE=InnoDB DATA DIRECTORY=\'/tmp\';',
            'GRANT ALL ON *.* TO evil;',
            "SET GLOBAL general_log = 'ON';",
            'UPDATE `wp_users` SET user_pass = 1;',
        ] as $statement) {
            try {
                $importer->translate($statement);
                $this->fail("Accepted: {$statement}");
            } catch (BackupFailure $e) {
                $this->assertSame('RESTORE_SQL_REJECTED', $e->error_code, $statement);
            }
        }
    }

    public function test_rejected_sql_leaves_the_live_database_untouched(): void
    {
        $this->requires_mysql();
        update_option('caregrid_restore_marker', 'live');
        $dump = "SET NAMES utf8mb4;\nDROP TABLE IF EXISTS `wp_options`;\nCREATE TABLE `wp_options` (`option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `option_name` varchar(191) NOT NULL DEFAULT '', `option_value` longtext NOT NULL, `autoload` varchar(20) NOT NULL DEFAULT 'yes', PRIMARY KEY (`option_id`)) ENGINE=InnoDB;\nINSERT INTO `wp_options` VALUES ('1','siteurl','https://evil.example','yes');\nDROP TABLE `wp_users`;\n";

        $result = $this->restore_crafted_dump($dump);

        $this->assertSame('RESTORE_SQL_REJECTED', $result['result_code']);
        wp_cache_flush();
        $this->assertSame('live', get_option('caregrid_restore_marker'));
        $this->assertSame('https://site.example', get_option('siteurl'));
        global $wpdb;
        $this->assertSame([], $wpdb->get_col("SHOW TABLES LIKE 'cg%'"));
        $this->assertFileDoesNotExist(ABSPATH.'.maintenance');
    }

    public function test_failure_during_the_critical_section_ends_maintenance_mode(): void
    {
        [$backup, $objects] = $this->backup('BACKUP_FILES');
        add_action('wp_caregrid_restore_critical_section', function () {
            $this->assertFileExists(ABSPATH.'.maintenance', 'Maintenance mode is on during the critical section.');
            throw new BackupFailure('RESTORE_FILES_FAILED', 'Simulated failure.');
        });

        $result = $this->restore($backup, 'files', $objects);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('RESTORE_FILES_FAILED', $result['result_code']);
        $this->assertFalse($result['error']['details']['destructive_mutation_started']);
        $this->assertSame('file_staging', $result['error']['details']['failure_stage']);
        $this->assertFileDoesNotExist(ABSPATH.'.maintenance');
    }

    public function test_a_leftover_maintenance_mode_is_removed_at_the_next_poll(): void
    {
        // A restore process that died: our marker and WordPress's .maintenance file remain.
        Maintenance::enable(wp_generate_uuid4());
        $reflection = new \ReflectionProperty(Maintenance::class, 'active');
        $reflection->setValue(null, null);
        $this->assertFileExists(ABSPATH.'.maintenance');

        $this->http->on('GET jobs', ['status' => 200, 'body' => ['jobs' => []]]);
        $this->plugin()->runner()->poll();

        $this->assertFileDoesNotExist(ABSPATH.'.maintenance');
    }

    public function test_someone_elses_maintenance_mode_is_not_touched(): void
    {
        file_put_contents(ABSPATH.'.maintenance', '<?php $upgrading = '.time().';');
        $this->http->on('GET jobs', ['status' => 200, 'body' => ['jobs' => []]]);

        $this->plugin()->runner()->poll();

        $this->assertFileExists(ABSPATH.'.maintenance');
    }

    public function test_fault_harness_guard_refuses_non_test_or_non_loopback_contexts(): void
    {
        $root = (string) getenv('WP_CAREGRID_TEST_ROOT');
        $valid = ['app_env' => 'testing', 'enabled' => true, 'root' => $root, 'site_url' => 'http://127.0.0.1:18419', 'synthetic' => true];
        RestoreHarnessGuard::assertContext($valid);

        foreach ([
            array_replace($valid, ['app_env' => 'production']),
            array_replace($valid, ['app_env' => 'staging']),
            array_replace($valid, ['enabled' => false]),
            array_replace($valid, ['site_url' => 'https://staging.wpcaregrid.com']),
            array_replace($valid, ['site_url' => 'https://example.test']),
            array_replace($valid, ['synthetic' => false]),
            array_replace($valid, ['root' => sys_get_temp_dir().'/not-a-wp-caregrid-test-root']),
        ] as $context) {
            try {
                RestoreHarnessGuard::assertContext($context);
                $this->fail('The restore harness accepted an unsafe context.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('available only', $e->getMessage());
            }
        }
    }

    public function test_variant_lifecycle_never_changes_canonical_index_or_source_artifact(): void
    {
        $this->with_harness_environment(function (): void {
            [$source, $objects] = $this->backup('BACKUP_FILES');
            $canonicalBefore = BackupIndex::find($source);
            $sourceHashes = [];
            foreach ($this->server->downloads as $name => $path) {
                $sourceHashes[$name] = hash_file('sha256', $path);
            }
            $variants = new RestoreVariantIndex;

            try {
                $copy = $variants->createVariant($source, $this->server->downloads);
                $this->assertNull(BackupIndex::find($copy['backup_uuid']), 'Test variant is absent from the canonical option.');
                $this->assertNotNull($variants->find($copy['backup_uuid']));
                $this->assertSame($source, $copy['parent_uuid']);
                try {
                    $variants->createVariant($source, $this->server->downloads, 'corrupt_database');
                    $this->fail('The harness accepted a DB mutation for a files-only source.');
                } catch (\RuntimeException $e) {
                    $this->assertStringContainsString('source backup scope', $e->getMessage());
                }

                $corrupt = $variants->createVariant($source, $this->server->downloads, 'corrupt_files');
                $this->assertNotSame($sourceHashes['files'], hash_file('sha256', $corrupt['paths']['files']));

                $checksum = $variants->createVariant($source, $this->server->downloads, 'files_checksum_mismatch');
                $this->assertNotSame(hash_file('sha256', $checksum['paths']['files']), $checksum['objects']['files']['sha256']);

                $missing = $variants->createVariant($source, $this->server->downloads, 'missing_files');
                $this->assertNull($missing['paths']['files']);
                $this->assertSame($canonicalBefore, BackupIndex::find($source));
                foreach ($this->server->downloads as $name => $path) {
                    $this->assertSame($sourceHashes[$name], hash_file('sha256', $path), 'Canonical source artifact remains unchanged.');
                }
            } finally {
                $variants->cleanup();
            }
        });
    }

    public function test_database_variant_mutations_are_copied_and_test_index_only(): void
    {
        $this->requires_mysql();
        $this->with_harness_environment(function (): void {
            [$source] = $this->backup('BACKUP_DATABASE');
            $canonicalBefore = BackupIndex::find($source);
            $sourcePath = $this->server->downloads['database'];
            $sourceHash = hash_file('sha256', $sourcePath);
            $variants = new RestoreVariantIndex;

            try {
                $corrupt = $variants->createVariant($source, ['database' => $sourcePath], 'corrupt_database');
                $checksum = $variants->createVariant($source, ['database' => $sourcePath], 'database_checksum_mismatch');
                $missing = $variants->createVariant($source, ['database' => $sourcePath], 'missing_database');

                $this->assertNotSame($sourceHash, hash_file('sha256', $corrupt['paths']['database']));
                $this->assertNotSame(hash_file('sha256', $checksum['paths']['database']), $checksum['objects']['database']['sha256']);
                $this->assertNull($missing['paths']['database']);
                foreach ([$corrupt, $checksum, $missing] as $variant) {
                    $this->assertNull(BackupIndex::find($variant['backup_uuid']));
                    $this->assertSame($source, $variant['parent_uuid']);
                }
                $this->assertSame($canonicalBefore, BackupIndex::find($source));
                $this->assertSame($sourceHash, hash_file('sha256', $sourcePath));
            } finally {
                $variants->cleanup();
            }
        });
    }

    public function test_fault_hook_is_deterministic_and_one_shot(): void
    {
        $this->with_harness_environment(function (): void {
            $fault = new OneShotRestoreFault('before_destructive_mutation', 'HARNESS_STOPPED_BEFORE_MUTATION');
            try {
                $fault->reach('index_validated', wp_generate_uuid4());
                $fault->reach('before_destructive_mutation', wp_generate_uuid4());
                $this->fail('The armed one-shot hook did not fire.');
            } catch (BackupFailure $e) {
                $this->assertSame('HARNESS_STOPPED_BEFORE_MUTATION', $e->error_code);
            }
            $fault->reach('before_destructive_mutation', wp_generate_uuid4());
            $this->assertTrue($fault->consumed());
            $this->assertCount(3, $fault->reached());
        });
    }

    public function test_local_variant_smoke_reaches_pre_mutation_boundary_without_restoring(): void
    {
        $this->requires_mysql();
        $this->with_harness_environment(function (): void {
            $this->put(WP_CONTENT_DIR.'/uploads/m8-harness-unchanged.txt', 'SYNTHETIC BASELINE');
            update_option('m8_harness_unchanged', 'yes');
            $baselineFileHash = hash_file('sha256', WP_CONTENT_DIR.'/uploads/m8-harness-unchanged.txt');

            [$source, $objects] = $this->backup('BACKUP_FILES');
            $canonicalBefore = BackupIndex::find($source);
            $canonicalSourcePath = $this->server->downloads['files'];
            $sourceFileHash = hash_file('sha256', $canonicalSourcePath);
            $variants = new RestoreVariantIndex;
            try {
                $variant = $variants->createVariant($source, ['files' => $this->server->downloads['files']]);
                $this->assertNull(BackupIndex::find($variant['backup_uuid']));
                $this->server->downloads['files'] = $variant['paths']['files'];
                $fault = new OneShotRestoreFault('before_destructive_mutation', 'HARNESS_STOPPED_BEFORE_MUTATION');

                $plugin = $this->plugin();
                $registry = new JobRegistry(['RESTORE' => new RestoreHandler($plugin->downloader(), $plugin->health(), $plugin->jobs(), $variants, $fault)]);
                $runner = new JobRunner($plugin->api(), $registry, $plugin->jobs(), $plugin->connection());
                $plugin->set('registry', $registry);
                $plugin->set('runner', $runner);

                $job = $this->job('RESTORE', [
                    'restore_uuid' => wp_generate_uuid4(),
                    'backup_uuid' => $variant['backup_uuid'],
                    'scope' => 'files',
                    'objects' => [['name' => 'files', ...$variant['objects']['files']]],
                ]);
                // Exercise the supported job-poll path with the exact same
                // restore UUID delivered twice in one response.
                $this->serve_jobs($job, $job);
                $runner->poll();
                $result = $plugin->jobs()->find($job['uuid'])['result'];

                $this->assertSame('HARNESS_STOPPED_BEFORE_MUTATION', $result['result_code']);
                $this->assertSame('failed', $result['status']);
                $this->assertFalse($result['error']['details']->destructive_mutation_started);
                $this->assertSame('file_staging', $result['error']['details']->failure_stage);
                $this->assertContains(['point' => 'before_destructive_mutation', 'restore_uuid' => $job['payload']->restore_uuid], $fault->reached());
                $this->assertSame(1, count(array_filter($fault->reached(), static fn (array $hit) => $hit['point'] === 'before_destructive_mutation')));
                $restoreResultRequests = array_filter(
                    $this->http->api_requests('POST jobs/*/result'),
                    static fn (array $request) => $request['path'] === 'jobs/'.$job['uuid'].'/result'
                );
                $this->assertCount(1, $restoreResultRequests, 'Duplicate delivery is acknowledged without rerunning the restore.');
                $this->assertTrue($fault->consumed());
                $this->assertSame($canonicalBefore, BackupIndex::find($source));
                $this->assertNull(BackupIndex::find($variant['backup_uuid']));
                $this->assertSame($sourceFileHash, hash_file('sha256', $canonicalSourcePath));
                $this->assertSame($baselineFileHash, hash_file('sha256', WP_CONTENT_DIR.'/uploads/m8-harness-unchanged.txt'));
                $this->assertSame('yes', get_option('m8_harness_unchanged'));
                $this->assertFileDoesNotExist(ABSPATH.'.maintenance');
                $this->assertSame([], $this->temp_files());
            } finally {
                $variants->cleanup();
            }
        });
    }

    public function test_hard_killed_worker_before_mutation_reports_false_after_redelivery(): void
    {
        if (getenv('WP_CAREGRID_RUN_PRE_MUTATION_INTERRUPTION_E2E') !== '1') {
            $this->markTestSkipped('Opt-in: a real worker kill requires waiting for the 900-second Connector poll-lock TTL.');
        }
        $this->requires_mysql();
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_kill') || ! defined('SIGKILL')) {
            $this->environment_limitation('Hard worker interruption requires a POSIX local test runner.');
        }

        $this->with_harness_environment(function (): void {
            $this->put(WP_CONTENT_DIR.'/uploads/m8-process-interrupt/baseline.txt', 'baseline-file');
            update_option('m8_process_interrupt_marker', 'baseline-db');
            [$backup, $objects] = $this->backup('BACKUP_FULL');
            $artifacts = $this->copy_verified_artifacts_into_harness($backup, $objects);
            file_put_contents(WP_CONTENT_DIR.'/uploads/m8-process-interrupt/baseline.txt', 'pre-kill-mutated-file');
            update_option('m8_process_interrupt_marker', 'pre-kill-mutated-db');
            $preJob = $this->restore_job($backup, $objects);
            [$preProcess, $preBarrier] = $this->start_worker_until_barrier($preJob, $artifacts, 'before_destructive_mutation');
            $preMarker = $this->hard_kill_worker($preProcess, $preBarrier, 'before_destructive_mutation');
            $this->assertSame('pre-kill-mutated-db', get_option('m8_process_interrupt_marker'));
            $this->assertSame('pre-kill-mutated-file', file_get_contents(WP_CONTENT_DIR.'/uploads/m8-process-interrupt/baseline.txt'));

            $preResult = $this->redeliver_after_connector_lock_expiry($preJob, $artifacts, $preMarker);
            $this->assertSame('JOB_INTERRUPTED', $preResult['result']['result_code']);
            $this->assertFalse($preResult['result']['error']['details']['destructive_mutation_started']);
            $this->assertSame('unknown', $preResult['result']['error']['details']['failure_stage'], 'A hard kill before handler entry cannot persist a more precise stage.');
            $this->assertSame($preJob['uuid'], $preResult['job_uuid']);
            $this->assertSame($preMarker['restore_uuid'], $preJob['payload']->restore_uuid);
            $this->assertSame(false, $preResult['state']['poll_lock_after']);
            $this->record_process_evidence('connector-pre-destructive-interruption.json', ['boundary' => $preMarker, 'job_uuid' => $preJob['uuid'], 'redelivery' => $preResult]);
        });
    }

    public function test_hard_killed_worker_after_database_swap_reports_true_and_fixture_recovers(): void
    {
        if (getenv('WP_CAREGRID_RUN_HARD_INTERRUPTION_E2E') !== '1') {
            $this->markTestSkipped('Opt-in: a real worker kill requires waiting for the 900-second Connector poll-lock TTL.');
        }
        $this->requires_mysql();
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_kill') || ! defined('SIGKILL')) {
            $this->environment_limitation('Hard worker interruption requires a POSIX local test runner.');
        }

        $this->with_harness_environment(function (): void {
            $this->put(WP_CONTENT_DIR.'/uploads/m8-process-interrupt/baseline.txt', 'baseline-file');
            update_option('m8_process_interrupt_marker', 'baseline-db');
            [$backup, $objects] = $this->backup('BACKUP_FULL');
            $artifacts = $this->copy_verified_artifacts_into_harness($backup, $objects);
            $baselineFileHash = hash_file('sha256', WP_CONTENT_DIR.'/uploads/m8-process-interrupt/baseline.txt');

            file_put_contents(WP_CONTENT_DIR.'/uploads/m8-process-interrupt/baseline.txt', 'post-kill-mutated-file');
            update_option('m8_process_interrupt_marker', 'post-kill-mutated-db');
            $postJob = $this->restore_job($backup, $objects);
            [$postProcess, $postBarrier] = $this->start_worker_until_barrier($postJob, $artifacts, 'after_database_swap');
            $postMarker = $this->hard_kill_worker($postProcess, $postBarrier, 'after_database_swap');
            wp_cache_flush();
            $this->assertSame('baseline-db', get_option('m8_process_interrupt_marker'), 'The MySQL table swap completed before the process was killed.');
            $this->assertSame('post-kill-mutated-file', file_get_contents(WP_CONTENT_DIR.'/uploads/m8-process-interrupt/baseline.txt'), 'File application had not begun at the boundary.');

            $postResult = $this->redeliver_after_connector_lock_expiry($postJob, $artifacts, $postMarker);
            $this->assertSame('JOB_INTERRUPTED', $postResult['result']['result_code']);
            $this->assertTrue($postResult['result']['error']['details']['destructive_mutation_started']);
            $this->assertSame('database_swap', $postResult['result']['error']['details']['failure_stage']);
            $this->assertSame($postJob['uuid'], $postResult['job_uuid']);
            $this->assertSame($postMarker['restore_uuid'], $postJob['payload']->restore_uuid);
            $this->assertSame(false, $postResult['state']['poll_lock_after']);

            // These are the actual Connector values consumed by Laravel when
            // the signed job result is reported. The paired Laravel MySQL test
            // verifies they queue the same restore's safety backup only once.
            $this->assertTrue($postResult['result']['error']['details']['destructive_mutation_started']);

            // Return the disposable site to the known baseline through a fresh
            // normal Connector restore job, then confirm deterministic state.
            $recoveryJob = $this->restore_job($backup, $objects);
            $resultFile = $this->run_worker($recoveryJob, $artifacts, null);
            $recovery = json_decode((string) file_get_contents($resultFile), true, 32, JSON_THROW_ON_ERROR);
            $recovery['state'] = json_decode((string) file_get_contents($resultFile.'.state'), true, 16, JSON_THROW_ON_ERROR);
            $this->assertSame('success', $recovery['result']['status']);
            $this->assertSame('RESTORED', $recovery['result']['result_code']);
            wp_cache_flush();
            $this->assertSame('baseline-db', get_option('m8_process_interrupt_marker'));
            $this->assertSame($baselineFileHash, hash_file('sha256', WP_CONTENT_DIR.'/uploads/m8-process-interrupt/baseline.txt'));
            $this->assertSame(false, $recovery['state']['poll_lock_after']);
            $remainingTemp = $this->temp_files();
            sort($remainingTemp);
            $expectedTemp = [
                $postJob['payload']->restore_uuid.'-database.download',
                $postJob['payload']->restore_uuid.'-files-staging',
                $postJob['payload']->restore_uuid.'-files.download',
            ];
            sort($expectedTemp);
            $this->assertSame($expectedTemp, $remainingTemp, 'Only the interrupted restore’s job-scoped artifacts remain; normal six-hour stale cleanup and test-root teardown own their removal.');
            $this->record_process_evidence('connector-post-destructive-interruption.json', ['boundary' => $postMarker, 'job_uuid' => $postJob['uuid'], 'redelivery' => $postResult, 'caregrid_consumption_regression' => 'PreUpdateAndRestoreTest::test_interrupted_restore_with_persisted_post_swap_marker_queues_recovery_once', 'recovery_job_uuid' => $recoveryJob['uuid'], 'recovery_result' => $recovery, 'baseline_file_sha256' => $baselineFileHash]);
        });
    }

    private function copy_verified_artifacts_into_harness(string $backup, array $objects): array
    {
        $root = RestoreHarnessGuard::activeRoot();
        $directory = $root.'/restore-process-harness/artifacts';
        wp_mkdir_p($directory);
        chmod(dirname($directory), 0700);
        chmod($directory, 0700);
        $artifacts = [];
        foreach ($objects as $object) {
            $name = $object['name'];
            $source = $this->server->downloads[$name] ?? '';
            $target = $directory.'/'.$backup.'-'.$name.'.artifact';
            $this->assertFileExists($source);
            $this->assertSame((int) $object['size'], filesize($source));
            $this->assertSame($object['sha256'], hash_file('sha256', $source));
            $this->assertTrue(copy($source, $target));
            chmod($target, 0600);
            $artifacts[$name] = ['path' => $target, 'size' => (int) filesize($target), 'sha256' => hash_file('sha256', $target)];
        }

        return $artifacts;
    }

    private function record_process_evidence(string $filename, array $evidence): void
    {
        $directory = getenv('WP_CAREGRID_M8_EVIDENCE_DIR');
        if (! is_string($directory) || $directory === '') {
            return;
        }
        $realDirectory = realpath($directory);
        if (! is_string($realDirectory) || ! is_dir($realDirectory) || is_link($directory)) {
            throw new \RuntimeException('The process evidence directory must already exist and cannot be a symlink.');
        }
        $path = $realDirectory.DIRECTORY_SEPARATOR.$filename;
        if (is_link($path)) {
            throw new \RuntimeException('Refusing to replace a linked process evidence file.');
        }
        file_put_contents($path, wp_json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($path, 0600);
    }

    private function restore_job(string $backup, array $objects): array
    {
        $mapped = array_map(static fn (array $object): array => ['name' => $object['name'], 'size' => (int) $object['size'], 'sha256' => $object['sha256']], $objects);

        return $this->job('RESTORE', ['restore_uuid' => wp_generate_uuid4(), 'backup_uuid' => $backup, 'scope' => 'full', 'objects' => $mapped]);
    }

    /** @return array{0: array, 1: string} */
    private function start_worker_until_barrier(array $job, array $artifacts, string $point): array
    {
        $this->run_worker($job, $artifacts, $point, false);
        $config = $this->worker_config_path($job['uuid']);
        $contents = json_decode((string) file_get_contents($config), true, 32, JSON_THROW_ON_ERROR);
        $barrier = $contents['barrier_dir'];
        $process = $this->worker_process($config);
        $deadline = microtime(true) + 40;
        while (microtime(true) < $deadline && ! is_file($barrier.'/reached.json') && proc_get_status($process['handle'])['running']) {
            usleep(10000);
        }
        if (! is_file($barrier.'/reached.json')) {
            $status = proc_get_status($process['handle']);
            if ($status['running']) {
                posix_kill($process['pid'], SIGTERM);
            }
            proc_close($process['handle']);
            $this->fail('Worker did not reach the requested deterministic restore boundary: '.trim((string) @file_get_contents($process['stderr'])));
        }

        return [$process, $barrier];
    }

    private function hard_kill_worker(array $process, string $barrier, string $expectedPoint): array
    {
        $marker = json_decode((string) file_get_contents($barrier.'/reached.json'), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame($expectedPoint, $marker['point']);
        $this->assertSame($process['pid'], (int) $marker['pid'], 'The synchronized PID is the actual PHP worker process.');
        $this->assertTrue(posix_kill((int) $marker['pid'], SIGKILL));
        $deadline = microtime(true) + 5;
        do {
            $status = proc_get_status($process['handle']);
            if (! $status['running']) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->assertFalse($status['running'], 'The targeted worker process exited after SIGKILL.');
        proc_close($process['handle']);

        return $marker;
    }

    private function redeliver_after_connector_lock_expiry(array $job, array $artifacts, array $barrierMarker): array
    {
        $lockStarted = (int) ($barrierMarker['poll_lock_value'] ?? 0);
        $this->assertGreaterThan(0, $lockStarted, 'The worker persisted its poll lock before reaching the barrier.');
        $deadline = $lockStarted + Lock::TTL + 2;
        while (time() <= $deadline) {
            sleep(1); // Wait for the real lock TTL; no option mutation/time travel.
        }
        $resultFile = $this->run_worker($job, $artifacts, null);
        $result = json_decode((string) file_get_contents($resultFile), true, 32, JSON_THROW_ON_ERROR);
        $result['state'] = json_decode((string) file_get_contents($resultFile.'.state'), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame($job['uuid'], $result['job_uuid']);

        return $result;
    }

    private function run_worker(array $job, array $artifacts, ?string $barrier, bool $wait = true): string
    {
        $configPath = $this->worker_config_path($job['uuid']);
        $root = RestoreHarnessGuard::activeRoot();
        $barrierDir = $root.'/restore-process-harness/barriers/'.$job['uuid'];
        wp_mkdir_p($barrierDir);
        chmod($barrierDir, 0700);
        $resultFile = $root.'/restore-process-harness/results/'.$job['uuid'].'-'.bin2hex(random_bytes(4)).'.json';
        wp_mkdir_p(dirname($resultFile));
        $contents = ['barrier' => $barrier, 'barrier_dir' => $barrierDir, 'jobs' => [$job], 'artifacts' => $artifacts, 'result_file' => $resultFile, 'state_file' => $resultFile.'.state'];
        file_put_contents($configPath, wp_json_encode($contents, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($configPath, 0600);
        if (! $wait) {
            return $resultFile;
        }
        $process = $this->worker_process($configPath);
        $deadline = microtime(true) + 90;
        do {
            $status = proc_get_status($process['handle']);
            if (! $status['running']) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if ($status['running']) {
            posix_kill($process['pid'], SIGTERM);
            proc_close($process['handle']);
            $this->fail('The Connector worker exceeded its bounded runtime.');
        }
        $reportedExitCode = (int) ($status['exitcode'] ?? -1);
        $closeExitCode = proc_close($process['handle']);
        $exitCode = $closeExitCode >= 0 ? $closeExitCode : $reportedExitCode;
        $this->assertSame(0, $exitCode, 'The Connector worker completed normally: '.trim((string) @file_get_contents($process['stderr'])));

        return $resultFile;
    }

    private function worker_config_path(string $jobUuid): string
    {
        $root = RestoreHarnessGuard::activeRoot();
        $directory = $root.'/restore-process-harness/config';
        wp_mkdir_p($directory);
        chmod($directory, 0700);

        return $directory.'/'.$jobUuid.'.json';
    }

    private function worker_process(string $configPath): array
    {
        $root = RestoreHarnessGuard::activeRoot();
        $logDirectory = $root.'/restore-process-harness/results';
        wp_mkdir_p($logDirectory);
        chmod($logDirectory, 0700);
        $stdout = $logDirectory.'/worker-'.bin2hex(random_bytes(4)).'.out';
        $stderr = $logDirectory.'/worker-'.bin2hex(random_bytes(4)).'.err';
        $process = proc_open([
            PHP_BINARY,
            dirname(__DIR__).'/Support/restore-process-worker.php',
            $configPath,
        ], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $stdout, 'ab'],
            2 => ['file', $stderr, 'ab'],
        ], $pipes, dirname(__DIR__, 2), [
            'APP_ENV' => 'testing',
            'WP_CAREGRID_RESTORE_HARNESS' => '1',
            'WP_CAREGRID_TEST_ROOT' => (string) getenv('WP_CAREGRID_TEST_ROOT'),
            'WP_CAREGRID_TEST_MYSQL' => (string) getenv('WP_CAREGRID_TEST_MYSQL'),
        ], ['bypass_shell' => true]);
        $this->assertIsResource($process, 'Could not start the isolated Connector worker.');
        $status = proc_get_status($process);
        $this->assertGreaterThan(0, (int) $status['pid']);

        return ['handle' => $process, 'pid' => (int) $status['pid'], 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function with_harness_environment(callable $callback): void
    {
        $keys = ['APP_ENV', 'WP_CAREGRID_RESTORE_HARNESS'];
        $previous = [];
        foreach ($keys as $key) {
            $value = getenv($key);
            $previous[$key] = $value === false ? null : $value;
        }
        $siteUrl = get_option('siteurl');
        $home = get_option('home');
        $fixture = get_option('wp_caregrid_test_fixture');
        putenv('APP_ENV=testing');
        putenv('WP_CAREGRID_RESTORE_HARNESS=1');
        update_option('siteurl', 'http://127.0.0.1:18419');
        update_option('home', 'http://127.0.0.1:18419');
        update_option('wp_caregrid_test_fixture', 'synthetic');

        try {
            $callback();
        } finally {
            foreach ($previous as $key => $value) {
                $value === null ? putenv($key) : putenv($key.'='.$value);
            }
            update_option('siteurl', $siteUrl);
            update_option('home', $home);
            $fixture === false ? delete_option('wp_caregrid_test_fixture') : update_option('wp_caregrid_test_fixture', $fixture);
        }
    }

    /* ----- helpers ----- */

    /** Make a real backup; its objects become downloadable. @return array{0: string, 1: list<array>} */
    private function backup(string $type): array
    {
        $uuid = wp_generate_uuid4();
        $job = $this->job($type, ['backup_uuid' => $uuid, 'reason' => 'manual', 'chunk_size' => 4194304]);
        $this->serve_jobs($job);
        $this->plugin()->runner()->poll();
        $this->assertSame('success', $this->posted_result($job['uuid'])['status'], wp_json_encode($this->posted_result($job['uuid'])['error']));

        $objects = [];
        foreach ($this->server->objects as $name => $bytes) {
            $path = sys_get_temp_dir().'/caregrid-download-'.$uuid.'-'.$name;
            file_put_contents($path, $bytes);
            $this->created[] = $path;
            $this->server->downloads[$name] = $path;
            $objects[] = ['name' => $name, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        }
        $this->server->objects = [];
        $this->server->completed = [];

        return [$uuid, $objects];
    }

    private function restore(string $backup, string $scope, array $objects, bool $fresh_http = true): array
    {
        $job = $this->job('RESTORE', ['restore_uuid' => wp_generate_uuid4(), 'backup_uuid' => $backup, 'scope' => $scope, 'objects' => $objects]);
        $this->serve_jobs($job);
        $this->plugin()->runner()->poll();

        return $this->posted_result($job['uuid']) ?? $this->fail('No result posted.');
    }

    private function restore_with_fault(string $backup, array $objects, OneShotRestoreFault $fault): array
    {
        $plugin = $this->plugin();
        $registry = new JobRegistry(['RESTORE' => new RestoreHandler($plugin->downloader(), $plugin->health(), $plugin->jobs(), null, $fault)]);
        $runner = new JobRunner($plugin->api(), $registry, $plugin->jobs(), $plugin->connection());
        $plugin->set('registry', $registry);
        $plugin->set('runner', $runner);
        $job = $this->job('RESTORE', ['restore_uuid' => wp_generate_uuid4(), 'backup_uuid' => $backup, 'scope' => 'full', 'objects' => $objects]);
        $this->serve_jobs($job);
        $runner->poll();

        return $this->posted_result($job['uuid']) ?? $this->fail('No injected restore result was posted.');
    }

    private function capture_restore_manifest(): array
    {
        $manifest = [];
        foreach (FilesystemManifest::capture(WP_CONTENT_DIR) as $path => $entry) {
            $manifest['wp-content/'.$path] = $entry;
        }

        foreach (FilePolicy::ROOT_FILES as $name) {
            $path = ABSPATH.$name;
            if (! is_file($path) || is_link($path)) {
                continue;
            }
            $mode = fileperms($path);
            $size = filesize($path);
            $sha256 = hash_file('sha256', $path);
            if ($mode === false || $size === false || $sha256 === false) {
                throw new \RuntimeException('A selected root file could not be fingerprinted.');
            }
            $manifest['root/'.$name] = ['type' => 'file', 'size' => $size, 'sha256' => $sha256, 'mode' => $mode & 0777];
        }

        ksort($manifest, SORT_STRING);

        return $manifest;
    }

    /** A files archive built by hand, registered in the index as if this site made it. */
    private function restore_crafted_archive(callable $build): array
    {
        $uuid = wp_generate_uuid4();
        $path = TempDir::ensure().'/crafted-'.$uuid.'.tar.gz';
        $this->created[] = $path;
        $sink = new Sink($path, true);
        $tar = new TarWriter($sink);
        $build($tar);
        $tar->finish();
        $file = $sink->close();

        return $this->restore_registered($uuid, 'files', $path, $file['size'], $file['sha256']);
    }

    private function restore_crafted_dump(string $sql): array
    {
        $uuid = wp_generate_uuid4();
        $path = TempDir::ensure().'/crafted-'.$uuid.'.sql.gz';
        $this->created[] = $path;
        file_put_contents($path, gzencode($sql));

        return $this->restore_registered($uuid, 'database', $path, filesize($path), hash_file('sha256', $path));
    }

    private function restore_registered(string $uuid, string $name, string $path, int $size, string $sha256): array
    {
        BackupIndex::record($uuid, $name, [$name => ['size' => $size, 'sha256' => $sha256, 'uncompressed_size' => $size]]);
        $this->server->downloads[$name] = $path;

        return $this->restore($uuid, $name, [['name' => $name, 'size' => $size, 'sha256' => $sha256]]);
    }

    private function put(string $path, string $content): void
    {
        wp_mkdir_p(dirname($path));
        file_put_contents($path, $content);
        $this->created[] = $path;
        if (str_contains($path, '/uploads/restore-me/')) {
            $this->created[] = WP_CONTENT_DIR.'/uploads/restore-me';
        }
    }

    private function temp_files(): array
    {
        return array_values(preg_grep('/^[0-9a-f-]{36}-/', array_map('basename', (array) glob(TempDir::path().'/*'))));
    }

    private function requires_mysql(): void
    {
        if (! DatabaseDumper::supported()) {
            $this->markTestSkipped('Needs MySQL/MariaDB: run with WP_CAREGRID_TEST_MYSQL=user:password@host/database.');
        }
    }
}
