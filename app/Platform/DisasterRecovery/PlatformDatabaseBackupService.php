<?php

namespace App\Platform\DisasterRecovery;

use App\Events\Platform\PlatformRecoverySignal;
use App\Models\PlatformBackupDelivery;
use App\Models\PlatformDatabaseBackup;
use App\Owner\OwnerSettings;
use App\Privacy\PrivacyTombstoneJournal;
use App\Privacy\PrivacyTombstoneReplayApplier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

final class PlatformDatabaseBackupService
{
    public function __construct(
        private readonly PlatformBackupCryptography $cryptography,
        private readonly PlatformBackupStorage $storage,
    ) {}

    public function create(string $retentionClass = 'daily'): PlatformDatabaseBackup
    {
        return $this->createWithMode($retentionClass, false);
    }

    public function createManual(string $retentionClass = 'daily'): PlatformDatabaseBackup
    {
        return $this->createWithMode($retentionClass, true);
    }

    private function createWithMode(string $retentionClass, bool $manualRun): PlatformDatabaseBackup
    {
        $lock = Cache::lock('platform-database-backup:create', (int) config('platform_backup.timeout', 3600) + 300);
        if (! $lock->get()) {
            throw new RuntimeException('Another platform database backup is already in progress.');
        }
        try {
            return $this->createLocked($retentionClass, $manualRun);
        } finally {
            $lock->release();
        }
    }

    private function createLocked(string $retentionClass, bool $manualRun): PlatformDatabaseBackup
    {
        $backupSettings = app(OwnerSettings::class)->group('backups');
        if (! $manualRun && ! (bool) $backupSettings['backups.primary.enabled']) {
            throw new RuntimeException('Platform database backups are disabled.');
        }

        $deploymentEnvironment = (string) (config('platform.deployment_environment') ?: app()->environment());
        $backupDisk = (string) config('platform_backup.disk', 'platform_backups');
        $diskConfiguration = $this->storage->configuration();
        $configuredDiskDriver = $diskConfiguration['driver'] ?? null;
        $declaredStorageDriver = $this->storage->driver();

        if (! in_array($deploymentEnvironment, ['local', 'testing', 'staging', 'production'], true)) {
            throw new RuntimeException('Platform database backup requires an explicitly recognized deployment environment.');
        }
        if ($backupDisk !== 'platform_backups' || $configuredDiskDriver !== $declaredStorageDriver) {
            throw new RuntimeException('Platform backup disk does not match the dedicated platform storage configuration.');
        }
        if (in_array($deploymentEnvironment, ['staging', 'production'], true) && $configuredDiskDriver === 'local') {
            throw new RuntimeException('Deployed platform backups must use an independently managed remote storage disk.');
        }

        if (! in_array($retentionClass, ['daily', 'weekly', 'monthly'], true)) {
            throw new RuntimeException('Unsupported platform backup retention class.');
        }

        $connectionName = (string) (config('platform_backup.source_connection') ?: config('database.default'));
        $connection = DB::connection($connectionName);
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException("Platform database backup does not support the {$driver} driver.");
        }

        $uuid = (string) str()->uuid();
        $createdAt = now();
        $artifactExtension = $driver === 'sqlite' ? 'sqlite.gz' : 'sql.gz';
        $objectKey = trim(config('platform_backup.prefix'), '/').'/'.$createdAt->format('Y/m/d')."/{$uuid}.{$artifactExtension}.enc";
        $manifestKey = $objectKey.'.manifest.json';
        $backup = PlatformDatabaseBackup::query()->create([
            'uuid' => $uuid,
            'source_environment' => (string) (config('platform.deployment_environment') ?: app()->environment()),
            'database_identity_sha256' => hash('sha256', $connectionName.'|'.$connection->getDatabaseName()),
            'database_driver' => $driver,
            'database_version' => $this->databaseVersion($connection),
            'release_sha' => config('platform_backup.release_sha'),
            'retention_class' => $retentionClass,
            'status' => 'creating',
            'verification_status' => 'pending',
            'storage_disk' => config('platform_backup.disk'),
            'object_key' => $objectKey,
            'manifest_object_key' => $manifestKey,
            'created_for_backup_at' => $createdAt,
            'expires_at' => $createdAt->copy()->addDays(match ($retentionClass) {
                'daily' => (int) $backupSettings['backups.retention.daily'],
                'weekly' => (int) $backupSettings['backups.retention.weekly'] * 7,
                'monthly' => (int) $backupSettings['backups.retention.monthly'] * 30,
            }),
            'manifest' => [
                'format' => $driver === 'sqlite' ? 'sqlite-database-image' : 'mysql-sql-dump',
                'compression' => 'gzip',
                'consistency' => $driver === 'mysql' ? 'single-transaction; routines/triggers/events included' : 'sqlite-vacuum-into snapshot',
            ],
        ]);
        $delivery = PlatformBackupDelivery::query()->create([
            'platform_database_backup_id' => $backup->id,
            'destination_key' => 'primary',
            'destination_type' => (string) $configuredDiskDriver,
            'storage_disk' => $backupDisk,
            'object_key' => $objectKey,
            'manifest_object_key' => $manifestKey,
            'upload_status' => 'pending',
            'verification_status' => 'pending',
            'retention_status' => 'retained',
        ]);

        $work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcg-platform-db-'.$uuid;
        if (! mkdir($work, 0700, true) && ! is_dir($work)) {
            $backup->forceFill(['status' => 'failed', 'failure_code' => 'WORKDIR_CREATE_FAILED'])->save();
            $delivery->forceFill(['upload_status' => 'failed', 'verification_status' => 'failed', 'failure_code' => 'WORKDIR_CREATE_FAILED'])->save();
            event(new PlatformRecoverySignal('platform_db_backup_failed', ['backup_uuid' => $uuid, 'reason' => 'workdir_create_failed']));
            throw new RuntimeException('Could not create private platform backup work directory.');
        }

