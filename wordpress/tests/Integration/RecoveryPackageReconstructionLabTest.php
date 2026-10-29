Attempting to perform the InitializeDefaultDrives operation on the 'FileSystem' provider failed.
<?php

namespace WPCareGrid\Connector\Tests\Integration;

use WPCareGrid\Connector\Backups\BackupFailure;
use WPCareGrid\Connector\Backups\DatabaseDumper;
use WPCareGrid\Connector\Backups\TarReader;
use WPCareGrid\Connector\Backups\Uploader;
use WPCareGrid\Connector\Tests\Support\FakeBackupServer;
use WPCareGrid\Connector\Tests\Support\TestCase;

/**
 * Synthetic manual-recovery laboratory. It generates packages through the
 * real Connector BACKUP_* jobs, then reconstructs only into a fresh, private
 * WordPress tree and a per-test database created on the loopback test server.
 * It deliberately never calls Connector RESTORE code or seeds BackupIndex.
 */
final class RecoveryPackageReconstructionLabTest extends TestCase
{
    private FakeBackupServer $server;

    /** @var list<string> */
    private array $ownedPaths = [];

    /** @var list<array{host: string, database: string, username: string}> */
    private array $ownedDatabases = [];

    /** @var array<string, mixed> */
    private array $originalOptions = [];

    private bool $sourceConfigExisted = false;

    private ?string $sourceConfigOriginal = null;

