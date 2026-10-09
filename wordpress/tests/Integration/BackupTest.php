<?php

namespace WPCareGrid\Connector\Tests\Integration;

use WPCareGrid\Connector\Backups\BackupIndex;
use WPCareGrid\Connector\Backups\DatabaseDumper;
use WPCareGrid\Connector\Backups\SqlImporter;
use WPCareGrid\Connector\Backups\TarReader;
use WPCareGrid\Connector\Backups\TempDir;
use WPCareGrid\Connector\Backups\Uploader;
use WPCareGrid\Connector\Jobs\JobRegistry;
use WPCareGrid\Connector\Security\Logger;
use WPCareGrid\Connector\Tests\Support\FakeBackupServer;
use WPCareGrid\Connector\Tests\Support\TestCase;

/** BACKUP_FILES / BACKUP_DATABASE / BACKUP_FULL in a real WordPress; the server side is FakeBackupServer. */
final class BackupTest extends TestCase
{
    private FakeBackupServer $server;

    /** @var string[] */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pair();
        $this->server = (new FakeBackupServer($this->http))->register();
        // No real waiting between retries.
        $this->plugin()->set('uploader', new Uploader($this->plugin()->api(), fn () => null));
        delete_option('wp_caregrid_backup_runs');
        delete_option('wp_caregrid_backup_index');
    }

    protected function tearDown(): void
    {
        // Each PHPUnit method shares one synthetic site within the run.
        // Remove its fixtures so archives in later methods stay deterministic.
        foreach (array_reverse($this->created) as $path) {
            TempDir::remove($path);
        }
        remove_all_filters('wp_caregrid_backup_free_space');
        remove_all_filters('wp_caregrid_backup_max_file_size');
        parent::tearDown();
    }

    public function test_only_implemented_backup_capabilities_are_advertised(): void
    {
        $expected = ['inventory', 'health', 'updates', 'license_check', 'backup_files', 'restore_files', 'admin_access', 'security_scan', 'security_quarantine', 'security_remediate'];
        if ($this->mysql()) {
            array_push($expected, 'backup_database', 'backup_full', 'restore_database', 'restore_full');
        }

        $this->assertSame($expected, JobRegistry::capabilities());

        $this->http->on('POST heartbeat', ['status' => 200, 'body' => ['ok' => true]]);
        $this->plugin()->heartbeat()->send();
        $this->assertSame($expected, $this->http->api_requests('POST heartbeat')[0]['body']['capabilities']);
    }

    public function test_files_backup_follows_the_inclusion_policy(): void
    {
        $this->put(WP_CONTENT_DIR.'/uploads/2026/09/photo.jpg', 'JPEGDATA');
        $this->put(WP_CONTENT_DIR.'/uploads/'.str_repeat('long-folder-name/', 8).'deep-file.txt', 'deep');
        $this->put(WP_CONTENT_DIR.'/cache/page.html', 'cached');
        $this->put(WP_CONTENT_DIR.'/debug.log', 'log line');
        $this->put(WP_CONTENT_DIR.'/uploads/wc-logs/fatal.log', 'wc log');
        $this->put(WP_CONTENT_DIR.'/ai1wm-backups/site.wpress', 'other backup plugin');
        $this->put(WP_CONTENT_DIR.'/plugins/big-plugin/huge.bin', str_repeat('x', 3 * 1024 * 1024));
        add_filter('wp_caregrid_backup_max_file_size', fn () => 2 * 1024 * 1024);
        $outside = sys_get_temp_dir().'/wp-caregrid-outside-'.wp_generate_password(6, false);
        wp_mkdir_p($outside);
        $this->cleanup_later($outside);
        $this->symlink_or_skip($outside, WP_CONTENT_DIR.'/uploads/escape');

        $result = $this->run_backup('BACKUP_FILES');

        $this->assertSame('success', $result['status'], wp_json_encode($result['error']));
        $this->assertSame('BACKUP_UPLOADED', $result['result_code']);
        $entries = $this->entries($this->server->objects['files']);

        $this->assertArrayHasKey('wp-content/themes/twentytwentyfive/style.css', $entries);
        $this->assertArrayHasKey('wp-content/plugins/wp-caregrid-connector/wp-caregrid-connector.php', $entries);
        $this->assertSame('JPEGDATA', $entries['wp-content/uploads/2026/09/photo.jpg']);
        $this->assertSame('deep', $entries['wp-content/uploads/'.str_repeat('long-folder-name/', 8).'deep-file.txt'], 'Long names via pax headers.');
        foreach (['wp-content/cache/page.html', 'wp-content/debug.log', 'wp-content/uploads/wc-logs/fatal.log', 'wp-content/ai1wm-backups/site.wpress', 'wp-content/plugins/big-plugin/huge.bin'] as $excluded) {
            $this->assertArrayNotHasKey($excluded, $entries);
        }
        $this->assertSame([], preg_grep('#^wp-content/uploads/escape#', array_keys($entries)), 'Symlinks are never followed.');
        $this->assertSame([], preg_grep('#^wp-core|^wp-includes|^wp-admin#', array_keys($entries)), 'WordPress core is not included.');

        $files = $this->server->manifest['files'];
        $this->assertSame(1, $files['skipped']['symlinks']);
        $this->assertSame(1, $files['skipped']['too_large']);
        $this->assertGreaterThanOrEqual(3, $files['skipped']['excluded']);
        $this->assertSame('standard-1', $files['policy']);
        $this->assertSame('ustar', $files['format']);
        $this->assertSame('gzip', $files['compression']);
    }

    public function test_wp_config_is_backed_up_without_secrets(): void
    {
        $config = ABSPATH.'wp-config.php';
        $this->created[] = $config;
        file_put_contents($config, "<?php\ndefine( 'DB_NAME', 'site_db' );\ndefine( 'DB_USER', '".DB_USER."' );\ndefine('DB_PASSWORD', \"".DB_PASSWORD."-x'y\");\ndefine('AUTH_KEY', '".AUTH_KEY."');\ndefine('SECURE_AUTH_SALT', '".SECURE_AUTH_SALT."');\n\$table_prefix = 'wp_';\n");

        $this->run_backup('BACKUP_FILES');

        $copy = $this->entries($this->server->objects['files'])['root/wp-config.php'];
        $this->assertSame('redacted', $this->server->manifest['files']['wp_config']);
        $this->assertStringContainsString("define( 'DB_NAME', 'site_db' )", $copy, 'Settings needed for recovery stay.');
        foreach (['DB_PASSWORD', 'AUTH_KEY', 'SECURE_AUTH_SALT'] as $secret) {
            $this->assertStringContainsString("'{$secret}', 'removed-by-wp-caregrid'", str_replace('"', "'", $copy));
        }
        foreach (array_filter([DB_PASSWORD, AUTH_KEY, SECURE_AUTH_SALT]) as $value) {
            $this->assertStringNotContainsString($value, $copy);
        }
    }

    public function test_wp_config_that_cannot_be_redacted_is_left_out(): void
    {
        $config = ABSPATH.'wp-config.php';
        $this->created[] = $config;
        // A salt defined in a way the redactor does not recognise.
        file_put_contents($config, "<?php\n\$salt = '".SECURE_AUTH_SALT."';\ndefine('SECURE_AUTH_SALT', \$salt);\n");

        $this->run_backup('BACKUP_FILES');

        $this->assertSame('excluded', $this->server->manifest['files']['wp_config']);
        $this->assertArrayNotHasKey('root/wp-config.php', $this->entries($this->server->objects['files']));
    }

    public function test_database_backup_is_a_restorable_dump_without_transients_or_connector_state(): void
    {
        $this->requires_mysql();
        update_option('caregrid_raw_value', "CareGrid's \"test\" site\nwith newline\\and backslash");
        set_transient('caregrid_cached', 'TRANSIENT-VALUE');

        $result = $this->run_backup('BACKUP_DATABASE');

        $this->assertSame('success', $result['status'], wp_json_encode($result['error']));
        $sql = (string) gzdecode($this->server->objects['database']);
        $this->assertStringContainsString('CREATE TABLE `wp_options`', $sql);
        $this->assertStringContainsString("'caregrid_raw_value','CareGrid\\'s \\\"test\\\" site\\nwith newline\\\\and backslash'", $sql);
        $this->assertStringNotContainsString('TRANSIENT-VALUE', $sql);
        $this->assertStringNotContainsString('wp_caregrid_credential', $sql, 'The connector credential is not in the backup.');
        $this->assertStringNotContainsString(DB_PASSWORD, $sql);

        // Every line is one of the statement forms the restore accepts.
        $importer = new SqlImporter('11111111-2222-4333-8444-555555555555', 'wp_');
        foreach (array_filter(explode("\n", $sql)) as $line) {
            if (! str_starts_with($line, '-- ')) {
                $importer->translate($line);
            }
        }

        $database = $this->server->manifest['database'];
        $this->assertSame('wp_', $database['table_prefix']);
        $this->assertContains($database['engine'], ['mysql', 'mariadb']);
        $this->assertGreaterThan(10, $database['tables']);
        $this->assertSame(['transients', 'connector_state'], $database['excluded']);
        $this->assertSame('gzip', $database['compression']);
    }

    public function test_full_backup_uploads_both_objects_with_checksums_and_a_manifest(): void
    {
        $this->requires_mysql();

        $result = $this->run_backup('BACKUP_FULL', ['reason' => 'pre_update']);

        $this->assertSame('success', $result['status'], wp_json_encode($result['error']));
        $manifest = $this->server->manifest;
        $this->assertSame(['format', 'backup_uuid', 'type', 'installation_id', 'connector_version', 'wordpress_version', 'php_version', 'created_at', 'completed_at', 'site', 'files', 'database'], array_keys($manifest));
        $this->assertSame('full', $manifest['type']);
        foreach (['files', 'database'] as $name) {
            $bytes = $this->server->objects[$name];
            $this->assertSame(hash('sha256', $bytes), $manifest[$name]['sha256']);
            $this->assertSame(strlen($bytes), $manifest[$name]['size']);
            $this->assertSame(['size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)], $this->server->completed[$name]);
        }
        $this->assertSame($result['data']['backup']['uuid'], $manifest['backup_uuid']);
        $this->assertNotNull(BackupIndex::find($manifest['backup_uuid']), 'Recorded locally for restore checks.');
    }

    public function test_large_objects_are_sent_in_checksummed_chunks(): void
    {
        $this->put(WP_CONTENT_DIR.'/uploads/random.bin', random_bytes(3 * 1024 * 1024));

        $this->run_backup('BACKUP_FILES', ['chunk_size' => 1048576]);

        $chunks = $this->http->api_requests('PUT backups/*/objects/files');
        $this->assertGreaterThanOrEqual(3, count($chunks));
        foreach ($chunks as $i => $chunk) {
            $this->assertLessThanOrEqual(1048576, strlen($chunk['raw']));
            $this->assertSame(hash('sha256', $chunk['raw']), $chunk['headers']['X-Chunk-SHA256']);
            $this->assertSame('application/octet-stream', $chunk['headers']['Content-Type']);
            $this->assertSame($i * 1048576, (int) $chunk['query']['offset']);
        }
    }

    public function test_temporary_upload_failures_are_retried(): void
    {
        $this->server->failing_chunks = 3;

        $result = $this->run_backup('BACKUP_FILES');

        $this->assertSame('success', $result['status']);
        $this->assertSame(hash('sha256', $this->server->objects['files']), $this->server->manifest['files']['sha256']);
    }

    public function test_upload_that_keeps_failing_fails_the_job_without_looping(): void
    {
        $this->server->failing_chunks = 1000;

        $result = $this->run_backup('BACKUP_FILES');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('UPLOAD_FAILED', $result['result_code']);
        $this->assertCount(7, $this->http->api_requests('PUT backups/*/objects/files'), 'One try and six retries.');
        $this->assertSame(0, $this->server->finalized);
        $this->assertSame([], $this->temp_files());
    }

    public function test_upload_resumes_from_the_offset_the_server_has(): void
    {
        $this->put(WP_CONTENT_DIR.'/uploads/random.bin', random_bytes(2 * 1024 * 1024 + 10));
        $job = $this->job('BACKUP_FILES', $this->backup_payload(['chunk_size' => 1048576]));
        $handler = $this->plugin()->registry()->handler('BACKUP_FILES');
        $payload = $handler->validate((array) $job['payload']);

        // The process built the archive, uploaded one chunk, then died.
        $handler->prepare($payload);
        $this->plugin()->jobs()->begin($job['uuid'], 'BACKUP_FILES', $payload);
        $path = TempDir::file($payload['backup_uuid'], 'files.tar.gz');
        $this->server->objects['files'] = (string) file_get_contents($path, false, null, 0, 1048576);
        $built_at = filemtime($path);

        $this->serve_jobs($job);
        $this->plugin()->runner()->poll();

        $result = $this->posted_result($job['uuid']);
        $this->assertSame('success', $result['status'], wp_json_encode($result['error']));
        $this->assertSame(hash('sha256', $this->server->objects['files']), $this->server->manifest['files']['sha256']);
        $offsets = array_map(fn ($r) => (int) $r['query']['offset'], $this->http->api_requests('PUT backups/*/objects/files'));
        $this->assertSame(1048576, $offsets[0], 'Continued where the server was.');
        $this->assertSame([], $this->temp_files());
        $this->assertGreaterThanOrEqual($built_at, time());
    }

    public function test_interrupted_backup_that_cannot_resume_is_reported_not_rerun(): void
    {
        $job = $this->job('BACKUP_FILES', $this->backup_payload());
        $this->plugin()->jobs()->begin($job['uuid'], 'BACKUP_FILES'); // Died while building.
        $this->serve_jobs($job);

        $this->plugin()->runner()->poll();

        $this->assertSame('JOB_INTERRUPTED', $this->posted_result($job['uuid'])['result_code']);
        $this->assertSame([], $this->server->objects);
    }

    public function test_duplicate_backup_job_is_built_once(): void
    {
        $job = $this->job('BACKUP_FILES', $this->backup_payload());
        $this->http->on('GET jobs', ['status' => 200, 'body' => ['jobs' => [$job, $job]]], ['status' => 200, 'body' => ['jobs' => [$job]]]);
        $this->http->on('POST jobs/*/result', ['status' => 202, 'body' => []]);

        $this->plugin()->runner()->poll();
        $this->plugin()->runner()->poll();

        $this->assertSame(1, $this->server->finalized);
    }

    public function test_insufficient_disk_space_stops_before_writing(): void
    {
        add_filter('wp_caregrid_backup_free_space', fn () => 1024);

        $result = $this->run_backup('BACKUP_FILES');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('INSUFFICIENT_DISK_SPACE', $result['result_code']);
        $this->assertSame([], $this->server->objects);
        $this->assertSame([], $this->temp_files());
    }

    public function test_temporary_files_are_private_and_removed(): void
    {
        $dir = TempDir::ensure();
        $this->assertFalse(TempDir::inside($dir, ABSPATH), 'Outside the web root when the system temp directory allows it.');
        $this->assert_mode('0700', $dir);

        $this->run_backup('BACKUP_FILES');
        $this->assertSame([], $this->temp_files(), 'Removed after a successful upload.');

        // Leftovers of a job that is not running any more are removed at the next poll.
        $stale = $dir.'/'.wp_generate_uuid4().'-files.tar.gz';
        file_put_contents($stale, 'x');
        touch($stale, time() - 7 * HOUR_IN_SECONDS);
        $this->http->on('GET jobs', ['status' => 200, 'body' => ['jobs' => []]]);
        $this->plugin()->runner()->poll();
        $this->assertFileDoesNotExist($stale);
    }

    public function test_invalid_backup_payloads_are_refused(): void
    {
        foreach ([
            ['chunk_size' => 1],
            ['reason' => 'because'],
            ['backup_uuid' => '../../etc'],
            ['path' => '/var/www'],
            ['storage' => 's3://bucket', 'key' => 'AKIA'],
        ] as $override) {
            $job = $this->job('BACKUP_FILES', array_merge($this->backup_payload(), $override));
            $this->serve_jobs($job);
            $this->plugin()->runner()->poll();
            $this->assertSame('INVALID_PAYLOAD', $this->posted_result($job['uuid'])['result_code'], wp_json_encode($override));
        }

        $this->assertSame([], $this->server->objects);
    }

    public function test_unsupported_backup_types_are_refused(): void
    {
        $jobs = [$this->job('BACKUP_EVERYTHING', $this->backup_payload()), $this->job('BACKUP_SERVER', $this->backup_payload())];
        if (! $this->mysql()) {
            $jobs[] = $db = $this->job('BACKUP_DATABASE', $this->backup_payload());
        }
        $this->serve_jobs(...$jobs);

        $this->plugin()->runner()->poll();

        $this->assertSame('UNSUPPORTED_JOB_TYPE', $this->posted_result($jobs[0]['uuid'])['result_code']);
        $this->assertSame('UNSUPPORTED_JOB_TYPE', $this->posted_result($jobs[1]['uuid'])['result_code']);
        if (isset($db)) {
            $this->assertSame('CAPABILITY_UNAVAILABLE', $this->posted_result($db['uuid'])['result_code'], 'No database backups without MySQL.');
        }
    }

    public function test_backups_never_leak_the_credential(): void
    {
        $this->run_backup($this->mysql() ? 'BACKUP_FULL' : 'BACKUP_FILES');

        $everything = implode('', array_map(fn ($bytes) => (string) (@gzdecode($bytes) ?: $bytes), $this->server->objects))
            .wp_json_encode($this->server->manifest).wp_json_encode(Logger::entries());

        $this->assertStringNotContainsString(self::CREDENTIAL, $everything);
        foreach ($this->http->api_requests() as $request) {
            if (! str_starts_with((string) $request['path'], 'backups/')) {
                continue;
            }
            $this->assertStringNotContainsString(self::CREDENTIAL, $request['raw'].wp_json_encode($request['body']));
        }
    }

    /* ----- helpers ----- */

    private function run_backup(string $type, array $payload = []): array
    {
        $job = $this->job($type, $this->backup_payload($payload));
        $this->serve_jobs($job);
        $this->plugin()->runner()->poll();

        return $this->posted_result($job['uuid']) ?? $this->fail('No result posted.');
    }

    private function backup_payload(array $override = []): array
    {
        return array_merge(['backup_uuid' => wp_generate_uuid4(), 'reason' => 'manual', 'chunk_size' => 4194304], $override);
    }

    /** @return array<string, string> entry name => content */
    private function entries(string $archive): array
    {
        $path = tempnam(sys_get_temp_dir(), 'cgtar');
        file_put_contents($path, $archive);
        $entries = [];
        (new TarReader($path))->each(function (string $name, string $type, int $size, callable $read) use (&$entries) {
            $content = '';
            while (($piece = $read()) !== '') {
                $content .= $piece;
            }
            $entries[$name] = $content;
        });
        unlink($path);

        return $entries;
    }

    private function put(string $path, string $content): void
    {
        wp_mkdir_p(dirname($path));
        file_put_contents($path, $content);
        $this->created[] = $path;
        foreach (['/cache', '/uploads/wc-logs', '/ai1wm-backups', '/plugins/big-plugin', '/uploads/2026', '/uploads/long-folder-name'] as $dir) {
            if (str_starts_with($path, WP_CONTENT_DIR.$dir)) {
                $this->created[] = WP_CONTENT_DIR.$dir;
            }
        }
    }

    private function temp_files(): array
    {
        return array_values(preg_grep('/^[0-9a-f-]{36}-/', array_map('basename', (array) glob(TempDir::path().'/*'))));
    }

    private function mysql(): bool
    {
        return DatabaseDumper::supported();
    }

    private function requires_mysql(): void
    {
        if (! $this->mysql()) {
            $this->markTestSkipped('Needs MySQL/MariaDB: run with WP_CAREGRID_TEST_MYSQL=user:password@host/database.');
        }
    }
}