        try {
            $rawPath = $work.DIRECTORY_SEPARATOR.($driver === 'sqlite' ? 'database.sqlite' : 'database.sql');
            $snapshotLock = Cache::lock(PrivacyTombstoneReplayApplier::SNAPSHOT_LOCK, (int) config('platform_backup.timeout', 3600) + 300);
            if (! $snapshotLock->get()) {
                throw new RuntimeException('A privacy action or another recovery snapshot is already in progress.');
            }
            try {
                $journal = app(PrivacyTombstoneJournal::class);
                $journalStatus = $journal->readiness();
                $journalWatermark = ($journalStatus['state'] ?? null) === 'ready' ? $journal->watermark() : null;
                if ($driver === 'sqlite') {
                    $this->snapshotSqlite($connection, $rawPath);
                } else {
                    $this->snapshotMysql($connectionName, $connection->getConfig(), $connection->getDatabaseName(), $rawPath);
                }
            } finally {
                $snapshotLock->release();
            }
            chmod($rawPath, 0600);

            $compressedPath = $work.DIRECTORY_SEPARATOR.'database.'.$artifactExtension;
            $encryptedPath = $compressedPath.'.enc';
            $this->gzipFile($rawPath, $compressedPath);
            chmod($compressedPath, 0600);
            $encryption = $this->cryptography->encryptFile($compressedPath, $encryptedPath);
            $size = filesize($encryptedPath);
            if (! is_int($size) || $size < 1) {
                throw new RuntimeException('Platform database backup produced an empty artifact.');
            }

            $stream = fopen($encryptedPath, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Could not read completed platform database backup.');
            }
            $disk = $this->storage->disk();
            try {
                if (! $disk->put($backup->object_key, $stream, ['visibility' => 'private'])) {
                    throw new RuntimeException('Platform backup destination did not accept the artifact.');
                }
            } finally {
                fclose($stream);
            }

            $sha256 = hash_file('sha256', $encryptedPath);
            if (! is_string($sha256)) {
                throw new RuntimeException('Could not calculate platform backup artifact integrity hash.');
            }
            $manifest = array_merge($backup->manifest ?? [], [
                'backup_uuid' => $backup->uuid,
                'created_at' => $backup->created_for_backup_at->toIso8601String(),
                'source_environment' => $backup->source_environment,
                'database_identity_sha256' => $backup->database_identity_sha256,
                'database_driver' => $backup->database_driver,
                'database_version' => $backup->database_version,
                'release_sha' => $backup->release_sha,
                'retention_class' => $backup->retention_class,
                'object_key' => $backup->object_key,
                'size_bytes' => $size,
                'sha256' => $sha256,
                'encryption' => $encryption,
                'privacy_journal' => [
                    'state' => ($journalStatus['state'] ?? null) === 'ready' ? 'verified' : 'blocked',
                    'watermark' => $journalWatermark,
                    'reason' => (string) ($journalStatus['reason'] ?? 'unknown'),
                ],
                'table_manifest' => $this->tableManifest($connectionName),
            ]);
            $manifest['manifest_hmac'] = $this->cryptography->signManifest($manifest, $encryption['key_id']);
            if (! $disk->put($backup->manifest_object_key, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), ['visibility' => 'private'])) {
                throw new RuntimeException('Platform backup destination did not accept the authenticated manifest.');
            }

            $backup->forceFill([
                'status' => 'stored',
                'size_bytes' => $size,
                'sha256' => $sha256,
                'manifest' => $manifest,
            ])->save();
            $delivery->forceFill([
                'upload_status' => 'uploaded',
                'verification_status' => 'pending',
                'size_bytes' => $size,
                'sha256' => $sha256,
                'uploaded_at' => now(),
            ])->save();

