<?php

namespace Tests\Feature\Platform;

use App\Platform\DisasterRecovery\PlatformDatabaseBackupService;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Exception\ProcessException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Tests\TestCase;

class PlatformDatabaseBackupMysqlTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql_client_stderr_diagnostic_classification_is_closed_and_never_returns_raw_content(): void
    {
        $service = (new \ReflectionClass(PlatformDatabaseBackupService::class))->newInstanceWithoutConstructor();
        $classifier = new \ReflectionMethod(PlatformDatabaseBackupService::class, 'classifyMysqlClientStderrForDiagnostics');
        $this->app['config']->set('platform_backup.diagnostic_c_enabled', true);

        $cases = [
            ["ERROR 1419 (HY000) at line 12: Binary logging restriction\n", 'MYSQL_IMPORT_ERRNO_1419'],
            ["ERROR 1227 (42000) at line 2: Definer authorization denied\n", 'MYSQL_IMPORT_ERRNO_1227'],
            ["ERROR 1449 (HY000): Specified definer is unavailable\n", 'MYSQL_IMPORT_ERRNO_1449'],
            ["ERROR 1044 (42000): Database access denied\n", 'MYSQL_IMPORT_ERRNO_1044'],
            ["ERROR 1045 (28000): Authentication rejected\n", 'MYSQL_IMPORT_ERRNO_1045'],
            ["ERROR 1142 (42000): Table access denied\n", 'MYSQL_IMPORT_ERRNO_1142'],
            ["ERROR 9999 (HY000): Unknown error\n", 'UNCLASSIFIED'],
            ["ERROR 1419 (42000): Wrong SQLSTATE\n", 'UNCLASSIFIED'],
            ["ERROR 1419 HY000: Malformed record\n", 'UNCLASSIFIED'],
            ["ERROR 1419 (HY000) at line 12: Truncated without line terminator", 'UNCLASSIFIED'],
            ["ERROR 1419 (HY000): First\nERROR 1227 (42000): Second\n", 'UNCLASSIFIED'],
            ["ERROR 1419 (HY000): First ERROR 1227 (42000): Second\n", 'UNCLASSIFIED'],
            [str_repeat('X', 513)."\n", 'UNCLASSIFIED'],
            ["mysql: [Warning] message\nERROR 1419 (HY000): mixed output\n", 'UNCLASSIFIED'],
        ];

        foreach ($cases as [$stderr, $expected]) {
            $this->assertSame($expected, $classifier->invoke($service, $stderr));
        }

        $sensitive = 'SENSITIVE_SENTINEL_value-do-not-disclose';
        $result = $classifier->invoke($service, "ERROR 1227 (42000): Access denied for definer {$sensitive}\n");
        $this->assertSame('MYSQL_IMPORT_ERRNO_1227', $result);
        $this->assertFalse(str_contains($result, $sensitive));
        $this->assertMatchesRegularExpression(
            '/\A(?:UNCLASSIFIED|MYSQL_IMPORT_ERRNO_(?:1419|1227|1449|1044|1045|1142))\z/D',
            $result,
        );

        $this->app['config']->set('platform_backup.diagnostic_c_enabled', false);
        $this->assertSame(
            'UNCLASSIFIED',
            $classifier->invoke($service, "ERROR 1419 (HY000): Binary logging restriction\n"),
        );
    }

    public function test_isolated_verifier_credential_presence_is_strict(): void
    {
        $this->assertFalse($this->hasIsolatedVerifierCredential(false));
        $this->assertFalse($this->hasIsolatedVerifierCredential(null));
        $this->assertFalse($this->hasIsolatedVerifierCredential(''));
        $this->assertFalse($this->hasIsolatedVerifierCredential('   '));
        $this->assertTrue($this->hasIsolatedVerifierCredential('synthetic-verifier-credential'));
    }

    public function test_service_temp_cleanup_emits_complete_only_after_every_applicable_unlink_succeeds(): void
    {
        $service = (new \ReflectionClass(PlatformDatabaseBackupService::class))->newInstanceWithoutConstructor();
        $cleanup = new \ReflectionMethod(PlatformDatabaseBackupService::class, 'cleanupTemporaryArtifacts');
        $root = sys_get_temp_dir().'/wpcg-diag-c-cleanup-'.bin2hex(random_bytes(6));
        mkdir($root, 0700);

        try {
            $successfulPath = $root.'/successful-artifact';
            file_put_contents($successfulPath, 'synthetic');
            $diagnostics = $cleanup->invoke($service, [$successfulPath], 'return_true_pending_cleanup');
            $this->assertSame(['complete'], array_column($diagnostics, 'event'));
            $this->assertFileDoesNotExist($successfulPath);

            $absentPath = $root.'/not-created';
            $diagnostics = $cleanup->invoke($service, [$absentPath], 'return_true_pending_cleanup');
            $this->assertSame(['complete'], array_column($diagnostics, 'event'));

            foreach (['first-failure', 'second-failure'] as $name) {
                $failedPath = $root.'/'.$name;
                mkdir($failedPath, 0700);
                file_put_contents($failedPath.'/child', 'synthetic');

                $diagnostics = $cleanup->invoke($service, [$failedPath], 'return_false_pending_cleanup');

                $this->assertSame(['cleanup_observation'], array_column($diagnostics, 'event'));
                $this->assertSame('SERVICE_TEMP_ARTIFACT_UNLINK_FAILED', $diagnostics[0]['code']);
                $this->assertSame('return_false_pending_cleanup', $diagnostics[0]['body_state']);
                unlink($failedPath.'/child');
                rmdir($failedPath);
            }

            $successfulPath = $root.'/success-with-failure';
            file_put_contents($successfulPath, 'synthetic');
            $failedPath = $root.'/multiple-failure';
            mkdir($failedPath, 0700);
            file_put_contents($failedPath.'/child', 'synthetic');
            $diagnostics = $cleanup->invoke($service, [$successfulPath, $failedPath], 'return_false_pending_cleanup');
            $this->assertSame(['cleanup_observation'], array_column($diagnostics, 'event'));
            $this->assertFileDoesNotExist($successfulPath);
            unlink($failedPath.'/child');
            rmdir($failedPath);

            $source = file_get_contents((new \ReflectionClass(PlatformDatabaseBackupService::class))->getFileName());
            $verifyMethodStart = strpos($source, 'public function verify(');
            $outerCleanupScope = strpos($source, '$target = fopen($path, \'wb\');');
            $this->assertNotFalse($verifyMethodStart);
            $this->assertNotFalse($outerCleanupScope);
            $this->assertGreaterThan($verifyMethodStart, $outerCleanupScope);
            $earlyVerifySource = substr($source, $verifyMethodStart, $outerCleanupScope - $verifyMethodStart);
            $this->assertStringContainsString('return_false_before_outer_cleanup', $earlyVerifySource);
            $this->assertStringNotContainsString("'return_false_pending_cleanup'", $earlyVerifySource);
        } finally {
            @rmdir($root);
        }
    }

    public function test_cleanup_objects_never_emits_complete_after_an_unlink_or_rmdir_failure(): void
    {
        $successfulRoot = sys_get_temp_dir().'/wpcg-cleanup-success-'.bin2hex(random_bytes(6));
        mkdir($successfulRoot, 0700);
        file_put_contents($successfulRoot.'/artifact', 'synthetic');
        $successfulEvents = [];

        try {
            $succeeded = $this->cleanupDiagnosticObjects(
                $successfulRoot,
                static function (string $event, string $category, string $code) use (&$successfulEvents): void {
                    $successfulEvents[] = compact('event', 'category', 'code');
                },
            );

            $this->assertTrue($succeeded);
            $this->assertSame(['complete'], array_column($successfulEvents, 'event'));
        } finally {
            @unlink($successfulRoot.'/artifact');
            @rmdir($successfulRoot);
        }

        $failingRoot = sys_get_temp_dir().'/wpcg-cleanup-failure-'.bin2hex(random_bytes(6));
        $nestedDirectory = $failingRoot.'/nested';
        mkdir($nestedDirectory, 0700, true);
        $failedArtifact = $nestedDirectory.'/blocked-artifact';
        file_put_contents($failedArtifact, 'synthetic');
        file_put_contents($failingRoot.'/successful-artifact', 'synthetic');
        $failureEvents = [];

        try {
            $succeeded = $this->cleanupDiagnosticObjects(
                $failingRoot,
                static function (string $event, string $category, string $code) use (&$failureEvents): void {
                    $failureEvents[] = compact('event', 'category', 'code');
                },
                static fn (string $path): bool => $path === $failedArtifact ? false : @unlink($path),
            );

            $this->assertFalse($succeeded);
            $this->assertSame(
                ['cleanup_observation', 'cleanup_observation', 'cleanup_observation'],
                array_column($failureEvents, 'event'),
            );
            $this->assertNotContains('complete', array_column($failureEvents, 'event'));
            $this->assertFileExists($failedArtifact);
            $this->assertFileDoesNotExist($failingRoot.'/successful-artifact');
        } finally {
            @unlink($failedArtifact);
            @rmdir($nestedDirectory);
            @rmdir($failingRoot);
        }
    }

    private function cleanupDiagnosticObjects(string $root, callable $emit, ?callable $unlink = null, ?callable $rmdir = null): bool
    {
        $unlink ??= static fn (string $path): bool => @unlink($path);
        $rmdir ??= static fn (string $path): bool => @rmdir($path);
        $cleanupFailed = false;

        if (is_dir($root)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $removed = $file->isDir()
                    ? $rmdir($file->getPathname())
                    : $unlink($file->getPathname());

                if (! $removed) {
                    $cleanupFailed = true;
                    $emit('cleanup_observation', 'cleanup_error', 'other');
                }
            }
        }

        if (! $rmdir($root)) {
            $cleanupFailed = true;
            $emit('cleanup_observation', 'cleanup_error', 'other');
        }

        if (! $cleanupFailed) {
            $emit('complete', 'none', 'none');
        }

        return ! $cleanupFailed;
    }

    #[Group('wpcg_isolated_mysql_backup_verification')]
    public function test_mysql_dump_is_imported_into_a_generated_disposable_schema_and_sanity_checked(): void
    {
        $verificationRequired = getenv('WPCG_BACKUP_VERIFY_REQUIRED') === '1';
        if (DB::connection()->getDriverName() !== 'mysql') {
            if ($verificationRequired) {
                $this->fail('Mandatory isolated MySQL verification requires the MySQL test connection.');
            }
            $this->markTestSkipped('Requires the isolated MySQL 8.4 CI service.');
        }

        $verificationPassword = getenv('WPCG_MYSQL_VERIFY_PASSWORD');
        $verificationHost = getenv('WPCG_MYSQL_VERIFY_HOST');
        $verificationPort = getenv('WPCG_MYSQL_VERIFY_PORT');
        $verificationDatabase = getenv('WPCG_MYSQL_VERIFY_DATABASE');
        $verificationUsername = getenv('WPCG_MYSQL_VERIFY_USERNAME');
        $verificationConfigIsValid = is_string($verificationHost)
            && trim($verificationHost) !== ''
            && is_string($verificationPort)
            && ctype_digit($verificationPort)
            && (int) $verificationPort > 0
            && (int) $verificationPort <= 65535
            && is_string($verificationDatabase)
            && trim($verificationDatabase) !== ''
            && is_string($verificationUsername)
            && trim($verificationUsername) !== '';

        if (! $this->hasIsolatedVerifierCredential($verificationPassword) || ! $verificationConfigIsValid) {
            if ($verificationRequired) {
                $this->fail('Mandatory isolated MySQL verifier configuration is missing or invalid.');
            }
            $this->markTestSkipped('Requires the isolated CI MySQL verification service and restricted verifier credential.');
        }

        $probe = 'm9_recovery_probe';
        $root = null;
        $activeStage = 'fixture_setup';
        $diagnosticCode = 'none';
        $primaryFailure = null;

        $this->emitDiagnosticV2($activeStage, 'begin');
        try {
            $mysql = config('database.connections.mysql');
            config([
                'database.connections.m9_platform_source' => $mysql,
                'database.connections.m9_platform_verify' => array_merge($mysql, [
                    'host' => $verificationHost,
                    'port' => (int) $verificationPort,
                    'database' => $verificationDatabase,
                    'username' => $verificationUsername,
                    'password' => $verificationPassword,
                ]),
                'platform_backup.enabled' => true,
                'platform_backup.source_connection' => 'm9_platform_source',
                'platform_backup.verification_connection' => 'm9_platform_verify',
                'platform_backup.verification_database_prefix' => 'wpcg_m9_dr_ci_',
                'platform_backup.verification_isolated' => true,
                // Test-level markers locate the assertion only; opt in here for service-stage diagnostics.
                'platform_backup.diagnostic_c_enabled' => true,
                'platform_backup.critical_tables' => array_merge(config('platform_backup.critical_tables'), [$probe]),
                'platform_backup.encryption.current_key_id' => 'test-v1',
                'platform_backup.encryption.keys' => ['test-v1' => base64_encode(str_repeat('k', 32))],
                'platform_backup.disk' => 'platform_backups',
                'filesystems.disks.platform_backups.driver' => 'local',
                'filesystems.disks.platform_backups.root' => sys_get_temp_dir().'/wpcg-m9-mysql-objects-'.bin2hex(random_bytes(6)),
            ]);
            $root = config('filesystems.disks.platform_backups.root');
            DB::purge('m9_platform_source');
            DB::purge('m9_platform_verify');
            Storage::forgetDisk('platform_backups');

            $primaryProbe = 'm9_verify_primary_scope_probe';
            config(["database.connections.{$primaryProbe}" => array_merge($mysql, [
                'username' => $verificationUsername,
                'password' => $verificationPassword,
            ])]);
            try {
                DB::connection($primaryProbe)->getPdo();
                $this->fail('The isolated verifier unexpectedly authenticated to the application MySQL instance.');
            } catch (\PDOException $exception) {
                // The restricted verifier identity exists only on the isolated verification instance.
                $this->assertSame(1045, (int) ($exception->errorInfo[1] ?? 0), 'The verifier must be denied by MySQL authentication, not by a connection/configuration failure.');
            } finally {
                DB::purge($primaryProbe);
            }
        } catch (\Throwable $exception) {
            $this->emitDiagnosticV2($activeStage, 'primary_failure', $this->diagnosticCategoryV2($exception));
            throw $exception;
        }

        $activeStage = 'probe_setup';
        $this->emitDiagnosticV2($activeStage, 'begin');
        try {
            Schema::connection('m9_platform_source')->dropIfExists($probe);
            Schema::connection('m9_platform_source')->create($probe, function (Blueprint $table): void {
                $table->unsignedInteger('id')->primary();
                $table->string('record_key')->unique();
            });
            DB::connection('m9_platform_source')->table($probe)->insert([
                ['id' => 1, 'record_key' => 'site-snapshot'],
                ['id' => 2, 'record_key' => 'audit-snapshot'],
            ]);
        } catch (\Throwable $exception) {
            $this->emitDiagnosticV2($activeStage, 'primary_failure', $this->diagnosticCategoryV2($exception));
            throw $exception;
        }

        try {
            $activeStage = 'backup_create';
            $this->emitDiagnosticV2($activeStage, 'begin');
            $service = app(PlatformDatabaseBackupService::class);
            $backup = $service->create('daily');

            $activeStage = 'verify';
            $this->emitDiagnosticV2($activeStage, 'begin');
            $verified = $service->verify($backup);

            $activeStage = 'assert_verified';
            $this->emitDiagnosticV2($activeStage, 'begin');
            $failureCode = $backup->fresh()?->failure_code ?? 'unknown';
            if (! $verified) {
                $diagnosticCode = $this->diagnosticCodeV2($failureCode);
            }
            $this->assertTrue($verified, 'Verification failed: '.$failureCode);

            $activeStage = 'assert_status';
            $this->emitDiagnosticV2($activeStage, 'begin');
            $this->assertSame('verified', $backup->fresh()->status);

            $activeStage = 'assert_method';
            $this->emitDiagnosticV2($activeStage, 'begin');
            $this->assertSame('stored-object-sha256-gzip-and-disposable-mysql-import', $backup->fresh()->verification_method);

            $activeStage = 'assert_manifest';
            $this->emitDiagnosticV2($activeStage, 'begin');
            $tables = $backup->manifest['table_manifest']['tables'];

            $activeStage = 'assert_probe';
            $this->emitDiagnosticV2($activeStage, 'begin');
            $this->assertContains($probe, $tables);

            $activeStage = 'assert_critical_count';
            $this->emitDiagnosticV2($activeStage, 'begin');
            $this->assertSame(2, $backup->manifest['table_manifest']['critical_counts'][$probe]);
        } catch (\Throwable $exception) {
            $primaryFailure = $exception;
            $this->emitDiagnosticV2($activeStage, 'primary_failure', $this->diagnosticCategoryV2($exception), $diagnosticCode);
            throw $exception;
        } finally {
            try {
                $activeStage = 'cleanup_probe';
                $this->emitDiagnosticV2($activeStage, 'begin');
                Schema::connection('m9_platform_source')->dropIfExists($probe);

                $activeStage = 'cleanup_connections';
                $this->emitDiagnosticV2($activeStage, 'begin');
                DB::purge('m9_platform_source');
                DB::purge('m9_platform_verify');

                $activeStage = 'cleanup_objects';
                $this->emitDiagnosticV2($activeStage, 'begin');
                if (is_string($root)) {
                    $this->cleanupDiagnosticObjects(
                        $root,
                        function (string $event, string $category, string $code) use (&$activeStage): void {
                            $this->emitDiagnosticV2($activeStage, $event, $category, $code);
                        },
                    );
                } else {
                    $this->emitDiagnosticV2($activeStage, 'complete');
                }
            } catch (\Throwable $cleanupException) {
                $this->emitDiagnosticV2($activeStage, 'cleanup_failure', 'cleanup_error', 'other');
                if ($primaryFailure === null) {
                    throw $cleanupException;
                }
            }
        }
    }

    private function emitDiagnosticV2(string $stage, string $event, string $category = 'none', string $code = 'none'): void
    {
        static $stages = [
            'fixture_setup', 'probe_setup', 'backup_create', 'verify', 'assert_verified', 'assert_status',
            'assert_method', 'assert_manifest', 'assert_probe', 'assert_critical_count', 'cleanup_probe',
            'cleanup_connections', 'cleanup_objects',
        ];
        static $events = ['begin', 'primary_failure', 'cleanup_observation', 'cleanup_failure', 'complete'];
        static $categories = [
            'none', 'assertion_failure', 'pdo_error', 'process_error', 'filesystem_error', 'permission_error',
            'timeout', 'cleanup_error', 'backup_failure', 'other',
        ];

        if (! in_array($stage, $stages, true) || ! in_array($event, $events, true) || ! in_array($category, $categories, true)) {
            $stage = 'fixture_setup';
            $event = 'primary_failure';
            $category = 'other';
            $code = 'other';
        }

        if ($code !== 'none' && $code !== 'other' && ! $this->isAllowedDiagnosticCodeV2($code)) {
            $code = 'other';
        }

        try {
            if (defined('STDERR')) {
                @fwrite(STDERR, 'WPCG_DIAG_V2|test=backup_import|stage='.$stage.'|event='.$event.'|category='.$category.'|code='.$code.PHP_EOL);
                @fflush(STDERR);
            }
        } catch (\Throwable) {
            // Diagnostics must never affect the test result.
        }
    }

    private function diagnosticCategoryV2(\Throwable $exception): string
    {
        if (is_a($exception, AssertionFailedError::class)) {
            return 'assertion_failure';
        }

        if ($exception instanceof \PDOException) {
            return 'pdo_error';
        }

        if (is_a($exception, ProcessTimedOutException::class)) {
            return 'timeout';
        }

        if (is_a($exception, ProcessException::class)) {
            return 'process_error';
        }

        if (is_a($exception, FileNotFoundException::class)) {
            return 'filesystem_error';
        }

        return 'other';
    }

    private function hasIsolatedVerifierCredential(mixed $credential): bool
    {
        return is_string($credential) && trim($credential) !== '';
    }

    private function diagnosticCodeV2(?string $code): string
    {
        return is_string($code) && $this->isAllowedDiagnosticCodeV2($code) ? $code : 'other';
    }

    private function isAllowedDiagnosticCodeV2(string $code): bool
    {
        static $codes = [
            'WORKDIR_CREATE_FAILED', 'PLATFORM_BACKUP_FAILED', 'DISPOSABLE_MYSQL_IMPORT_NOT_CONFIGURED',
            'DISPOSABLE_MYSQL_IMPORT_FAILED', 'MYSQL_IMPORT_TABLE_SET_MISMATCH',
            'MYSQL_IMPORT_CRITICAL_COUNT_MISMATCH', 'OBJECT_MISSING', 'MANIFEST_OBJECT_MISSING',
            'MANIFEST_OBJECT_INVALID', 'MANIFEST_METADATA_MISMATCH', 'MANIFEST_AUTHENTICATION_FAILED',
            'OBJECT_UNREADABLE', 'VERIFY_TEMP_FAILED', 'OBJECT_READ_FAILED', 'VERIFY_TEMP_WRITE_FAILED',
            'SIZE_OR_HASH_MISMATCH', 'CIPHERTEXT_AUTHENTICATION_FAILED', 'INVALID_GZIP',
            'EMPTY_DECOMPRESSED_ARTIFACT', 'DATABASE_IMPORT_OR_SANITY_FAILED', 'VERIFICATION_ERROR',
        ];

        return in_array($code, $codes, true)
            || preg_match('/^MYSQL_IMPORT_PROCESS_FAILED_EXIT_(?:[0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])$/D', $code) === 1;
    }
}
