<?php

namespace Tests\Feature\Platform;

use App\Platform\DisasterRecovery\PlatformDatabaseBackupCredentialCleanupException;
use App\Platform\DisasterRecovery\PlatformDatabaseBackupService;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Exception\ProcessException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
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
            ['ERROR 1419 (HY000) at line 12: Truncated without line terminator', 'UNCLASSIFIED'],
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

    public function test_mysql_import_subprocess_allows_only_required_non_sensitive_environment(): void
    {
        $service = (new \ReflectionClass(PlatformDatabaseBackupService::class))->newInstanceWithoutConstructor();
        $loginPathDirectory = (new \ReflectionMethod(PlatformDatabaseBackupService::class, 'createMysqlImportLoginPathDirectory'))->invoke($service);
        $credentialNames = [
            'DB_PASSWORD',
            'DB_USERNAME',
            'WPCG_MYSQL_VERIFY_PASSWORD',
            'WPCG_MYSQL_VERIFY_USERNAME',
            'MYSQL_PWD',
            'MYSQL_ROOT_PASSWORD',
            'PLATFORM_DB_BACKUP_VERIFY_DB_PASSWORD',
            'PLATFORM_DB_BACKUP_VERIFY_DB_USERNAME',
            'DATABASE_URL',
            'DB_URL',
            'APP_KEY',
            'AWS_SECRET_ACCESS_KEY',
            'CI_JOB_TOKEN',
            'MYSQL_TEST_LOGIN_FILE',
            'MYSQL_HOME',
            'MYSQL_GROUP_SUFFIX',
            'MYSQL_TCP_PORT',
            'MYSQL_UNIX_PORT',
            'UNRECOGNIZED_SECRET_FIELD',
        ];
        $snapshot = [];

        foreach ($credentialNames as $name) {
            $snapshot[$name] = [
                'getenv' => getenv($name),
                'env_exists' => array_key_exists($name, $_ENV),
                'env_value' => $_ENV[$name] ?? null,
                'server_exists' => array_key_exists($name, $_SERVER),
                'server_value' => $_SERVER[$name] ?? null,
            ];
            putenv($name.'=synthetic-sensitive-value');
            $_ENV[$name] = 'synthetic-sensitive-value';
            $_SERVER[$name] = 'synthetic-sensitive-value';
        }

        try {
            $environment = (new \ReflectionMethod(PlatformDatabaseBackupService::class, 'mysqlClientProcessEnvironment'))->invoke($service, $loginPathDirectory);
            $probe = '$keys = '.var_export($credentialNames, true).'; '
                .'$result = []; foreach ($keys as $key) { $result[$key] = getenv($key) !== false; } '
                .'foreach (["HOME", "APPDATA", "USERPROFILE", "MYSQL_TEST_LOGIN_FILE"] as $key) { $result[$key] = getenv($key); } '
                .'$result["path_present"] = getenv("PATH") !== false; '
                .'echo json_encode($result, JSON_THROW_ON_ERROR);';
            $process = new Process([PHP_BINARY, '-r', $probe], null, $environment, null, 10);
            $process->run();

            $this->assertTrue($process->isSuccessful());
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $expected = array_fill_keys($credentialNames, false);
            $expected['HOME'] = $loginPathDirectory;
            $expected['APPDATA'] = $loginPathDirectory;
            $expected['USERPROFILE'] = $loginPathDirectory;
            $expected['MYSQL_TEST_LOGIN_FILE'] = $loginPathDirectory.DIRECTORY_SEPARATOR.'.mylogin.cnf';
            $expected['path_present'] = true;
            $this->assertSame($expected, $result);
            $this->assertStringNotContainsString('synthetic-sensitive-value', $process->getOutput());
            $this->assertStringNotContainsString('synthetic-sensitive-value', $process->getErrorOutput());
        } finally {
            foreach ($snapshot as $name => $state) {
                if ($state['getenv'] === false) {
                    putenv($name);
                } else {
                    putenv($name.'='.$state['getenv']);
                }
                if ($state['env_exists']) {
                    $_ENV[$name] = $state['env_value'];
                } else {
                    unset($_ENV[$name]);
                }
                if ($state['server_exists']) {
                    $_SERVER[$name] = $state['server_value'];
                } else {
                    unset($_SERVER[$name]);
                }
            }
            $this->assertFalse(file_exists($loginPathDirectory.DIRECTORY_SEPARATOR.'.mylogin.cnf'));
            $this->assertTrue(rmdir($loginPathDirectory));
        }
    }

    public function test_mysql_defaults_file_remains_private_and_contains_authentication_options(): void
    {
        $service = (new \ReflectionClass(PlatformDatabaseBackupService::class))->newInstanceWithoutConstructor();
        $password = 'synthetic-private-defaults-password';
        $defaults = (new \ReflectionMethod(PlatformDatabaseBackupService::class, 'mysqlDefaultsFile'))->invoke($service, [
            'username' => 'synthetic-user',
            'password' => $password,
            'host' => '127.0.0.1',
            'port' => 3307,
        ]);

        try {
            $contents = file_get_contents($defaults);
            $command = (new \ReflectionMethod(PlatformDatabaseBackupService::class, 'mysqlImportCommand'))->invoke($service, $defaults, 'wpcg_synthetic_target');
            $this->assertIsString($contents);
            $this->assertSame(0600, fileperms($defaults) & 0777);
            $this->assertTrue(str_contains($contents, 'user="synthetic-user"'));
            $this->assertTrue(str_contains($contents, 'password="'.$password.'"'));
            $this->assertSame('--defaults-extra-file='.$defaults, $command[1]);
            $this->assertSame('--database=wpcg_synthetic_target', $command[2]);
            $this->assertNotContains('--no-login-paths', $command);
        } finally {
            $this->assertTrue($this->unlinkPrivateTestFile($defaults));
        }
    }

    public function test_mysql_credential_cleanup_is_fail_closed_and_preserves_primary_failure(): void
    {
        $service = (new \ReflectionClass(PlatformDatabaseBackupService::class))->newInstanceWithoutConstructor();
        $defaultsMethod = new \ReflectionMethod(PlatformDatabaseBackupService::class, 'mysqlDefaultsFile');
        $cleanupMethod = new \ReflectionMethod(PlatformDatabaseBackupService::class, 'cleanupMysqlClientResources');
        $secret = 'synthetic-cleanup-credential-do-not-disclose';
        $config = [
            'username' => 'synthetic-user',
            'password' => $secret,
            'host' => '127.0.0.1',
            'port' => 3307,
        ];

        $successfulDefaults = $defaultsMethod->invoke($service, $config);
        $input = fopen('php://memory', 'rb');
        $successfulCleanup = $cleanupMethod->invokeArgs($service, [
            $successfulDefaults,
            null,
            $input,
            null,
            'MYSQL_IMPORT_PROCESS_FAILED_EXIT_1',
        ]);

        $this->assertTrue($successfulCleanup['success']);
        $this->assertSame('MYSQL_IMPORT_PROCESS_FAILED_EXIT_1', $successfulCleanup['failure_code']);
        $this->assertSame('complete', $successfulCleanup['event']);
        $this->assertFalse(is_resource($input));
        $this->assertFileDoesNotExist($successfulDefaults);

        $failedDefaults = $defaultsMethod->invoke($service, $config);
        try {
            $failedCleanup = $cleanupMethod->invokeArgs($service, [
                $failedDefaults,
                null,
                null,
                null,
                'MYSQL_IMPORT_PROCESS_FAILED_EXIT_1',
                static fn (string $path): bool => false,
            ]);

            $this->assertFalse($failedCleanup['success']);
            $this->assertSame('MYSQL_IMPORT_PROCESS_FAILED_EXIT_1', $failedCleanup['failure_code']);
            $this->assertSame('cleanup_failure', $failedCleanup['event']);
            $this->assertFileExists($failedDefaults);
            $this->assertStringNotContainsString($secret, json_encode($failedCleanup, JSON_THROW_ON_ERROR));
        } finally {
            $this->assertTrue($this->unlinkPrivateTestFile($failedDefaults));
        }

        $writeFailurePath = null;
        try {
            $defaultsMethod->invokeArgs($service, [
                $config,
                'wpcg-mysql-verify-',
                static function (string $path, string $contents) use (&$writeFailurePath): int|false {
                    $writeFailurePath = $path;
                    $written = file_put_contents($path, $contents);

                    return is_int($written) ? max(0, $written - 1) : false;
                },
                static fn (string $path): bool => false,
            ]);
            $this->fail('A partial defaults-file write with failed cleanup must throw.');
        } catch (PlatformDatabaseBackupCredentialCleanupException $error) {
            $this->assertSame('Could not securely remove private MySQL verification configuration.', $error->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $error->getPrevious());
            $this->assertStringNotContainsString($secret, $error->getMessage());
            $this->assertIsString($writeFailurePath);
            $this->assertFileExists($writeFailurePath);
        } finally {
            if (is_string($writeFailurePath) && file_exists($writeFailurePath)) {
                $this->assertTrue($this->unlinkPrivateTestFile($writeFailurePath));
            }
        }

        $outcomeMethod = new \ReflectionMethod(PlatformDatabaseBackupService::class, 'mysqlImportOutcome');
        $this->assertSame([
            'success' => true,
            'failure_code' => 'none',
        ], $outcomeMethod->invoke($service, true, false, null, [
            'success' => true,
            'failure_code' => null,
            'event' => 'complete',
        ], 'none'));
        $this->assertSame([
            'success' => false,
            'failure_code' => 'MYSQL_PROCESS_CLEANUP_FAILED',
        ], $outcomeMethod->invoke($service, true, false, null, [
            'success' => false,
            'failure_code' => 'MYSQL_PROCESS_CLEANUP_FAILED',
            'event' => 'cleanup_failure',
        ], 'none'));
        $this->assertSame([
            'success' => false,
            'failure_code' => 'MYSQL_IMPORT_PROCESS_FAILED_EXIT_1',
        ], $outcomeMethod->invoke($service, false, true, null, [
            'success' => false,
            'failure_code' => 'MYSQL_IMPORT_PROCESS_FAILED_EXIT_1',
            'event' => 'cleanup_failure',
        ], 'MYSQL_IMPORT_PROCESS_FAILED_EXIT_1'));

        $primaryException = new \RuntimeException('synthetic primary import failure');
        try {
            $outcomeMethod->invoke($service, false, false, $primaryException, [
                'success' => false,
                'failure_code' => 'MYSQL_PROCESS_CLEANUP_FAILED',
                'event' => 'cleanup_failure',
            ], 'VERIFICATION_ERROR');
            $this->fail('The primary exception must remain primary when cleanup also fails.');
        } catch (\ReflectionException $error) {
            throw $error;
        } catch (\RuntimeException $error) {
            $this->assertSame($primaryException, $error);
            $this->assertSame('synthetic primary import failure', $error->getMessage());
        }

        $createLoginDirectory = new \ReflectionMethod(PlatformDatabaseBackupService::class, 'createMysqlImportLoginPathDirectory');
        $loginDirectory = $createLoginDirectory->invoke($service);
        $loginFile = $loginDirectory.DIRECTORY_SEPARATOR.'.mylogin.cnf';
        $loginSecret = 'synthetic-login-path-secret';
        file_put_contents($loginFile, $loginSecret);
        $defaults = $defaultsMethod->invoke($service, $config);
        try {
            $loginFileFailure = $cleanupMethod->invokeArgs($service, [
                $defaults,
                $loginDirectory,
                null,
                null,
                null,
                static fn (string $path): bool => $path === $loginFile ? false : @unlink($path),
            ]);

            $this->assertFalse($loginFileFailure['success']);
            $this->assertSame('MYSQL_PROCESS_CLEANUP_FAILED', $loginFileFailure['failure_code']);
            $this->assertSame('cleanup_failure', $loginFileFailure['event']);
            $this->assertFileDoesNotExist($defaults);
            $this->assertFileExists($loginFile);
            $this->assertStringNotContainsString($loginSecret, json_encode($loginFileFailure, JSON_THROW_ON_ERROR));
        } finally {
            if (file_exists($defaults)) {
                $this->assertTrue($this->unlinkPrivateTestFile($defaults));
            }
            if (file_exists($loginFile)) {
                $this->assertTrue(@unlink($loginFile));
            }
            if (is_dir($loginDirectory)) {
                $this->assertTrue(@rmdir($loginDirectory));
            }
        }

        $loginDirectory = $createLoginDirectory->invoke($service);
        $defaults = $defaultsMethod->invoke($service, $config);
        try {
            $directoryFailure = $cleanupMethod->invokeArgs($service, [
                $defaults,
                $loginDirectory,
                null,
                null,
                null,
                null,
                static fn (string $path): bool => false,
            ]);

            $this->assertFalse($directoryFailure['success']);
            $this->assertSame('MYSQL_PROCESS_CLEANUP_FAILED', $directoryFailure['failure_code']);
            $this->assertSame('cleanup_failure', $directoryFailure['event']);
            $this->assertFileDoesNotExist($defaults);
            $this->assertDirectoryExists($loginDirectory);
        } finally {
            if (file_exists($defaults)) {
                $this->assertTrue($this->unlinkPrivateTestFile($defaults));
            }
            if (is_dir($loginDirectory)) {
                $this->assertTrue(@rmdir($loginDirectory));
            }
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            $temporaryRoot = realpath(sys_get_temp_dir());
            $ownedLink = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcg-mysql-login-'.bin2hex(random_bytes(8));
            $foreignDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcg-foreign-login-'.bin2hex(random_bytes(8));
            $foreignFile = $foreignDirectory.DIRECTORY_SEPARATOR.'.mylogin.cnf';
            $this->assertIsString($temporaryRoot);
            $this->assertTrue(@mkdir($foreignDirectory, 0700));
            file_put_contents($foreignFile, 'synthetic-foreign-login-file');
            $this->assertTrue(@symlink($foreignDirectory, $ownedLink));
            $defaults = $defaultsMethod->invoke($service, $config);
            try {
                $symlinkCleanup = $cleanupMethod->invokeArgs($service, [
                    $defaults,
                    $ownedLink,
                    null,
                    null,
                ]);

                $this->assertFalse($symlinkCleanup['success']);
                $this->assertSame('cleanup_failure', $symlinkCleanup['event']);
                $this->assertFileDoesNotExist($defaults);
                $this->assertFileExists($foreignFile);
            } finally {
                if (file_exists($defaults)) {
                    $this->assertTrue($this->unlinkPrivateTestFile($defaults));
                }
                if (is_link($ownedLink)) {
                    $this->assertTrue(@unlink($ownedLink));
                }
                if (file_exists($foreignFile)) {
                    $this->assertTrue(@unlink($foreignFile));
                }
                if (is_dir($foreignDirectory)) {
                    $this->assertTrue(@rmdir($foreignDirectory));
                }
            }
        }
    }

    public function test_mysql_client_process_is_stopped_before_private_credentials_are_deleted(): void
    {
        $service = (new \ReflectionClass(PlatformDatabaseBackupService::class))->newInstanceWithoutConstructor();
        $defaults = (new \ReflectionMethod(PlatformDatabaseBackupService::class, 'mysqlDefaultsFile'))->invoke($service, [
            'username' => 'synthetic-user',
            'password' => 'synthetic-process-cleanup-credential',
            'host' => '127.0.0.1',
            'port' => 3307,
        ]);
        $process = new Process([PHP_BINARY, '-r', 'usleep(5000000);']);
        $process->start();

        try {
            $cleanup = (new \ReflectionMethod(PlatformDatabaseBackupService::class, 'cleanupMysqlClientResources'))->invokeArgs($service, [
                $defaults,
                null,
                null,
                $process,
                null,
                static function (string $path) use ($process): bool {
                    if ($process->isRunning()) {
                        return false;
                    }

                    return @unlink($path);
                },
            ]);

            $this->assertTrue($cleanup['success']);
            $this->assertSame('complete', $cleanup['event']);
            $this->assertFalse($process->isRunning());
            $this->assertFileDoesNotExist($defaults);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
            if (file_exists($defaults)) {
                $this->assertTrue($this->unlinkPrivateTestFile($defaults));
            }
        }
    }

    public function test_snapshot_cleanup_failure_is_logged_without_replacing_primary_process_failure(): void
    {
        $service = (new \ReflectionClass(PlatformDatabaseBackupService::class))->newInstanceWithoutConstructor();
        $snapshot = new \ReflectionMethod(PlatformDatabaseBackupService::class, 'snapshotMysql');
        $originalBinary = config('platform_backup.mysql_dump_binary');
        $destination = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcg-snapshot-failure-'.bin2hex(random_bytes(8));
        $defaults = null;
        config(['platform_backup.mysql_dump_binary' => 'wpcg-missing-mysqldump-binary']);
        Log::shouldReceive('error')
            ->once()
            ->with('MySQL backup credential cleanup failed.', ['code' => 'MYSQL_PROCESS_CLEANUP_FAILED']);

        try {
            $snapshot->invokeArgs($service, [
                'synthetic_connection',
                [
                    'username' => 'synthetic-user',
                    'password' => 'synthetic-snapshot-credential',
                    'host' => '127.0.0.1',
                    'port' => 3307,
                ],
                'synthetic_database',
                $destination,
                static function (string $path) use (&$defaults): bool {
                    if (str_starts_with(basename($path), 'wpcg-mysql-')) {
                        $defaults = $path;

                        return false;
                    }

                    return @unlink($path);
                },
            ]);

            $this->fail('A missing MySQL client must preserve its primary process failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame(
                'mysqldump failed for connection synthetic_connection with exit code 127.',
                $error->getMessage(),
            );
        } finally {
            config(['platform_backup.mysql_dump_binary' => $originalBinary]);
            if (is_string($defaults) && file_exists($defaults)) {
                $this->assertTrue($this->unlinkPrivateTestFile($defaults));
            }
            if (file_exists($destination)) {
                $this->assertTrue($this->unlinkPrivateTestFile($destination));
            }
        }

        $this->assertIsString($defaults);
        $this->assertFileDoesNotExist($destination);
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

    private function assertMysqlClientFailsSafelyWhenDefaultsFileIsMissing(): void
    {
        $missingDefaults = sys_get_temp_dir().'/wpcg-missing-mysql-defaults-'.bin2hex(random_bytes(8));
        $service = (new \ReflectionClass(PlatformDatabaseBackupService::class))->newInstanceWithoutConstructor();
        $loginPathDirectory = (new \ReflectionMethod(PlatformDatabaseBackupService::class, 'createMysqlImportLoginPathDirectory'))->invoke($service);
        $environment = (new \ReflectionMethod(PlatformDatabaseBackupService::class, 'mysqlClientProcessEnvironment'))->invoke($service, $loginPathDirectory);
        $command = (new \ReflectionMethod(PlatformDatabaseBackupService::class, 'mysqlImportCommand'))->invoke($service, $missingDefaults, 'wpcg_synthetic_target');
        $command[] = '--execute=SELECT 1';
        $process = new Process($command, null, $environment, null, 10);

        try {
            $process->run();
            $diagnosticOutput = $process->getOutput().$process->getErrorOutput();
            $containsSensitiveValue = false;
            foreach (['DB_PASSWORD', 'WPCG_MYSQL_VERIFY_PASSWORD', 'MYSQL_PWD', 'MYSQL_ROOT_PASSWORD'] as $name) {
                $value = getenv($name);
                if (is_string($value) && $value !== '') {
                    $containsSensitiveValue = $containsSensitiveValue || str_contains($diagnosticOutput, $value);
                }
            }

            $this->assertFalse($process->isSuccessful());
            $this->assertFalse($containsSensitiveValue);
        } finally {
            $process->clearOutput();
            $process->clearErrorOutput();
            $loginPathFile = $loginPathDirectory.DIRECTORY_SEPARATOR.'.mylogin.cnf';
            if (file_exists($loginPathFile) || is_link($loginPathFile)) {
                $this->assertTrue($this->unlinkPrivateTestFile($loginPathFile));
            }
            $this->assertTrue(@rmdir($loginPathDirectory) || ! is_dir($loginPathDirectory));
        }
    }

    /** @return array<string, string|false> */
    private function conflictingLoginPathProcessEnvironment(string $root, string $home, string $profile, string $appData, string $loginFile): array
    {
        $service = (new \ReflectionClass(PlatformDatabaseBackupService::class))->newInstanceWithoutConstructor();
        $environment = (new \ReflectionMethod(PlatformDatabaseBackupService::class, 'mysqlClientProcessEnvironment'))->invoke($service, $root);
        $environment['HOME'] = $home;
        $environment['USERPROFILE'] = $profile;
        $environment['APPDATA'] = $appData;
        $environment['MYSQL_TEST_LOGIN_FILE'] = $loginFile;

        return $environment;
    }

    /** @param array<string, string|false> $environment */
    private function assertCredentialFreeHelperEnvironment(array $environment): void
    {
        $credentialNames = [
            'DB_PASSWORD', 'DB_USERNAME', 'WPCG_MYSQL_VERIFY_PASSWORD', 'WPCG_MYSQL_VERIFY_USERNAME',
            'MYSQL_PWD', 'MYSQL_ROOT_PASSWORD', 'PLATFORM_DB_BACKUP_VERIFY_DB_PASSWORD',
            'PLATFORM_DB_BACKUP_VERIFY_DB_USERNAME', 'DATABASE_URL', 'DB_URL', 'APP_KEY',
            'AWS_SECRET_ACCESS_KEY', 'CI_JOB_TOKEN',
        ];
        $probe = '$keys = '.var_export($credentialNames, true).'; $result = []; '
            .'foreach ($keys as $key) { $result[$key] = getenv($key) !== false; } '
            .'$result["path_present"] = getenv("PATH") !== false; '
            .'$result["mysql_test_login_file_present"] = getenv("MYSQL_TEST_LOGIN_FILE") !== false; '
            .'echo json_encode($result, JSON_THROW_ON_ERROR);';
        $process = new Process([PHP_BINARY, '-r', $probe], null, $environment, null, 10);

        try {
            $process->run();
            $this->assertTrue($process->isSuccessful());
            $actual = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $expected = array_fill_keys($credentialNames, false);
            $expected['path_present'] = true;
            $expected['mysql_test_login_file_present'] = true;
            $this->assertSame($expected, $actual);
            $this->assertStringNotContainsString('synthetic-sensitive-value', $process->getOutput());
            $this->assertStringNotContainsString('synthetic-sensitive-value', $process->getErrorOutput());
        } finally {
            $process->clearOutput();
            $process->clearErrorOutput();
        }
    }

    /** @return array<string, array{getenv: string|false, env_exists: bool, env_value: mixed, server_exists: bool, server_value: mixed}> */
    private function useSyntheticCredentialEnvironment(): array
    {
        $snapshot = [];
        $names = [
            'DB_PASSWORD', 'DB_USERNAME', 'WPCG_MYSQL_VERIFY_PASSWORD', 'WPCG_MYSQL_VERIFY_USERNAME',
            'MYSQL_PWD', 'MYSQL_ROOT_PASSWORD', 'PLATFORM_DB_BACKUP_VERIFY_DB_PASSWORD',
            'PLATFORM_DB_BACKUP_VERIFY_DB_USERNAME', 'DATABASE_URL', 'DB_URL', 'APP_KEY',
            'AWS_SECRET_ACCESS_KEY', 'CI_JOB_TOKEN',
        ];

        foreach ($names as $name) {
            $snapshot[$name] = [
                'getenv' => getenv($name),
                'env_exists' => array_key_exists($name, $_ENV),
                'env_value' => $_ENV[$name] ?? null,
                'server_exists' => array_key_exists($name, $_SERVER),
                'server_value' => $_SERVER[$name] ?? null,
            ];
            putenv($name.'=synthetic-sensitive-value');
            $_ENV[$name] = 'synthetic-sensitive-value';
            $_SERVER[$name] = 'synthetic-sensitive-value';
        }

        return $snapshot;
    }

    private function unlinkPrivateTestFile(string $path): bool
    {
        $temporaryRoot = realpath(sys_get_temp_dir());
        $parent = realpath(dirname($path));
        if (! is_string($temporaryRoot)
            || ! is_string($parent)
            || strcasecmp($temporaryRoot, $parent) !== 0
            || ! str_starts_with(basename($path), 'wpcg-mysql-')) {
            return false;
        }

        if (! file_exists($path) && ! is_link($path)) {
            return true;
        }

        $removed = @unlink($path);

        return $removed && ! file_exists($path) && ! is_link($path);
    }

    /** @return array{root: string, home: string, profile: string, appdata: string, login_file: string, files: array<int, string>} */
    private function createConflictingMysqlLoginPathFixture(string $storageRoot): array
    {
        $root = $storageRoot.DIRECTORY_SEPARATOR.'wpcg-conflicting-login-path-'.bin2hex(random_bytes(8));
        $home = $root.DIRECTORY_SEPARATOR.'home';
        $profile = $root.DIRECTORY_SEPARATOR.'profile';
        $appData = $root.DIRECTORY_SEPARATOR.'appdata';
        $appDataMysql = $appData.DIRECTORY_SEPARATOR.'MySQL';
        $loginFiles = [
            $home.DIRECTORY_SEPARATOR.'.mylogin.cnf',
            $profile.DIRECTORY_SEPARATOR.'.mylogin.cnf',
            $appDataMysql.DIRECTORY_SEPARATOR.'.mylogin.cnf',
            $root.DIRECTORY_SEPARATOR.'explicit-login.cnf',
        ];

        foreach ([$root, $home, $profile, $appData, $appDataMysql] as $directory) {
            if (! @mkdir($directory, 0700) || (DIRECTORY_SEPARATOR !== '\\' && (! @chmod($directory, 0700) || (fileperms($directory) & 0777) !== 0700))) {
                throw new \RuntimeException('Could not create a private conflicting-login-path test fixture.');
            }
        }

        foreach ($loginFiles as $loginFile) {
            $environment = $this->conflictingLoginPathProcessEnvironment($root, $home, $profile, $appData, $loginFile);
            $this->assertCredentialFreeHelperEnvironment($environment);
            $editor = new Process([
                'mysql_config_editor',
                'set',
                '--login-path=client',
                '--host=127.0.0.1',
                '--port=1',
                '--user=wpcg_conflicting_login_path',
            ], null, $environment, null, 10);
            try {
                $editor->run();
                $this->assertTrue($editor->isSuccessful(), 'Could not create the synthetic conflicting login-path fixture.');
                if (DIRECTORY_SEPARATOR !== '\\') {
                    $this->assertTrue(@chmod($loginFile, 0600));
                    $this->assertSame(0600, fileperms($loginFile) & 0777);
                }
            } finally {
                $editor->clearOutput();
                $editor->clearErrorOutput();
            }
        }

        return [
            'root' => $root,
            'home' => $home,
            'profile' => $profile,
            'appdata' => $appData,
            'login_file' => end($loginFiles),
            'files' => $loginFiles,
        ];
    }

    /** @param array{home: string, profile: string, appdata: string, login_file: string} $fixture
     * @return array<string, array{getenv: string|false, env_exists: bool, env_value: mixed, server_exists: bool, server_value: mixed}>
     */
    private function useConflictingMysqlLoginPathEnvironment(array $fixture): array
    {
        $values = [
            'HOME' => $fixture['home'],
            'USERPROFILE' => $fixture['profile'],
            'APPDATA' => $fixture['appdata'],
            'MYSQL_TEST_LOGIN_FILE' => $fixture['login_file'],
        ];
        $snapshot = [];

        foreach ($values as $name => $value) {
            $snapshot[$name] = [
                'getenv' => getenv($name),
                'env_exists' => array_key_exists($name, $_ENV),
                'env_value' => $_ENV[$name] ?? null,
                'server_exists' => array_key_exists($name, $_SERVER),
                'server_value' => $_SERVER[$name] ?? null,
            ];
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        return $snapshot;
    }

    /** @param array<string, array{getenv: string|false, env_exists: bool, env_value: mixed, server_exists: bool, server_value: mixed}> $snapshot */
    private function restoreEnvironmentSnapshot(array $snapshot): void
    {
        foreach ($snapshot as $name => $state) {
            if ($state['getenv'] === false) {
                putenv($name);
            } else {
                putenv($name.'='.$state['getenv']);
            }
            if ($state['env_exists']) {
                $_ENV[$name] = $state['env_value'];
            } else {
                unset($_ENV[$name]);
            }
            if ($state['server_exists']) {
                $_SERVER[$name] = $state['server_value'];
            } else {
                unset($_SERVER[$name]);
            }
        }
    }

    /** @param array{home: string, profile: string, appdata: string, login_file: string} $fixture
     * @param  array<string, mixed>  $verifier
     */
    private function assertConflictingLoginPathAffectsUnisolatedClient(array $fixture, array $verifier): void
    {
        $service = (new \ReflectionClass(PlatformDatabaseBackupService::class))->newInstanceWithoutConstructor();
        $defaultsMethod = new \ReflectionMethod(PlatformDatabaseBackupService::class, 'mysqlDefaultsFile');
        $commandMethod = new \ReflectionMethod(PlatformDatabaseBackupService::class, 'mysqlImportCommand');
        $defaults = $defaultsMethod->invoke($service, $verifier);
        $command = $commandMethod->invoke($service, $defaults, (string) ($verifier['database'] ?? 'wpcg_verifier_seed'));
        $command[] = '--execute=SELECT 1';
        $environment = $this->conflictingLoginPathProcessEnvironment(
            $fixture['root'],
            $fixture['home'],
            $fixture['profile'],
            $fixture['appdata'],
            $fixture['login_file'],
        );
        $this->assertCredentialFreeHelperEnvironment($environment);
        $process = new Process($command, null, $environment, null, 10);

        try {
            $process->run();
            $this->assertFalse($process->isSuccessful(), 'The synthetic conflicting login path did not affect the control client.');
            $diagnosticOutput = $process->getOutput().$process->getErrorOutput();
            $password = $verifier['password'] ?? null;
            if (is_string($password) && $password !== '') {
                $this->assertStringNotContainsString($password, $diagnosticOutput);
            }
        } finally {
            $process->clearOutput();
            $process->clearErrorOutput();
            $this->assertTrue($this->unlinkPrivateTestFile($defaults));
        }
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
        $loginPathEnvironmentSnapshot = [];
        $syntheticCredentialSnapshot = [];
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

            $activeStage = 'fixture_setup';
            $this->emitDiagnosticV2($activeStage, 'begin');
            $syntheticCredentialSnapshot = $this->useSyntheticCredentialEnvironment();
            try {
                $loginPathFixture = $this->createConflictingMysqlLoginPathFixture((string) $root);
                $loginPathEnvironmentSnapshot = $this->useConflictingMysqlLoginPathEnvironment($loginPathFixture);
                $this->assertConflictingLoginPathAffectsUnisolatedClient($loginPathFixture, config('database.connections.m9_platform_verify'));
            } finally {
                $this->restoreEnvironmentSnapshot($syntheticCredentialSnapshot);
                $syntheticCredentialSnapshot = [];
            }
            $this->emitDiagnosticV2($activeStage, 'complete');

            $activeStage = 'verify';
            $this->emitDiagnosticV2($activeStage, 'begin');
            try {
                $verified = $service->verify($backup);
            } finally {
                $this->restoreEnvironmentSnapshot($loginPathEnvironmentSnapshot);
                $loginPathEnvironmentSnapshot = [];
            }

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
            $this->assertMysqlClientFailsSafelyWhenDefaultsFileIsMissing();
        } catch (\Throwable $exception) {
            $primaryFailure = $exception;
            $this->emitDiagnosticV2($activeStage, 'primary_failure', $this->diagnosticCategoryV2($exception), $diagnosticCode);
            throw $exception;
        } finally {
            try {
                if ($syntheticCredentialSnapshot !== []) {
                    $this->restoreEnvironmentSnapshot($syntheticCredentialSnapshot);
                    $syntheticCredentialSnapshot = [];
                }
                if ($loginPathEnvironmentSnapshot !== []) {
                    $this->restoreEnvironmentSnapshot($loginPathEnvironmentSnapshot);
                    $loginPathEnvironmentSnapshot = [];
                }

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