    private int $sourceConfigMode = 0600;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pair();
        $this->server = (new FakeBackupServer($this->http))->register();
        $this->plugin()->set('uploader', new Uploader($this->plugin()->api(), fn () => null));
        delete_option('wp_caregrid_backup_runs');
        delete_option('wp_caregrid_backup_index');
    }

    protected function tearDown(): void
    {
        $sourceConfig = ABSPATH.'wp-config.php';
        if ($this->sourceConfigExisted && is_string($this->sourceConfigOriginal)) {
            file_put_contents($sourceConfig, $this->sourceConfigOriginal, LOCK_EX);
            @chmod($sourceConfig, $this->sourceConfigMode);
        } elseif (is_file($sourceConfig) && ! is_link($sourceConfig)) {
            @unlink($sourceConfig);
        }
        foreach ($this->originalOptions as $name => $value) {
            if ($value === false) {
                delete_option($name);
            } else {
                update_option($name, $value);
            }
        }
        delete_transient('recovery_lab_transient');
        foreach (array_reverse($this->ownedDatabases) as $database) {
            $this->dropDisposableDatabase($database);
        }
        foreach (array_reverse($this->ownedPaths) as $path) {
            $this->removeTree($path);
        }
        parent::tearDown();
    }

    public function test_full_package_integrity_reconstruction_and_wordpress_application_recovery(): void
    {
        $this->requires_mysql_and_gzip();
        $this->writeSourceSentinels();
        $package = $this->generatePackage('full');
        $lab = $this->preparePackage($package);

        $this->assertSame('PASS', $lab['package_integrity']);
        $this->assertSame(['database', 'files'], $lab['components']);
        $this->assertRedactedSourceConfig($lab);
        $target = $this->createTarget((string) $package['manifest']['database']['table_prefix']);
        $this->importSql($target, $lab['sql_statements']);
        $this->applyFiles($target['root'], $lab['entries']);
        $this->deactivateConnectorForLab($target, (string) $package['manifest']['database']['table_prefix']);

        $result = $this->probeTarget($target, [
            'expect_installed' => true,
            'expect_file_sentinel' => true,
            'expect_database_sentinel' => true,
            'has_sql' => true,
            'has_files' => true,
            'url' => 'https://recovery-lab.invalid',
        ]);

        $this->assertSame('PASS', $result['sql_reconstruction']);
        $this->assertSame('PASS', $result['files_reconstruction']);
        $this->assertSame('PASS', $result['wordpress_application_recovery']);
        $this->assertSame('RECOVERY-LAB-FILE-SENTINEL', $result['file_sentinel']);
        $this->assertSame('RECOVERY-LAB-DATABASE-SENTINEL', $result['database_sentinel']);
        $this->assertTrue($target['generated_config_sha256'] === hash_file('sha256', $target['root'].'/wp-config.php'), 'A redacted source wp-config.php must never replace target-generated credentials.');
        $this->assertSame(0, $result['active_connector_count'], 'Manual recovery does not activate the Connector.');
        $this->assertTrue($result['functional_post']);
    }

    public function test_files_only_package_restores_files_but_does_not_claim_full_recovery(): void
    {
        $this->requires_mysql_and_gzip();
        $this->writeSourceSentinels();
        $package = $this->generatePackage('files');
        $lab = $this->preparePackage($package);

        $this->assertSame('PASS', $lab['package_integrity']);
        $this->assertSame(['files'], $lab['components']);
        $this->assertRedactedSourceConfig($lab);
        $target = $this->createTarget('wp_');
        $this->installFreshWordPress($target);
        $this->applyFiles($target['root'], $lab['entries']);

        $result = $this->probeTarget($target, [
            'expect_installed' => true,
            'expect_file_sentinel' => true,
            'expect_database_sentinel' => false,
            'has_sql' => false,
            'has_files' => true,
            'url' => 'https://recovery-lab.invalid',
        ]);

        $this->assertSame('NOT INCLUDED', $result['sql_reconstruction']);
        $this->assertSame('PASS', $result['files_reconstruction']);
        $this->assertSame('NOT CLAIMED — FILES-ONLY PACKAGE', $result['wordpress_application_recovery']);
        $this->assertSame('RECOVERY-LAB-FILE-SENTINEL', $result['file_sentinel']);
        $this->assertNull($result['database_sentinel']);
        $this->assertSame(0, $result['active_connector_count']);
        $this->assertSame($target['generated_config_sha256'], hash_file('sha256', $target['root'].'/wp-config.php'));
        $this->assertTrue($result['functional_post']);
    }

    public function test_database_only_package_reconstructs_database_but_does_not_claim_full_recovery(): void
    {
        $this->requires_mysql_and_gzip();
        $this->writeSourceSentinels();
        $package = $this->generatePackage('database');
        $lab = $this->preparePackage($package);

        $this->assertSame('PASS', $lab['package_integrity']);
        $this->assertSame(['database'], $lab['components']);
        $target = $this->createTarget((string) $package['manifest']['database']['table_prefix']);
        $this->importSql($target, $lab['sql_statements']);
        $this->deactivateConnectorForLab($target, (string) $package['manifest']['database']['table_prefix']);

        $result = $this->probeTarget($target, [
            'expect_installed' => true,
            'expect_file_sentinel' => false,
            'expect_database_sentinel' => true,
            'has_sql' => true,
            'has_files' => false,
            'url' => 'https://recovery-lab.invalid',
        ]);

        $this->assertSame('PASS', $result['sql_reconstruction']);
        $this->assertSame('NOT INCLUDED', $result['files_reconstruction']);
        $this->assertSame('NOT CLAIMED — DATABASE-ONLY PACKAGE', $result['wordpress_application_recovery']);
        $this->assertNull($result['file_sentinel']);
        $this->assertSame('RECOVERY-LAB-DATABASE-SENTINEL', $result['database_sentinel']);
        $this->assertSame(0, $result['active_connector_count']);
        $this->assertTrue($result['functional_post']);
    }

    public function test_invalid_manifests_objects_and_archives_are_rejected_before_target_writes(): void
    {
        $this->requires_mysql_and_gzip();
        $package = $this->generatePackage('full');
        $target = $this->temporaryDirectory('target-untouched');
        file_put_contents($target.'/guard.txt', 'UNCHANGED-TARGET');
        $guard = hash_file('sha256', $target.'/guard.txt');

        $cases = [];
        $missing = $package;
        unset($missing['objects']['files']);
        $cases['missing-object'] = $missing;
        $corrupt = $package;
        $corrupt['objects']['database'][0] = chr(ord($corrupt['objects']['database'][0]) ^ 1);
        $cases['corrupt-object'] = $corrupt;
        $wrongSize = $package;
        $wrongSize['manifest']['files']['size']++;
        $cases['object-size-mismatch'] = $wrongSize;
        $extra = $package;
        $extra['objects']['unexpected'] = 'not-allowed';
        $cases['unexpected-component'] = $extra;
        $wrongType = $package;
        $wrongType['manifest']['type'] = 'files';
        $cases['incompatible-type'] = $wrongType;
        $unexpectedManifest = $package;
        $unexpectedManifest['manifest']['unreviewed_component'] = true;
        $cases['unexpected-manifest-field'] = $unexpectedManifest;
        $wrongVersion = $package;
        $wrongVersion['manifest']['database']['server_version'] = '999.0.0';
        $cases['incompatible-database-version'] = $wrongVersion;

        foreach ($cases as $case) {
            try {
                $this->preparePackage($case);
                $this->fail('Invalid package was accepted.');
            } catch (\InvalidArgumentException|BackupFailure) {
                $this->assertSame($guard, hash_file('sha256', $target.'/guard.txt'));
                $this->assertSame(['guard.txt'], array_values(array_diff(scandir($target), ['.', '..'])));
            }
        }

        $unsafeSql = $package;
        $sql = (string) gzdecode($unsafeSql['objects']['database']);
        $unsafeSql['objects']['database'] = gzencode($sql."DROP DATABASE `wordpress`;\n");
        $unsafeSql['manifest']['database']['size'] = strlen($unsafeSql['objects']['database']);
        $unsafeSql['manifest']['database']['sha256'] = hash('sha256', $unsafeSql['objects']['database']);
        try {
            $this->preparePackage($unsafeSql);
            $this->fail('Unsupported SQL was accepted.');
        } catch (BackupFailure) {
            $this->assertSame($guard, hash_file('sha256', $target.'/guard.txt'));
        }

        $wrongCharset = $package;
        $wrongCharset['manifest']['database']['charset'] = 'latin1';
        try {
            $this->preparePackage($wrongCharset);
            $this->fail('A SQL/manifest charset mismatch was accepted.');
        } catch (BackupFailure|\InvalidArgumentException) {
            $this->assertSame($guard, hash_file('sha256', $target.'/guard.txt'));
        }

        foreach ([
            'unsafe-path' => [['../escape.txt', 'bad', '0']],
            'absolute-path' => [['/etc/passwd', 'synthetic', '0']],
            'drive-path' => [['C:/Windows/test.txt', 'synthetic', '0']],
            'duplicate-entry' => [['wp-content/uploads/recovery-lab.txt', 'one', '0'], ['wp-content/uploads/recovery-lab.txt', 'two', '0']],
            'link-entry' => [['wp-content/uploads/link', '', '2']],
            'unexpected-core-entry' => [['wp-admin/index.php', '<?php /* synthetic */', '0']],
        ] as $name => $entries) {
            $badArchive = $package;
            $bytes = $this->tarGz($entries);
            $badArchive['objects']['files'] = $bytes;
            $badArchive['manifest']['files']['size'] = strlen($bytes);
            $badArchive['manifest']['files']['sha256'] = hash('sha256', $bytes);
            try {
                $this->preparePackage($badArchive);
                $this->fail("Invalid {$name} archive was accepted.");
            } catch (BackupFailure) {
                $this->assertSame($guard, hash_file('sha256', $target.'/guard.txt'));
            }
        }

        $incompatible = $package;
        $incompatible['manifest']['database']['table_prefix'] = 'other_';
        try {
            $this->preparePackage($incompatible);
            $this->fail('A prefix mismatch was accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertSame($guard, hash_file('sha256', $target.'/guard.txt'));
        }
    }

    private function writeSourceSentinels(): void
    {
        if (! array_key_exists('caregrid_raw_value', $this->originalOptions)) {
            $this->originalOptions['caregrid_raw_value'] = get_option('caregrid_raw_value', false);
        }
        $path = WP_CONTENT_DIR.'/uploads/recovery-lab-sentinel.txt';
        wp_mkdir_p(dirname($path));
        file_put_contents($path, 'RECOVERY-LAB-FILE-SENTINEL');
        $this->ownedPaths[] = $path;
        update_option('caregrid_raw_value', 'RECOVERY-LAB-DATABASE-SENTINEL');
        foreach (['wp_caregrid_state', 'wp_caregrid_jobs'] as $name) {
            if (! array_key_exists($name, $this->originalOptions)) {
                $this->originalOptions[$name] = get_option($name, false);
            }
        }
        $state = get_option('wp_caregrid_state', []);
        $state = is_array($state) ? $state : [];
        $state['recovery_lab_sentinel'] = 'CONNECTOR-STATE-MUST-NOT-BE-PACKAGED';
        update_option('wp_caregrid_state', $state, false);
        $jobs = get_option('wp_caregrid_jobs', []);
        $jobs = is_array($jobs) ? $jobs : [];
        $jobs['recovery-lab-synthetic-job'] = ['type' => 'LAB_SENTINEL', 'state' => 'completed', 'delivered' => true, 'payload' => 'CONNECTOR-JOBS-MUST-NOT-BE-PACKAGED'];
        update_option('wp_caregrid_jobs', $jobs, false);
        set_transient('recovery_lab_transient', 'TRANSIENT-MUST-NOT-BE-PACKAGED');
        $config = ABSPATH.'wp-config.php';
        if (is_link($config)) {
            throw new \RuntimeException('Refusing to replace a linked source wp-config fixture.');
        }
        if (is_file($config)) {
            $this->sourceConfigExisted = true;
            $contents = file_get_contents($config);
            if (! is_string($contents)) {
                throw new \RuntimeException('Unable to preserve the existing source wp-config fixture.');
            }
            $this->sourceConfigOriginal = $contents;
            $this->sourceConfigMode = fileperms($config) & 0777;
        }
        $syntheticConfig = "<?php\n".
            "define( 'DB_NAME', 'recovery_lab_source' );\n".
            "define( 'DB_USER', 'RECOVERY-LAB-SOURCE-DB-USER' );\n".
            "define( 'DB_PASSWORD', 'RECOVERY-LAB-SOURCE-DB-PASSWORD' );\n".
            "define( 'AUTH_KEY', 'RECOVERY-LAB-SOURCE-AUTH-KEY' );\n".
            "define( 'SECURE_AUTH_SALT', 'RECOVERY-LAB-SOURCE-SECURE-SALT' );\n".
            "\$table_prefix = 'wp_';\n";
        if (file_put_contents($config, $syntheticConfig, LOCK_EX) !== strlen($syntheticConfig)) {
            throw new \RuntimeException('Unable to create synthetic source wp-config fixture.');
        }
    }

    /** @return array{manifest: array, objects: array<string, string>} */
    private function generatePackage(string $type): array
    {
        if (! in_array($type, ['full', 'files', 'database'], true)) {
            throw new \InvalidArgumentException('Unsupported synthetic package type.');
        }
        if ($type === 'database' || $type === 'full') {
            $this->requires_mysql_and_gzip();
        }
        $job = $this->job('BACKUP_'.strtoupper($type), [
            'backup_uuid' => wp_generate_uuid4(),
            'reason' => 'manual',
            'chunk_size' => 4194304,
        ]);
        $this->serve_jobs($job);
        $this->plugin()->runner()->poll();
        $result = $this->posted_result($job['uuid']);
        $this->assertSame('success', $result['status'] ?? null, wp_json_encode($result['error'] ?? []));
        $this->assertSame(1, $this->server->finalized, 'The actual Connector backup handler must finalize the synthetic artifact.');
        $package = ['manifest' => $this->server->manifest, 'objects' => $this->server->objects];
        $this->assertSame('wp-caregrid-backup/1', $package['manifest']['format']);
        $this->assertSame($type, $package['manifest']['type']);
        if (isset($package['objects']['database'])) {
            $dump = gzdecode($package['objects']['database']);
            $this->assertIsString($dump);
            $this->assertStringContainsString('caregrid_raw_value', $dump);
            $this->assertStringNotContainsString('CONNECTOR-STATE-MUST-NOT-BE-PACKAGED', $dump);
            $this->assertStringNotContainsString('CONNECTOR-JOBS-MUST-NOT-BE-PACKAGED', $dump);
            $this->assertStringNotContainsString('TRANSIENT-MUST-NOT-BE-PACKAGED', $dump);
        }

        return $package;
    }

    /** Validate all bytes and metadata before creating or changing the target. */
    private function preparePackage(array $package): array
    {
        $manifest = $package['manifest'] ?? null;
        $objects = $package['objects'] ?? null;
        if (! is_array($manifest) || ! is_array($objects)
            || ($manifest['format'] ?? null) !== 'wp-caregrid-backup/1'
            || ! in_array($manifest['type'] ?? null, ['full', 'files', 'database'], true)
            || ! is_string($manifest['backup_uuid'] ?? null)
            || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $manifest['backup_uuid'])
            || ! is_array($manifest['site'] ?? null)) {
            throw new \InvalidArgumentException('Package manifest identity is invalid.');
        }
        $siteKeys = array_keys($manifest['site']);
        sort($siteKeys);
        if ($siteKeys !== ['multisite', 'table_prefix'] || ! is_bool($manifest['site']['multisite'] ?? null)
            || ! is_string($manifest['site']['table_prefix'] ?? null)) {
            throw new \InvalidArgumentException('Site manifest identity is invalid.');
        }
        $requiredManifestKeys = ['backup_uuid', 'completed_at', 'connector_version', 'created_at', 'format', 'installation_id', 'php_version', 'site', 'type', 'wordpress_version'];
        if (in_array($manifest['type'], ['full', 'files'], true)) {
            $requiredManifestKeys[] = 'files';
        }
        if (in_array($manifest['type'], ['full', 'database'], true)) {
            $requiredManifestKeys[] = 'database';
        }
        $manifestKeys = array_keys($manifest);
        sort($requiredManifestKeys);
        sort($manifestKeys);
        if ($manifestKeys !== $requiredManifestKeys) {
            throw new \InvalidArgumentException('Manifest contains missing or unexpected fields.');
        }
        foreach (['installation_id', 'connector_version', 'wordpress_version', 'php_version', 'created_at', 'completed_at'] as $field) {
            if (! is_string($manifest[$field] ?? null) || $manifest[$field] === '') {
                throw new \InvalidArgumentException("Manifest field {$field} is invalid.");
            }
        }

        $expected = match ($manifest['type']) {
            'full' => ['database', 'files'],
            'files' => ['files'],
            'database' => ['database'],
        };
        $actual = array_keys($objects);
        sort($expected);
        sort($actual);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException('Package has missing or unexpected components.');
        }
        foreach ($expected as $name) {
            $metadata = $manifest[$name] ?? null;
            $bytes = $objects[$name] ?? null;
            if (! is_array($metadata) || ! is_string($bytes)
                || ! is_int($metadata['size'] ?? null) || $metadata['size'] !== strlen($bytes)
                || ! is_string($metadata['sha256'] ?? null)
                || ! hash_equals($metadata['sha256'], hash('sha256', $bytes))) {
                throw new \InvalidArgumentException("{$name} object size or SHA-256 does not match the manifest.");
            }
        }
        if (isset($manifest['files'])) {
            if (($manifest['files']['compression'] ?? null) !== 'gzip'
                || ($manifest['files']['format'] ?? null) !== 'ustar'
                || ($manifest['files']['policy'] ?? null) !== 'standard-1'
                || ! in_array($manifest['files']['wp_config'] ?? null, ['redacted', 'excluded'], true)) {
                throw new \InvalidArgumentException('Files package metadata is incompatible.');
            }
            $requiredFileKeys = ['size', 'sha256', 'compression', 'format', 'file_count', 'uncompressed_size', 'policy', 'wp_config', 'skipped'];
            $fileKeys = array_keys($manifest['files']);
            sort($requiredFileKeys);
            sort($fileKeys);
            if ($fileKeys !== $requiredFileKeys
                || ! is_int($manifest['files']['file_count'] ?? null)
                || ! is_int($manifest['files']['uncompressed_size'] ?? null)
                || ! is_array($manifest['files']['skipped'] ?? null)) {
                throw new \InvalidArgumentException('Files manifest has missing or unexpected metadata.');
            }
            $skippedKeys = array_keys($manifest['files']['skipped']);
            sort($skippedKeys);
            if ($skippedKeys !== ['excluded', 'symlinks', 'too_large', 'unreadable']) {
                throw new \InvalidArgumentException('Files manifest skip inventory is invalid.');
            }
            $expanded = gzdecode($objects['files'], 64 * 1024 * 1024 + 1);
            if (! is_string($expanded) || strlen($expanded) !== $manifest['files']['uncompressed_size']) {
                throw new \InvalidArgumentException('Files archive expanded size does not match the manifest.');
            }
        }
        if (isset($manifest['database'])) {
            global $wpdb;
            $sourceVersion = preg_match('/([0-9]+\\.[0-9]+)/', (string) $wpdb->db_server_info(), $matches) ? $matches[1] : null;
            $packageVersion = preg_match('/([0-9]+\\.[0-9]+)/', (string) ($manifest['database']['server_version'] ?? ''), $versionMatches) ? $versionMatches[1] : null;
            $sourceServer = (string) $wpdb->db_server_info();
            $sourceEngine = stripos($sourceServer, 'mariadb') === false ? 'mysql' : 'mariadb';
            if ($sourceVersion === null || $packageVersion !== $sourceVersion || ($manifest['database']['engine'] ?? null) !== $sourceEngine) {
                throw new \InvalidArgumentException('Database package server version is incompatible with the disposable lab target.');
            }
            $databasePrefix = (string) ($manifest['database']['table_prefix'] ?? '');
            if (! preg_match('/^[A-Za-z0-9_]+$/D', $databasePrefix)
                || $databasePrefix === ''
                || ! in_array($manifest['database']['compression'] ?? null, ['gzip', 'none'], true)
                || ! in_array($manifest['database']['engine'] ?? null, ['mysql', 'mariadb'], true)
                || ! preg_match('/^[A-Za-z0-9_]+$/D', (string) ($manifest['database']['charset'] ?? ''))
                || ($manifest['database']['excluded'] ?? null) !== ['transients', 'connector_state']
                || ! is_int($manifest['database']['tables'] ?? null)
                || ! is_int($manifest['database']['rows'] ?? null)
                || ($manifest['site']['table_prefix'] ?? null) !== $databasePrefix) {
                throw new \InvalidArgumentException('Database package metadata is incompatible.');
            }
            $requiredDatabaseKeys = ['size', 'sha256', 'compression', 'engine', 'server_version', 'charset', 'table_prefix', 'tables', 'rows', 'excluded'];
            $databaseKeys = array_keys($manifest['database']);
            sort($requiredDatabaseKeys);
            sort($databaseKeys);
            if ($databaseKeys !== $requiredDatabaseKeys) {
                throw new \InvalidArgumentException('Database manifest has missing or unexpected metadata.');
            }
        }

        $entries = [];
        if (isset($objects['files'])) {
            $archive = $this->temporaryFile($objects['files'], '.tar.gz');
            $stage = $this->temporaryDirectory('archive-stage');
            $fileCount = 0;
            (new TarReader($archive, 2000, 64 * 1024 * 1024, 32, 10000, 1024 * 1024, 16 * 1024 * 1024, 32 * 1024 * 1024))->each(function (string $name, string $type, int $size, callable $read) use (&$entries, &$fileCount, $stage, $manifest): void {
                $this->assertAllowedArchiveName($name, $type);
                if ($name === 'root/wp-config.php' && ($manifest['files']['wp_config'] ?? null) !== 'redacted') {
                    throw new BackupFailure('RECOVERY_PACKAGE_INVALID', 'A wp-config archive member conflicts with manifest redaction state.');
                }
                $path = $stage.'/'.$name;
                if ($type === 'file') {
                    $fileCount++;
                    wp_mkdir_p(dirname($path));
                    $handle = fopen($path, 'xb');
                    if (! $handle) {
                        throw new BackupFailure('RECOVERY_PACKAGE_INVALID', 'Unable to stage archive entry safely.');
                    }
                    $remaining = 0;
                    while (($chunk = $read()) !== '') {
                        if (fwrite($handle, $chunk) !== strlen($chunk)) {
                            fclose($handle);
                            throw new BackupFailure('RECOVERY_PACKAGE_INVALID', 'Unable to stage archive entry completely.');
                        }
                        $remaining += strlen($chunk);
                    }
                    fclose($handle);
                    if ($remaining !== $size) {
                        throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'Archive entry size mismatch.');
                    }
                } else {
                    wp_mkdir_p($path);
                }
                $entries[] = ['name' => $name, 'type' => $type, 'size' => $size, 'staged_path' => $path];
            });
            if ($fileCount !== ($manifest['files']['file_count'] ?? null)) {
                throw new \InvalidArgumentException('Archive file inventory disagrees with the manifest.');
            }
        }

        $statements = [];
        if (isset($objects['database'])) {
            $sqlBytes = $objects['database'];
            if (strlen($sqlBytes) > 16 * 1024 * 1024) {
                throw new BackupFailure('RECOVERY_PACKAGE_INVALID', 'Compressed database object exceeds the lab limit.');
            }
            if (($manifest['database']['compression'] ?? null) === 'gzip') {
                $sqlBytes = gzdecode($sqlBytes, 128 * 1024 * 1024 + 1);
                if (! is_string($sqlBytes)) {
                    throw new BackupFailure('RECOVERY_PACKAGE_INVALID', 'Database object compression is invalid.');
                }
            }
            $statements = $this->validateGeneratedSql($sqlBytes, (string) $manifest['database']['table_prefix'], (string) $manifest['database']['charset'], (int) $manifest['database']['tables']);
        }

        return [
            'package_integrity' => 'PASS',
            'components' => $expected,
            'entries' => $entries,
            'sql_statements' => $statements,
        ];
    }

    private function assertAllowedArchiveName(string $name, string $type): void
    {
        if ($name === 'wp-content' && $type === 'dir') {
            return;
        }
        if (str_starts_with($name, 'wp-content/')) {
            return;
        }
        if (str_starts_with($name, 'root/') && $type === 'file') {
            $rootName = substr($name, 5);
            if (in_array($rootName, ['.htaccess', '.user.ini', 'web.config', 'robots.txt', 'wp-config.php'], true)) {
                return;
            }
        }
        throw new BackupFailure('RECOVERY_PACKAGE_INVALID', 'Archive contains an unexpected component.');
    }

    private function assertRedactedSourceConfig(array $lab): void
    {
        $matches = array_values(array_filter($lab['entries'], fn ($entry) => $entry['name'] === 'root/wp-config.php'));
        $this->assertCount(1, $matches);
        $this->assertSame('redacted', $this->server->manifest['files']['wp_config']);
        $bytes = file_get_contents($matches[0]['staged_path']);
        $this->assertIsString($bytes);
        $this->assertStringContainsString('removed-by-wp-caregrid', $bytes);
        foreach (['RECOVERY-LAB-SOURCE-DB-USER', 'RECOVERY-LAB-SOURCE-DB-PASSWORD', 'RECOVERY-LAB-SOURCE-AUTH-KEY', 'RECOVERY-LAB-SOURCE-SECURE-SALT'] as $secret) {
            $this->assertStringNotContainsString($secret, $bytes);
        }
    }

    /** Accept only the simple one-statement-per-line grammar emitted by DatabaseDumper. */
    private function validateGeneratedSql(string $sql, string $prefix, string $charset, int $tableCount): array
    {
        if ($sql === '' || strlen($sql) > 128 * 1024 * 1024) {
            throw new BackupFailure('RECOVERY_PACKAGE_INVALID', 'Database object is empty or exceeds the lab limit.');
        }
        $statements = [];
        $charsetDeclarationSeen = false;
        $createdTables = 0;
        foreach (preg_split('/\r?\n/', $sql) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '-- ')) {
                continue;
            }
            $this->assertOneSqlStatement($line);
            if (preg_match('/^(?:DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `([A-Za-z0-9_$]+)`/D', $line, $table)) {
                if (! str_starts_with($table[1], $prefix)) {
                    throw new BackupFailure('RECOVERY_PACKAGE_INVALID', 'SQL references a table outside the package prefix.');
                }
                if (str_starts_with($line, 'CREATE TABLE ')) {
                    $createdTables++;
                }
            } elseif (preg_match('/^SET NAMES ([A-Za-z0-9_]+);$/D', $line, $declaredCharset)) {
                if ($charsetDeclarationSeen || $declaredCharset[1] !== $charset) {
                    throw new BackupFailure('RECOVERY_PACKAGE_INVALID', 'SQL charset declaration conflicts with the manifest.');
                }
                $charsetDeclarationSeen = true;
            } elseif (! preg_match('/^SET FOREIGN_KEY_CHECKS = [01];$/D', $line)
                && ! preg_match("/^SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';$/D", $line)) {
                throw new BackupFailure('RECOVERY_PACKAGE_INVALID', 'Database object contains an unsupported SQL statement.');
            }
            $statements[] = $line;
        }
        if ($statements === [] || ! $charsetDeclarationSeen
            || $createdTables === 0 || $createdTables !== $tableCount) {
            throw new BackupFailure('RECOVERY_PACKAGE_INVALID', 'Database object has no table definitions.');
        }

        return $statements;
    }

    private function assertOneSqlStatement(string $line): void
    {
        if (! str_ends_with($line, ';')) {
            throw new BackupFailure('RECOVERY_PACKAGE_INVALID', 'SQL statement is incomplete.');
        }
        $quote = null;
        $escaped = false;
        $terminators = 0;
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($quote !== null && $char === '\\') {
                $escaped = true;
                continue;
            }
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '`') {
                $quote = $char;
            } elseif ($char === ';') {
                $terminators++;
                if ($i !== $length - 1) {
                    throw new BackupFailure('RECOVERY_PACKAGE_INVALID', 'SQL contains multiple or trailing statements.');
                }
            } elseif ($char === '#' || substr($line, $i, 2) === '/*'
                || (substr($line, $i, 2) === '--' && isset($line[$i + 2]) && ctype_space($line[$i + 2]))) {
                throw new BackupFailure('RECOVERY_PACKAGE_INVALID', 'SQL comments and executable comment forms are not accepted.');
            }
        }
        if ($quote !== null || $terminators !== 1) {
            throw new BackupFailure('RECOVERY_PACKAGE_INVALID', 'SQL quoting or statement boundary is invalid.');
        }
    }

    private function createTarget(string $prefix): array
    {
        $host = (string) DB_HOST;
        if (! in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            $this->markTestSkipped('Recovery lab refuses non-loopback database hosts.');
        }
        $admin = new \mysqli($host, DB_USER, DB_PASSWORD, DB_NAME);
        $database = 'cglab'.bin2hex(random_bytes(7));
        $username = 'cglab'.bin2hex(random_bytes(7));
        $password = bin2hex(random_bytes(24));
        $quotedDatabase = '`'.$database.'`';
        $quotedUser = "'{$username}'@'{$host}'";
        try {
            if (! $admin->query("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4")) {
                throw new \RuntimeException('Cannot create disposable lab database.');
            }
            if (! $admin->query("CREATE USER {$quotedUser} IDENTIFIED BY '".$admin->real_escape_string($password)."'")) {
                throw new \RuntimeException('Cannot create disposable lab database identity.');
            }
            if (! $admin->query("GRANT ALL PRIVILEGES ON {$quotedDatabase}.* TO {$quotedUser}")) {
                throw new \RuntimeException('Cannot grant database-scoped lab privileges.');
            }
        } catch (\Throwable $e) {
            $admin->query("DROP DATABASE IF EXISTS {$quotedDatabase}");
            $admin->query("DROP USER IF EXISTS {$quotedUser}");
            $admin->close();
            $this->markTestSkipped('MySQL test identity lacks disposable database/user provisioning grants.');
        }
        $admin->close();
        $this->ownedDatabases[] = ['host' => $host, 'database' => $database, 'username' => $username];

        $root = $this->temporaryDirectory('manual-recovery-target');
        $this->copyTree(ABSPATH, $root, true);
        wp_mkdir_p($root.'/wp-content/uploads');
        wp_mkdir_p($root.'/wp-content/plugins');
        $config = $this->writeTargetConfig($root, $database, $username, $password, $host, $prefix);

        return ['root' => $root, 'database' => $database, 'username' => $username, 'password' => $password, 'host' => $host, 'prefix' => $prefix, 'generated_config_sha256' => $config];
    }

    private function writeTargetConfig(string $root, string $database, string $username, string $password, string $host, string $prefix): string
    {
        $salts = [];
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $name) {
            $salts[] = "define('{$name}', '".bin2hex(random_bytes(32))."');";
        }
        $config = "<?php\n".
            "define('DB_NAME', ".var_export($database, true).");\n".
            "define('DB_USER', ".var_export($username, true).");\n".
            "define('DB_PASSWORD', ".var_export($password, true).");\n".
            "define('DB_HOST', ".var_export($host, true).");\n".
            "define('DB_CHARSET', 'utf8mb4');\ndefine('DB_COLLATE', '');\n".
            implode("\n", $salts)."\n".
            "define('WP_CONTENT_DIR', __DIR__.'/wp-content');\n".
            "define('WP_HTTP_BLOCK_EXTERNAL', true);\n".
            "define('DISABLE_WP_CRON', true);\n".
            "define('AUTOMATIC_UPDATER_DISABLED', true);\n".
            "define('WP_DEBUG', true);\ndefine('WP_DEBUG_DISPLAY', false);\n".
            '$table_prefix = '.var_export($prefix, true).";\n";
        file_put_contents($root.'/wp-config.php', $config, LOCK_EX);

        return hash('sha256', $config);
    }

    private function installFreshWordPress(array $target): void
    {
        $login = 'lab-'.bin2hex(random_bytes(6));
        $email = $login.'@invalid.test';
        $script = "<?php\nfunction wp_mail(...\$args): bool { return true; }\n\$_SERVER['HTTP_HOST']='recovery-lab.invalid';\n\$_SERVER['SERVER_NAME']='recovery-lab.invalid';\n\$_SERVER['REQUEST_URI']='/';\ndefine('WP_INSTALLING', true);\nrequire ".var_export($target['root'].'/wp-load.php', true).";\nrequire_once ABSPATH.'wp-admin/includes/upgrade.php';\n\$password=bin2hex(random_bytes(24));\n\$r=wp_install('Synthetic Recovery Lab',".var_export($login, true).','.var_export($email, true).",false,'',\$password);\nif(is_wp_error(\$r)||empty(\$r['user_id'])){fwrite(STDERR,'install failed');exit(2);}\nupdate_option('home','https://recovery-lab.invalid');\nupdate_option('siteurl','https://recovery-lab.invalid');\necho json_encode(['installed'=>true,'admin_password_generated'=>strlen(\$password)===48]);\n";
        $this->assertSame(['installed' => true, 'admin_password_generated' => true], $this->runTargetScript($target, $script));
    }

    private function importSql(array $target, array $statements): void
    {
        $db = new \mysqli($target['host'], $target['username'], $target['password'], $target['database']);
        $db->set_charset('utf8mb4');
        foreach ($statements as $statement) {
            if (! $db->query($statement)) {
                $db->close();
                $this->fail('Validated synthetic SQL import failed.');
            }
        }
        $db->close();
    }

    private function deactivateConnectorForLab(array $target, string $prefix): void
    {
        $db = new \mysqli($target['host'], $target['username'], $target['password'], $target['database']);
        $table = '`'.$prefix.'options`';
        foreach ([['active_plugins', 'a:0:{}'], ['home', 'https://recovery-lab.invalid'], ['siteurl', 'https://recovery-lab.invalid']] as [$name, $value]) {
            $n = $db->real_escape_string($name);
            $v = $db->real_escape_string($value);
            $db->query("UPDATE {$table} SET option_value='{$v}' WHERE option_name='{$n}'");
        }
        $db->close();
    }

    private function applyFiles(string $targetRoot, array $entries): void
    {
        foreach ($entries as $entry) {
            $name = $entry['name'];
            if (str_starts_with($name, 'root/')) {
                $relative = substr($name, 5);
                if ($relative === 'wp-config.php') {
                    continue; // Redacted config is reference material, never target credentials.
                }
                $target = $targetRoot.'/'.$relative;
            } else {
                $target = $targetRoot.'/'.$name;
            }
            if ($entry['type'] === 'dir') {
                wp_mkdir_p($target);
                continue;
            }
            wp_mkdir_p(dirname($target));
            if (! copy($entry['staged_path'], $target)) {
                throw new \RuntimeException('Unable to apply staged package file to disposable target.');
            }
        }
    }

    private function probeTarget(array $target, array $expect): array
    {
        $script = "<?php\nfunction wp_mail(...\$args): bool { return true; }\n\$_SERVER['HTTP_HOST']='recovery-lab.invalid';\n\$_SERVER['SERVER_NAME']='recovery-lab.invalid';\n\$_SERVER['REQUEST_URI']='/';\nrequire ".var_export($target['root'].'/wp-load.php', true).";\n\$db=\$wpdb;\n\$installed=is_blog_installed();\n\$file=file_exists(WP_CONTENT_DIR.'/uploads/recovery-lab-sentinel.txt') ? file_get_contents(WP_CONTENT_DIR.'/uploads/recovery-lab-sentinel.txt') : null;\n\$value=get_option('caregrid_raw_value');\n\$plugins=(array)get_option('active_plugins',[]);\nif(\$installed!==".($expect['expect_installed'] ? 'true' : 'false').") exit(3);\nif((\$file!==null)!==".($expect['expect_file_sentinel'] ? 'true' : 'false').") exit(4);\nif((\$value==='RECOVERY-LAB-DATABASE-SENTINEL')!==".($expect['expect_database_sentinel'] ? 'true' : 'false').") exit(5);\nif(get_option('home')!==".var_export($expect['url'], true).") exit(6);\necho json_encode(['installed'=>\$installed,'file_sentinel'=>\$file,'database_sentinel'=>\$value==='RECOVERY-LAB-DATABASE-SENTINEL'?\$value:null,'active_connector_count'=>count(array_filter(\$plugins,fn(\$p)=>str_contains((string)\$p,'wp-caregrid-connector')))]);\n";
        $result = $this->runTargetScript($target, $script);
        $functionalScript = "<?php\nfunction wp_mail(...\$args): bool { return true; }\n\$_SERVER['HTTP_HOST']='recovery-lab.invalid';\n\$_SERVER['SERVER_NAME']='recovery-lab.invalid';\n\$_SERVER['REQUEST_URI']='/';\nrequire ".var_export($target['root'].'/wp-load.php', true).";\n\$post=wp_insert_post(['post_title'=>'Recovery lab functionality sentinel','post_status'=>'publish','post_type'=>'post']);\necho json_encode(['post_read'=>!is_wp_error(\$post)&&get_post(\$post)!==null]);\n";
        $functionality = $this->runTargetScript($target, $functionalScript);

        return [
            'sql_reconstruction' => $expect['has_sql'] ? 'PASS' : 'NOT INCLUDED',
            'files_reconstruction' => $expect['has_files'] ? 'PASS' : 'NOT INCLUDED',
            'wordpress_application_recovery' => $expect['expect_file_sentinel'] && $expect['expect_database_sentinel'] ? 'PASS' : ($expect['expect_file_sentinel'] ? 'NOT CLAIMED — FILES-ONLY PACKAGE' : 'NOT CLAIMED — DATABASE-ONLY PACKAGE'),
            'file_sentinel' => $result['file_sentinel'],
            'database_sentinel' => $result['database_sentinel'],
            'active_connector_count' => $result['active_connector_count'],
            'functional_post' => $functionality['post_read'],
        ];
    }

    private function runTargetScript(array $target, string $script): array
    {
        $path = $target['root'].'/lab-probe-'.bin2hex(random_bytes(6)).'.php';
        file_put_contents($path, $script, LOCK_EX);
        $this->ownedPaths[] = $path;
        $command = [PHP_BINARY, $path];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $target['root']);
        if (! is_resource($process)) {
            $this->fail('Unable to start isolated WordPress probe.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $exitCode = null;
        $deadline = microtime(true) + 30;
        do {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            $state = proc_get_status($process);
            if (! $state['running']) {
                $exitCode = $state['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                $this->fail('Isolated WordPress probe exceeded its 30 second limit.');
            }
            usleep(20000);
        } while (true);
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $this->assertSame(0, $exitCode, 'Isolated WordPress probe failed: '.substr($stderr.$stdout, 0, 1000));
        $result = json_decode(trim($stdout), true);
        $this->assertIsArray($result, 'Isolated WordPress probe returned invalid JSON.');

        return $result;
    }

    private function temporaryDirectory(string $prefix): string
    {
        $path = rtrim(sys_get_temp_dir(), '/\\').'/wp-caregrid-'.$prefix.'-'.bin2hex(random_bytes(8));
        if (! mkdir($path, 0700)) {
            throw new \RuntimeException('Unable to create private recovery-lab directory.');
        }
        $this->ownedPaths[] = $path;

        return $path;
    }

    private function temporaryFile(string $bytes, string $suffix): string
    {
        $path = rtrim(sys_get_temp_dir(), '/\\').'/wp-caregrid-lab-'.bin2hex(random_bytes(8)).$suffix;
        file_put_contents($path, $bytes, LOCK_EX);
        $this->ownedPaths[] = $path;

        return $path;
    }

    private function copyTree(string $source, string $target, bool $coreRoot = false): void
    {
        $items = scandir($source);
        if ($items === false) {
            throw new \RuntimeException('Unable to read the local WordPress core fixture.');
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if ($coreRoot && $item === 'wp-config.php') {
                continue;
            }
            $from = $source.'/'.$item;
            $to = $target.'/'.$item;
            if (is_link($from)) {
                throw new \RuntimeException('Refusing symlink in local WordPress core fixture.');
            }
            if (is_dir($from)) {
                mkdir($to, 0700);
                $this->copyTree($from, $to);
            } else {
                copy($from, $to);
            }
        }
    }

    private function tarGz(array $entries): string
    {
        $tar = '';
        foreach ($entries as [$name, $content, $type]) {
            $header = str_repeat("\0", 512);
            $this->putTarField($header, 0, 100, $name);
            $this->putTarField($header, 100, 8, "0000644\0");
            $this->putTarField($header, 108, 8, "0000000\0");
            $this->putTarField($header, 116, 8, "0000000\0");
            $this->putTarField($header, 124, 12, sprintf("%011o\0", strlen($content)));
            $this->putTarField($header, 136, 12, sprintf("%011o\0", 1));
            $this->putTarField($header, 148, 8, '        ');
            $this->putTarField($header, 156, 1, $type);
            $this->putTarField($header, 257, 6, "ustar\0");
            $this->putTarField($header, 263, 2, '00');
            $sum = array_sum(array_map('ord', str_split($header)));
            $this->putTarField($header, 148, 8, sprintf("%06o\0 ", $sum));
            $tar .= $header.$content;
            $tar .= str_repeat("\0", (512 - strlen($content) % 512) % 512);
        }
        $tar .= str_repeat("\0", 1024);

        return gzencode($tar, 6);
    }

    private function putTarField(string &$header, int $offset, int $length, string $value): void
    {
        $header = substr_replace($header, substr($value.str_repeat("\0", $length), 0, $length), $offset, $length);
    }

    private function dropDisposableDatabase(array $owned): void
    {
        $admin = new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        $database = '`'.$owned['database'].'`';
        $user = "'{$owned['username']}'@'{$owned['host']}'";
        $admin->query("DROP DATABASE IF EXISTS {$database}");
        $admin->query("DROP USER IF EXISTS {$user}");
        $admin->close();
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->removeTree($path.'/'.$item);
            }
        }
        @rmdir($path);
    }

    private function requires_mysql_and_gzip(): void
    {
        if (! DatabaseDumper::supported()) {
            $this->markTestSkipped('SQL package reconstruction requires the configured synthetic MySQL/MariaDB Connector test database.');
        }
        $this->requires_gzip();
    }

    private function requires_gzip(): void
    {
        if (! function_exists('gzencode') || ! function_exists('gzdecode')
            || ! function_exists('deflate_init') || ! function_exists('inflate_init')) {
            $this->markTestSkipped('Recovery package lab requires local gzip support.');
        }
    }
}