            return $backup->fresh();
        } catch (\Throwable $error) {
            $backup->forceFill(['status' => 'failed', 'verification_status' => 'failed', 'failure_code' => 'PLATFORM_BACKUP_FAILED'])->save();
            $delivery->forceFill(['upload_status' => 'failed', 'verification_status' => 'failed', 'failure_code' => 'PLATFORM_BACKUP_FAILED'])->save();
            event(new PlatformRecoverySignal('platform_db_backup_failed', ['backup_uuid' => $uuid, 'reason' => 'creation_failed']));
            report($error);
            throw $error;
        } finally {
            foreach (glob($work.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            @rmdir($work);
        }
    }

    /** Verifies the stored bytes and gzip stream; SQL import is performed by the isolated recovery drill. */
    public function verify(PlatformDatabaseBackup $backup): bool
    {
        $diagnosticStage = 'artifact_integrity';
        $diagnosticExceptionReported = false;
        $diagnosticBodyState = 'pending';
        $this->emitVerificationDiagnostic($diagnosticStage, 'begin');
        $disk = $this->storage->disk($backup->storage_disk);
        if (! $disk->exists($backup->object_key)) {
            $diagnosticBodyState = 'return_false_before_outer_cleanup';
            $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'backup_failure', 'OBJECT_MISSING', $diagnosticBodyState);
            $this->markVerificationFailure($backup, 'OBJECT_MISSING');

            return false;
        }

        if (! $disk->exists($backup->manifest_object_key)) {
            $diagnosticBodyState = 'return_false_before_outer_cleanup';
            $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'backup_failure', 'MANIFEST_OBJECT_MISSING', $diagnosticBodyState);
            $this->markVerificationFailure($backup, 'MANIFEST_OBJECT_MISSING');

            return false;
        }
        try {
            $manifest = json_decode($disk->get($backup->manifest_object_key), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $diagnosticBodyState = 'return_false_before_outer_cleanup';
            $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'backup_failure', 'MANIFEST_OBJECT_INVALID', $diagnosticBodyState);
            $this->markVerificationFailure($backup, 'MANIFEST_OBJECT_INVALID');

            return false;
        }
        if (! is_array($manifest)
            || ($manifest['backup_uuid'] ?? null) !== $backup->uuid
            || ($manifest['object_key'] ?? null) !== $backup->object_key
            || (int) ($manifest['size_bytes'] ?? 0) !== (int) $backup->size_bytes
            || ($manifest['database_identity_sha256'] ?? null) !== $backup->database_identity_sha256
            || ($manifest['database_driver'] ?? null) !== $backup->database_driver
            || ! is_array($manifest['table_manifest'] ?? null)
            || ! is_array(data_get($backup->manifest, 'table_manifest'))
            || ! hash_equals(
                hash('sha256', $this->canonicalJson($manifest['table_manifest'])),
                hash('sha256', $this->canonicalJson(data_get($backup->manifest, 'table_manifest'))),
            )
            || ! is_string($manifest['sha256'] ?? null)
            || ! hash_equals((string) $backup->sha256, $manifest['sha256'])) {
            $diagnosticBodyState = 'return_false_before_outer_cleanup';
            $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'backup_failure', 'MANIFEST_METADATA_MISMATCH', $diagnosticBodyState);
            $this->markVerificationFailure($backup, 'MANIFEST_METADATA_MISMATCH');

            return false;
        }

        $encryption = $manifest['encryption'] ?? null;
        $encryptedArtifact = is_array($encryption);
        if ($encryptedArtifact
            && (! $this->cryptography->verifyManifest($manifest)
                || ! hash_equals((string) data_get($backup->manifest, 'manifest_hmac'), (string) ($manifest['manifest_hmac'] ?? '')))) {
            $diagnosticBodyState = 'return_false_before_outer_cleanup';
            $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'backup_failure', 'MANIFEST_AUTHENTICATION_FAILED', $diagnosticBodyState);
            $this->markVerificationFailure($backup, 'MANIFEST_AUTHENTICATION_FAILED');

            return false;
        }

        $stream = $disk->readStream($backup->object_key);
        if (! is_resource($stream)) {
            $diagnosticBodyState = 'return_false_before_outer_cleanup';
            $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'backup_failure', 'OBJECT_UNREADABLE', $diagnosticBodyState);
            $this->markVerificationFailure($backup, 'OBJECT_UNREADABLE');

            return false;
        }

        $path = tempnam(sys_get_temp_dir(), 'wpcg-verify-');
        if ($path === false) {
            fclose($stream);
            $diagnosticBodyState = 'return_false_before_outer_cleanup';
            $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'filesystem_error', 'VERIFY_TEMP_FAILED', $diagnosticBodyState);
            $this->markVerificationFailure($backup, 'VERIFY_TEMP_FAILED');

            return false;
        }
        chmod($path, 0600);
        $decompressedPath = tempnam(sys_get_temp_dir(), 'wpcg-db-check-');
        if ($decompressedPath === false) {
            fclose($stream);
            @unlink($path);
            $diagnosticBodyState = 'return_false_before_outer_cleanup';
            $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'filesystem_error', 'VERIFY_TEMP_FAILED', $diagnosticBodyState);
            $this->markVerificationFailure($backup, 'VERIFY_TEMP_FAILED');

            return false;
        }
        chmod($decompressedPath, 0600);
        $compressedPath = $path;
        if ($encryptedArtifact) {
            $compressedPath = tempnam(sys_get_temp_dir(), 'wpcg-db-decrypt-');
            if ($compressedPath === false) {
                fclose($stream);
                @unlink($path);
                @unlink($decompressedPath);
                $diagnosticBodyState = 'return_false_before_outer_cleanup';
                $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'filesystem_error', 'VERIFY_TEMP_FAILED', $diagnosticBodyState);
                $this->markVerificationFailure($backup, 'VERIFY_TEMP_FAILED');

                return false;
            }
            chmod($compressedPath, 0600);
        }

        try {
            $target = fopen($path, 'wb');
            if ($target === false) {
                $diagnosticBodyState = 'return_false_pending_cleanup';
                $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'filesystem_error', 'VERIFY_TEMP_FAILED', 'return_false_pending_cleanup');
                $this->markVerificationFailure($backup, 'VERIFY_TEMP_FAILED');

                return false;
            }
            $hash = hash_init('sha256');
            $bytes = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    fclose($target);
                    $diagnosticBodyState = 'return_false_pending_cleanup';
                    $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'filesystem_error', 'OBJECT_READ_FAILED', 'return_false_pending_cleanup');
                    $this->markVerificationFailure($backup, 'OBJECT_READ_FAILED');

                    return false;
                }
                if ($chunk === '') {
                    continue;
                }
                hash_update($hash, $chunk);
                $bytes += strlen($chunk);
                if (fwrite($target, $chunk) !== strlen($chunk)) {
                    fclose($target);
                    $diagnosticBodyState = 'return_false_pending_cleanup';
                    $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'filesystem_error', 'VERIFY_TEMP_WRITE_FAILED', 'return_false_pending_cleanup');
                    $this->markVerificationFailure($backup, 'VERIFY_TEMP_WRITE_FAILED');

                    return false;
                }
            }
            fclose($target);
            $actualHash = hash_final($hash);

            if ($bytes < 1 || $bytes !== (int) $backup->size_bytes || ! hash_equals((string) $backup->sha256, $actualHash)) {
                $diagnosticBodyState = 'return_false_pending_cleanup';
                $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'backup_failure', 'SIZE_OR_HASH_MISMATCH', 'return_false_pending_cleanup');
                $this->markVerificationFailure($backup, 'SIZE_OR_HASH_MISMATCH');

                return false;
            }

            $this->emitVerificationDiagnostic($diagnosticStage, 'complete');

            if ($encryptedArtifact) {
                $diagnosticStage = 'artifact_decrypt';
                $this->emitVerificationDiagnostic($diagnosticStage, 'begin');
                try {
                    $this->cryptography->decryptFile($path, $compressedPath, $encryption);
                } catch (\Throwable) {
                    $diagnosticBodyState = 'return_false_pending_cleanup';
                    $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'backup_failure', 'CIPHERTEXT_AUTHENTICATION_FAILED', 'return_false_pending_cleanup');
                    $this->markVerificationFailure($backup, 'CIPHERTEXT_AUTHENTICATION_FAILED');

                    return false;
                }
                $this->emitVerificationDiagnostic($diagnosticStage, 'complete');
            }

            $diagnosticStage = 'artifact_gzip';
            $this->emitVerificationDiagnostic($diagnosticStage, 'begin');
            $gzip = gzopen($compressedPath, 'rb');
            if ($gzip === false) {
                $diagnosticBodyState = 'return_false_pending_cleanup';
                $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'backup_failure', 'INVALID_GZIP', 'return_false_pending_cleanup');
                $this->markVerificationFailure($backup, 'INVALID_GZIP');

                return false;
            }
            $decompressed = fopen($decompressedPath, 'wb');
            if ($decompressed === false) {
                gzclose($gzip);
                $diagnosticBodyState = 'return_false_pending_cleanup';
                $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'filesystem_error', 'VERIFY_TEMP_FAILED', 'return_false_pending_cleanup');
                $this->markVerificationFailure($backup, 'VERIFY_TEMP_FAILED');

                return false;
            }
            $decompressedBytes = 0;
            while (! gzeof($gzip)) {
                $chunk = gzread($gzip, 1024 * 1024);
                if ($chunk === false) {
                    gzclose($gzip);
                    fclose($decompressed);
                    $diagnosticBodyState = 'return_false_pending_cleanup';
                    $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'backup_failure', 'INVALID_GZIP', 'return_false_pending_cleanup');
                    $this->markVerificationFailure($backup, 'INVALID_GZIP');

                    return false;
                }
                if ($chunk !== '' && fwrite($decompressed, $chunk) !== strlen($chunk)) {
                    gzclose($gzip);
                    fclose($decompressed);
                    $diagnosticBodyState = 'return_false_pending_cleanup';
                    $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'filesystem_error', 'VERIFY_TEMP_WRITE_FAILED', 'return_false_pending_cleanup');
                    $this->markVerificationFailure($backup, 'VERIFY_TEMP_WRITE_FAILED');

                    return false;
                }
                $decompressedBytes += strlen($chunk);
            }
            gzclose($gzip);
            fclose($decompressed);
            if ($decompressedBytes < 1) {
                $diagnosticBodyState = 'return_false_pending_cleanup';
                $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'backup_failure', 'EMPTY_DECOMPRESSED_ARTIFACT', 'return_false_pending_cleanup');
                $this->markVerificationFailure($backup, 'EMPTY_DECOMPRESSED_ARTIFACT');

                return false;
            }

            $this->emitVerificationDiagnostic($diagnosticStage, 'complete');

            if ($backup->database_driver === 'sqlite') {
                $diagnosticStage = 'sqlite_sanity';
                $this->emitVerificationDiagnostic($diagnosticStage, 'begin');
                if (! $this->verifySqliteImage($decompressedPath, $backup)) {
                    $diagnosticBodyState = 'return_false_pending_cleanup';
                    $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'backup_failure', 'DATABASE_IMPORT_OR_SANITY_FAILED', 'return_false_pending_cleanup');
                    $this->markVerificationFailure($backup, 'DATABASE_IMPORT_OR_SANITY_FAILED');

                    return false;
                }
                $this->emitVerificationDiagnostic($diagnosticStage, 'complete');
            }

            if ($backup->database_driver === 'mysql' && ! filled(config('platform_backup.verification_connection'))) {
                $diagnosticStage = 'mysql_preflight';
                $this->emitVerificationDiagnostic($diagnosticStage, 'begin');
                $backup->forceFill([
                    'status' => 'integrity_verified',
                    'verification_status' => 'import_verification_required',
                    'verification_method' => 'stored-object-sha256-and-gzip-integrity',
                    'failure_code' => 'DISPOSABLE_MYSQL_IMPORT_NOT_CONFIGURED',
                ])->save();
                event(new PlatformRecoverySignal('platform_db_backup_verification_failed', ['backup_uuid' => $backup->uuid, 'reason' => 'disposable_mysql_import_not_configured']));
                $diagnosticBodyState = 'return_false_pending_cleanup';
                $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'backup_failure', 'DISPOSABLE_MYSQL_IMPORT_NOT_CONFIGURED', 'return_false_pending_cleanup');

                return false;
            }

            if ($backup->database_driver === 'mysql') {
                $importFailureCode = 'DISPOSABLE_MYSQL_IMPORT_FAILED';
                if (! $this->verifyMysqlDump($decompressedPath, $backup, $importFailureCode, $diagnosticStage, $diagnosticExceptionReported, $diagnosticBodyState)) {
                    $diagnosticBodyState = 'return_false_pending_cleanup';
                    $this->markVerificationFailure($backup, $importFailureCode);

                    return false;
                }
            }

            $backup->forceFill([
                'status' => 'verified',
                'verification_status' => 'verified',
                'verification_method' => $backup->database_driver === 'sqlite'
                    ? 'stored-object-sha256-gzip-and-sqlite-quick-check'
                    : 'stored-object-sha256-gzip-and-disposable-mysql-import',
                'verified_at' => now(),
            ])->save();
            $backup->deliveries()->where('destination_key', 'primary')->update([
                'verification_status' => 'verified', 'failure_code' => null, 'verified_at' => now(), 'updated_at' => now(),
            ]);
            event(new PlatformRecoverySignal('platform_db_backup_verified', ['backup_uuid' => $backup->uuid, 'retention_class' => $backup->retention_class]));

            $diagnosticBodyState = 'return_true_pending_cleanup';
            $this->emitVerificationDiagnostic($diagnosticStage, 'body_result', 'none', 'none', $diagnosticBodyState);
            return true;
        } catch (\Throwable $error) {
            if (! $diagnosticExceptionReported) {
                $diagnosticBodyState = 'handled_exception_return_false_pending_cleanup';
                $this->emitVerificationDiagnostic($diagnosticStage, 'primary_exception', $this->verificationDiagnosticCategory($error), 'VERIFICATION_ERROR', 'exception_pending_cleanup');
            } else {
                $diagnosticBodyState = 'handled_exception_return_false_pending_cleanup';
            }
            report($error);
            $this->markVerificationFailure($backup, 'VERIFICATION_ERROR');

            $this->emitVerificationDiagnostic($diagnosticStage, 'body_result', 'none', 'VERIFICATION_ERROR', $diagnosticBodyState);
            return false;
        } finally {
            $diagnosticStage = 'service_cleanup_temp_artifacts';
            $this->emitVerificationDiagnostic($diagnosticStage, 'begin', 'none', 'none', $diagnosticBodyState);
            try {
                fclose($stream);
                $temporaryPaths = array_unique([$path, $compressedPath, $decompressedPath]);
                foreach ($this->cleanupTemporaryArtifacts($temporaryPaths, $diagnosticBodyState) as $diagnostic) {
                    $this->emitVerificationDiagnostic(
                        $diagnosticStage,
                        $diagnostic['event'],
                        $diagnostic['category'],
                        $diagnostic['code'],
                        $diagnostic['body_state'],
                    );
                }
            } catch (\Throwable $error) {
                $this->emitVerificationDiagnostic($diagnosticStage, 'cleanup_failure', 'cleanup_error', 'SERVICE_TEMP_ARTIFACT_CLEANUP_FAILED', $diagnosticBodyState);
                throw $error;
            }
        }
    }

    /** @param list<string> $temporaryPaths
     *  @return list<array{event: string, category: string, code: string, body_state: string}>
     */
    private function cleanupTemporaryArtifacts(array $temporaryPaths, string $diagnosticBodyState): array
    {
        $cleanupFailed = false;
        $diagnostics = [];
        foreach (array_unique($temporaryPaths) as $temporaryPath) {
            if (file_exists($temporaryPath) && ! @unlink($temporaryPath)) {
                $cleanupFailed = true;
                $diagnostics[] = [
                    'event' => 'cleanup_observation',
                    'category' => 'cleanup_error',
                    'code' => 'SERVICE_TEMP_ARTIFACT_UNLINK_FAILED',
                    'body_state' => $diagnosticBodyState,
                ];
            }
        }

        if (! $cleanupFailed) {
            $diagnostics[] = [
                'event' => 'complete',
                'category' => 'none',
                'code' => 'none',
                'body_state' => $diagnosticBodyState,
            ];
        }

        return $diagnostics;
    }

    /** Return only the watermark from the authenticated manifest bound to this backup object. */
    public function recoverySnapshotWatermark(PlatformDatabaseBackup $backup): ?string
    {
        $disk = $this->storage->disk($backup->storage_disk);
        if (! $disk->exists($backup->object_key) || ! $disk->exists($backup->manifest_object_key)) {
            throw new RuntimeException('privacy_restore_manifest_unavailable');
        }

        try {
            $manifest = json_decode($disk->get($backup->manifest_object_key), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new RuntimeException('privacy_restore_manifest_invalid', previous: $exception);
        }
        if (! is_array($manifest)
            || ($manifest['backup_uuid'] ?? null) !== $backup->uuid
            || ($manifest['object_key'] ?? null) !== $backup->object_key
            || ! $this->cryptography->verifyManifest($manifest)) {
            throw new RuntimeException('privacy_restore_manifest_integrity_failed');
        }

        $stream = $disk->readStream($backup->object_key);
        if (! is_resource($stream)) {
            throw new RuntimeException('privacy_restore_backup_unavailable');
        }
        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('privacy_restore_backup_unavailable');
                }
                if ($chunk !== '') {
                    hash_update($hash, $chunk);
                    $bytes += strlen($chunk);
                }
            }
        } finally {
            fclose($stream);
        }
        if (! is_string($manifest['sha256'] ?? null)
            || ! is_int($manifest['size_bytes'] ?? null)
            || $bytes !== $manifest['size_bytes']
            || ! hash_equals($manifest['sha256'], hash_final($hash))) {
            throw new RuntimeException('privacy_restore_backup_integrity_failed');
        }

        $journal = $manifest['privacy_journal'] ?? null;
        $watermark = is_array($journal) ? ($journal['watermark'] ?? null) : null;
        if (! is_array($journal) || ($journal['state'] ?? null) !== 'verified'
            || ($watermark !== null && (! is_string($watermark) || ! preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $watermark)))) {
            throw new RuntimeException('privacy_restore_watermark_unverified');
        }

        return $watermark;
    }

    private function snapshotSqlite($connection, string $destination): void
    {
        $source = (string) $connection->getDatabaseName();
        if ($source === ':memory:' || ! is_file($source)) {
            throw new RuntimeException('SQLite platform backup requires a file-backed source database.');
        }
        $literal = str_replace("'", "''", $destination);
        $connection->unprepared("VACUUM INTO '{$literal}'");
    }

    private function snapshotMysql(string $connectionName, array $config, string $database, string $destination): void
    {
        $defaults = tempnam(sys_get_temp_dir(), 'wpcg-mysql-');
        if ($defaults === false) {
            throw new RuntimeException('Could not allocate private MySQL client configuration.');
        }
        chmod($defaults, 0600);
        try {
            $lines = ['[client]', 'user='.$this->mysqlOption((string) ($config['username'] ?? ''))];
            if ((string) ($config['password'] ?? '') !== '') {
                $lines[] = 'password='.$this->mysqlOption((string) $config['password']);
            }
            if (! empty($config['unix_socket'])) {
                $lines[] = 'socket='.$this->mysqlOption((string) $config['unix_socket']);
            } else {
                $lines[] = 'host='.$this->mysqlOption((string) ($config['host'] ?? '127.0.0.1'));
                $lines[] = 'port='.(int) ($config['port'] ?? 3306);
            }
            if (file_put_contents($defaults, implode("\n", $lines)."\n", LOCK_EX) === false) {
                throw new RuntimeException('Could not write private MySQL client configuration.');
            }
            $process = new Process([
                (string) config('platform_backup.mysql_dump_binary'),
                '--defaults-extra-file='.$defaults,
                '--single-transaction', '--routines', '--triggers', '--events', '--hex-blob', '--skip-lock-tables', '--no-tablespaces',
                '--result-file='.$destination,
                $database,
            ], null, ['MYSQL_PWD' => null], null, (int) config('platform_backup.timeout'));
            $process->run();
            if (! $process->isSuccessful() || ! is_file($destination) || filesize($destination) < 1) {
                throw new RuntimeException('mysqldump failed for connection '.$connectionName.' with exit code '.$process->getExitCode().'.');
            }
        } finally {
            @unlink($defaults);
        }
    }

    private function mysqlOption(string $value): string
    {
        return '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value).'"';
    }

    private function gzipFile(string $source, string $destination): void
    {
        $input = fopen($source, 'rb');
        $output = gzopen($destination, 'wb9');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                gzclose($output);
            }
            throw new RuntimeException('Could not create compressed platform database backup.');
        }
        try {
            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('Could not stream platform database compression.');
                }
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $written = gzwrite($output, substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Could not stream platform database compression.');
                    }
                    $offset += $written;
                }
            }
        } finally {
            fclose($input);
            gzclose($output);
        }
    }

    private function databaseVersion($connection): ?string
    {
        try {
            return match ($connection->getDriverName()) {
                'mysql' => (string) $connection->selectOne('select version() as version')->version,
                'sqlite' => (string) $connection->selectOne('select sqlite_version() as version')->version,
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{tables: array<int, string>, critical_counts: array<string, int>} */
    private function tableManifest(string $connectionName): array
    {
        $connection = DB::connection($connectionName);
        $schema = $connection->getDriverName() === 'mysql' ? $connection->getDatabaseName() : null;
        $tables = $connection->getSchemaBuilder()->getTableListing($schema, false);
        if ($connection->getDriverName() === 'sqlite') {
            $tables = array_map(fn (string $table) => str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table, $tables);
        }
        sort($tables);
        $critical = (array) config('platform_backup.critical_tables', []);
        $counts = [];
        foreach (array_intersect($critical, $tables) as $table) {
            $counts[$table] = (int) $connection->table($table)->count();
        }

        return ['tables' => array_values($tables), 'critical_counts' => $counts];
    }

    private function verifySqliteImage(string $imagePath, PlatformDatabaseBackup $backup): bool
    {
        try {
            $pdo = new \PDO('sqlite:'.$imagePath);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            if ($pdo->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
                return false;
            }
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
            if ($tables === []) {
                return false;
            }
            $expected = (array) data_get($backup->manifest, 'table_manifest.tables', []);
            if ($expected !== [] && array_values($tables) !== $expected) {
                return false;
            }
            foreach ((array) data_get($backup->manifest, 'table_manifest.critical_counts', []) as $table => $count) {
                if (! in_array($table, $tables, true) || (int) $pdo->query('SELECT COUNT(*) FROM "'.str_replace('"', '""', $table).'"')->fetchColumn() !== (int) $count) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function verifyMysqlDump(
        string $dumpPath,
        PlatformDatabaseBackup $backup,
        ?string &$failureCode,
        string &$diagnosticStage,
        bool &$diagnosticExceptionReported,
        string &$diagnosticBodyState,
    ): bool {
        $diagnosticStage = 'mysql_preflight';
        $diagnosticBodyState = 'pending';
        $this->emitVerificationDiagnostic($diagnosticStage, 'begin', 'none', 'none', $diagnosticBodyState);
        $connectionName = (string) config('platform_backup.verification_connection');
        $target = config("database.connections.{$connectionName}");
        if (! is_array($target) || ($target['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException('The disposable platform backup verification connection must be MySQL.');
        }
        if (! config('platform_backup.verification_isolated')) {
            throw new RuntimeException('MySQL import verification requires an explicitly confirmed isolated disposable database endpoint.');
        }
        $prefix = (string) config('platform_backup.verification_database_prefix');
        if (! preg_match('/^wpcg_m9_dr_[A-Za-z0-9_]{0,32}$/', $prefix)) {
            throw new RuntimeException('Configure a dedicated wpcg_m9_dr_ disposable database prefix for MySQL import verification.');
        }
        $scratch = rtrim($prefix, '_').'_'.str_replace('-', '', (string) str()->uuid());
        $quoted = '`'.str_replace('`', '``', $scratch).'`';
        $admin = DB::connection($connectionName);
        $created = false;
        $dynamicConnection = 'm9_dr_verify_'.substr(hash('sha256', $scratch), 0, 12);
        $primaryExceptionReported = false;
        try {
            $diagnosticStage = 'mysql_create_database';
            $this->emitVerificationDiagnostic($diagnosticStage, 'begin', 'none', 'none', $diagnosticBodyState);
            $admin->unprepared("CREATE DATABASE {$quoted} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $created = true;
            $this->emitVerificationDiagnostic($diagnosticStage, 'complete', 'none', 'none', $diagnosticBodyState);

            $diagnosticStage = 'mysql_client_import';
            $this->emitVerificationDiagnostic($diagnosticStage, 'begin', 'none', 'none', $diagnosticBodyState);
            $defaults = $this->mysqlDefaultsFile($target);
            $input = fopen($dumpPath, 'rb');
            if ($input === false) {
                @unlink($defaults);
                $diagnosticBodyState = 'return_false_pending_cleanup';
                $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'filesystem_error', 'MYSQL_DUMP_INPUT_OPEN_FAILED', $diagnosticBodyState);

                return false;
            }
            try {
                $process = new Process([
                    (string) config('platform_backup.mysql_binary'),
                    '--defaults-extra-file='.$defaults,
                    '--database='.$scratch,
                    '--binary-mode',
                ], null, null, $input, (int) config('platform_backup.timeout'));
                $process->run();
                if (! $process->isSuccessful()) {
                    $failureCode = 'MYSQL_IMPORT_PROCESS_FAILED_EXIT_'.(string) $process->getExitCode();
                    $diagnosticBodyState = 'return_false_pending_cleanup';
                    $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'process_error', 'MYSQL_IMPORT_PROCESS_FAILED', $diagnosticBodyState);

                    return false;
                }
                $this->emitVerificationDiagnostic($diagnosticStage, 'complete', 'none', 'none', $diagnosticBodyState);
            } catch (\Throwable $error) {
                $diagnosticBodyState = 'exception_pending_cleanup';
                $primaryExceptionReported = true;
                $diagnosticExceptionReported = true;
                $this->emitVerificationDiagnostic(
                    $diagnosticStage,
                    'primary_exception',
                    $this->verificationDiagnosticCategory($error),
                    'MYSQL_IMPORT_PROCESS_FAILED',
                    $diagnosticBodyState,
                );
                throw $error;
            } finally {
                $diagnosticStage = 'mysql_process_cleanup';
                $this->emitVerificationDiagnostic($diagnosticStage, 'begin', 'none', 'none', $diagnosticBodyState);
                try {
                    fclose($input);
                    @unlink($defaults);
                } catch (\Throwable $error) {
                    $primaryExceptionReported = true;
                    $diagnosticExceptionReported = true;
                    $this->emitVerificationDiagnostic($diagnosticStage, 'cleanup_failure', 'cleanup_error', 'MYSQL_PROCESS_CLEANUP_FAILED', $diagnosticBodyState);
                    throw $error;
                }
                $this->emitVerificationDiagnostic($diagnosticStage, 'complete', 'none', 'none', $diagnosticBodyState);
            }

            $diagnosticStage = 'mysql_schema_check';
            $this->emitVerificationDiagnostic($diagnosticStage, 'begin', 'none', 'none', $diagnosticBodyState);
            config(["database.connections.{$dynamicConnection}" => array_merge($target, ['database' => $scratch])]);
            DB::purge($dynamicConnection);
            $restored = DB::connection($dynamicConnection);
            $tables = $restored->getSchemaBuilder()->getTableListing($scratch, false);
            sort($tables);
            $expectedTables = (array) data_get($backup->manifest, 'table_manifest.tables', []);
            if ($tables === [] || ($expectedTables !== [] && $tables !== $expectedTables)) {
                Log::warning('Platform database import table set mismatch.', [
                    'expected_count' => count($expectedTables),
                    'actual_count' => count($tables),
                    'missing_tables' => array_values(array_diff($expectedTables, $tables)),
                    'unexpected_tables' => array_values(array_diff($tables, $expectedTables)),
                ]);
                $failureCode = 'MYSQL_IMPORT_TABLE_SET_MISMATCH';
                $diagnosticBodyState = 'return_false_pending_cleanup';
                $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'backup_failure', 'MYSQL_IMPORT_TABLE_SET_MISMATCH', $diagnosticBodyState);

                return false;
            }
            $this->emitVerificationDiagnostic($diagnosticStage, 'complete', 'none', 'none', $diagnosticBodyState);

            $diagnosticStage = 'mysql_critical_count';
            $this->emitVerificationDiagnostic($diagnosticStage, 'begin', 'none', 'none', $diagnosticBodyState);
            foreach ((array) data_get($backup->manifest, 'table_manifest.critical_counts', []) as $table => $count) {
                if (! in_array($table, $tables, true) || (int) $restored->table($table)->count() !== (int) $count) {
                    $failureCode = 'MYSQL_IMPORT_CRITICAL_COUNT_MISMATCH';
                    $diagnosticBodyState = 'return_false_pending_cleanup';
                    $this->emitVerificationDiagnostic($diagnosticStage, 'primary_failure', 'backup_failure', 'MYSQL_IMPORT_CRITICAL_COUNT_MISMATCH', $diagnosticBodyState);

                    return false;
                }
            }

            $this->emitVerificationDiagnostic($diagnosticStage, 'complete', 'none', 'none', $diagnosticBodyState);
            $diagnosticBodyState = 'return_true_pending_cleanup';
            $this->emitVerificationDiagnostic($diagnosticStage, 'body_result', 'none', 'none', $diagnosticBodyState);

            return true;
        } catch (\Throwable $error) {
            if (! $primaryExceptionReported) {
                $diagnosticBodyState = 'exception_pending_cleanup';
                $diagnosticExceptionReported = true;
                $this->emitVerificationDiagnostic(
                    $diagnosticStage,
                    'primary_exception',
                    $this->verificationDiagnosticCategory($error),
                    $this->verificationDiagnosticCodeForStage($diagnosticStage),
                    $diagnosticBodyState,
                );
            }
            throw $error;
        } finally {
            $diagnosticStage = 'service_cleanup_connection_purge';
            $this->emitVerificationDiagnostic($diagnosticStage, 'begin', 'none', 'none', $diagnosticBodyState);
            try {
                DB::purge($dynamicConnection);
            } catch (\Throwable $error) {
                $diagnosticExceptionReported = true;
                $this->emitVerificationDiagnostic($diagnosticStage, 'cleanup_failure', 'cleanup_error', 'SERVICE_CONNECTION_PURGE_FAILED', $diagnosticBodyState);
                throw $error;
            }
            $this->emitVerificationDiagnostic($diagnosticStage, 'complete', 'none', 'none', $diagnosticBodyState);
            if ($created) {
                $diagnosticStage = 'service_cleanup_drop_database';
                $this->emitVerificationDiagnostic($diagnosticStage, 'begin', 'none', 'none', $diagnosticBodyState);
                try {
                    $admin->unprepared("DROP DATABASE IF EXISTS {$quoted}");
                } catch (\Throwable $error) {
                    $diagnosticExceptionReported = true;
                    $this->emitVerificationDiagnostic($diagnosticStage, 'cleanup_failure', 'cleanup_error', 'SERVICE_DATABASE_DROP_FAILED', $diagnosticBodyState);
                    throw $error;
                }
                $this->emitVerificationDiagnostic($diagnosticStage, 'complete', 'none', 'none', $diagnosticBodyState);
            }
        }
    }

    private function emitVerificationDiagnostic(string $stage, string $event, string $category = 'none', string $code = 'none', string $bodyState = 'pending'): void
    {
        static $stages = [
            'artifact_integrity', 'artifact_decrypt', 'artifact_gzip', 'sqlite_sanity', 'mysql_preflight',
            'mysql_create_database', 'mysql_client_import', 'mysql_schema_check', 'mysql_critical_count',
            'mysql_process_cleanup', 'service_cleanup_connection_purge', 'service_cleanup_drop_database',
            'service_cleanup_temp_artifacts',
        ];
        static $events = [
            'begin', 'complete', 'primary_failure', 'primary_exception', 'body_result', 'cleanup_observation', 'cleanup_failure',
        ];
        static $categories = [
            'none', 'backup_failure', 'filesystem_error', 'process_error', 'timeout', 'pdo_error',
            'runtime_error', 'cleanup_error', 'other',
        ];
        static $codes = [
            'none', 'other', 'OBJECT_MISSING', 'MANIFEST_OBJECT_MISSING', 'MANIFEST_OBJECT_INVALID',
            'MANIFEST_METADATA_MISMATCH', 'MANIFEST_AUTHENTICATION_FAILED', 'OBJECT_UNREADABLE',
            'VERIFY_TEMP_FAILED', 'OBJECT_READ_FAILED', 'VERIFY_TEMP_WRITE_FAILED', 'SIZE_OR_HASH_MISMATCH',
            'CIPHERTEXT_AUTHENTICATION_FAILED', 'INVALID_GZIP', 'EMPTY_DECOMPRESSED_ARTIFACT',
            'DATABASE_IMPORT_OR_SANITY_FAILED', 'DISPOSABLE_MYSQL_IMPORT_NOT_CONFIGURED', 'VERIFICATION_ERROR',
            'MYSQL_DUMP_INPUT_OPEN_FAILED', 'MYSQL_IMPORT_PROCESS_FAILED', 'MYSQL_IMPORT_TABLE_SET_MISMATCH',
            'MYSQL_IMPORT_CRITICAL_COUNT_MISMATCH', 'MYSQL_DATABASE_CREATE_FAILED', 'MYSQL_SCHEMA_CHECK_FAILED',
            'MYSQL_PROCESS_CLEANUP_FAILED', 'SERVICE_CONNECTION_PURGE_FAILED', 'SERVICE_DATABASE_DROP_FAILED',
            'SERVICE_TEMP_ARTIFACT_CLEANUP_FAILED', 'SERVICE_TEMP_ARTIFACT_UNLINK_FAILED',
        ];
        static $bodyStates = [
            'pending', 'return_true_pending_cleanup', 'return_false_pending_cleanup',
            'return_false_before_outer_cleanup', 'exception_pending_cleanup', 'handled_exception_return_false_pending_cleanup',
        ];

        try {
            if (! app()->environment('testing') || config('platform_backup.diagnostic_c_enabled') !== true || ! defined('STDERR')) {
                return;
            }
            if (! in_array($stage, $stages, true)
                || ! in_array($event, $events, true)
                || ! in_array($bodyState, $bodyStates, true)) {
                return;
            }
            if (! in_array($category, $categories, true)) {
                $category = 'other';
            }
            if (! in_array($code, $codes, true)) {
                $code = 'other';
            }

            @fwrite(STDERR, 'WPCG_DIAG_V2|test=backup_import|stage='.$stage.'|event='.$event.'|category='.$category.'|code='.$code.'|body_state='.$bodyState.PHP_EOL);
            @fflush(STDERR);
        } catch (\Throwable) {
            // Diagnostics are opt-in, best-effort, and must not alter verification behavior.
        }
    }

    private function verificationDiagnosticCategory(\Throwable $error): string
    {
        if ($error instanceof \PDOException) {
            return 'pdo_error';
        }
        if ($error instanceof \Symfony\Component\Process\Exception\ProcessTimedOutException) {
            return 'timeout';
        }
        if ($error instanceof \Symfony\Component\Process\Exception\ProcessException) {
            return 'process_error';
        }
        if ($error instanceof \ErrorException || $error instanceof RuntimeException) {
            return 'runtime_error';
        }

        return 'other';
    }

    private function verificationDiagnosticCodeForStage(string $stage): string
    {
        return match ($stage) {
            'mysql_create_database' => 'MYSQL_DATABASE_CREATE_FAILED',
            'mysql_client_import' => 'MYSQL_IMPORT_PROCESS_FAILED',
            'mysql_schema_check', 'mysql_critical_count' => 'MYSQL_SCHEMA_CHECK_FAILED',
            default => 'VERIFICATION_ERROR',
        };
    }

    /** @param array<string, mixed> $config */
    private function mysqlDefaultsFile(array $config): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wpcg-mysql-verify-');
        if ($path === false) {
            throw new RuntimeException('Could not allocate private MySQL verification configuration.');
        }
        chmod($path, 0600);
        $lines = ['[client]', 'user='.$this->mysqlOption((string) ($config['username'] ?? ''))];
        if ((string) ($config['password'] ?? '') !== '') {
            $lines[] = 'password='.$this->mysqlOption((string) $config['password']);
        }
        if (! empty($config['unix_socket'])) {
            $lines[] = 'socket='.$this->mysqlOption((string) $config['unix_socket']);
        } else {
            $lines[] = 'host='.$this->mysqlOption((string) ($config['host'] ?? '127.0.0.1'));
            $lines[] = 'port='.(int) ($config['port'] ?? 3306);
        }
        if (file_put_contents($path, implode("\n", $lines)."\n", LOCK_EX) === false) {
            @unlink($path);
            throw new RuntimeException('Could not write private MySQL verification configuration.');
        }

        return $path;
    }

    private function canonicalJson(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }

            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }
            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }

            return $item;
        };

        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function markVerificationFailure(PlatformDatabaseBackup $backup, string $code): void
    {
        $backup->forceFill(['status' => 'verification_failed', 'verification_status' => 'failed', 'failure_code' => $code])->save();
        $backup->deliveries()->where('destination_key', 'primary')->update([
            'verification_status' => 'failed', 'failure_code' => $code, 'updated_at' => now(),
        ]);
        event(new PlatformRecoverySignal('platform_db_backup_verification_failed', ['backup_uuid' => $backup->uuid, 'reason' => $code]));
    }
}
