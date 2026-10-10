<?php

namespace WPCareGrid\Connector\Tests\Integration;

use WPCareGrid\Connector\Auth\CredentialStore;
use WPCareGrid\Connector\Backups\BackupFailure;
use WPCareGrid\Connector\Backups\BackupIndex;
use WPCareGrid\Connector\Backups\DatabaseDumper;
use WPCareGrid\Connector\Backups\Downloader;
use WPCareGrid\Connector\Backups\Maintenance;
use WPCareGrid\Connector\Backups\PathGuard;
use WPCareGrid\Connector\Backups\Sink;
use WPCareGrid\Connector\Backups\SqlImporter;
use WPCareGrid\Connector\Backups\TarWriter;
use WPCareGrid\Connector\Backups\TempDir;
use WPCareGrid\Connector\Backups\Uploader;
use WPCareGrid\Connector\Core\Settings;
use WPCareGrid\Connector\Tests\Support\FakeBackupServer;
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

    public function test_file_copy_failure_after_database_swap_reports_destructive_boundary(): void
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
        $this->assertTrue($result['error']['details']['destructive_mutation_started']);
        $this->assertSame('file_apply', $result['error']['details']['failure_stage']);
        wp_cache_flush();
        $this->assertSame('baseline-db', get_option('caregrid_restore_marker'), 'The DB table swap completed before file copy failed.');
        $this->assertNull(get_post($post), 'The backed-up DB state is live.');
        $this->assertDirectoryExists($target, 'The controlled obstruction remains until explicit recovery.');
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
