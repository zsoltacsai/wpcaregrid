<?php

namespace WPCareGrid\Connector\Jobs\Handlers;

use WPCareGrid\Connector\Api\Client;
use WPCareGrid\Connector\Auth\InstallationId;
use WPCareGrid\Connector\Backups\BackupFailure;
use WPCareGrid\Connector\Backups\BackupIndex;
use WPCareGrid\Connector\Backups\DatabaseDumper;
use WPCareGrid\Connector\Backups\DiskSpace;
use WPCareGrid\Connector\Backups\FilesArchiver;
use WPCareGrid\Connector\Backups\TempDir;
use WPCareGrid\Connector\Backups\Uploader;
use WPCareGrid\Connector\Jobs\InvalidPayload;
use WPCareGrid\Connector\Jobs\JobHandler;
use WPCareGrid\Connector\Jobs\JobResult;
use WPCareGrid\Connector\Jobs\ResumableJob;
use WPCareGrid\Connector\Security\Logger;

/**
 * BACKUP_FILES, BACKUP_DATABASE and BACKUP_FULL.
 *
 *   build locally (private temp dir) → upload in chunks → finalize with
 *   the manifest → record in the local index → remove local files
 *
 * The server decides nothing here but the backup's UUID, its reason and
 * the chunk size: no paths, no storage, no credentials. If the process
 * dies while uploading, the next poll resumes the upload (ResumableJob);
 * a backup is never built twice for the same job.
 */
final class BackupHandler implements JobHandler, ResumableJob
{
    private const STATE = 'wp_caregrid_backup_runs';

    public function __construct(
        private readonly string $type,
        private readonly Client $api,
        private readonly Uploader $uploader,
    ) {}

    public function capability(array $payload = []): string
    {
        return 'backup_'.$this->type;
    }

    public function validate(array $payload): array
    {
        InvalidPayload::check($payload, ['backup_uuid', 'reason', 'chunk_size'], ['backup_uuid', 'reason', 'chunk_size']);

        if (! in_array($payload['reason'], ['scheduled', 'manual', 'pre_update', 'pre_restore', 'recovery'], true)) {
            throw new InvalidPayload('Invalid backup reason.');
        }

        $chunk = filter_var($payload['chunk_size'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1048576, 'max_range' => 16777216]]);
        if ($chunk === false) {
            throw new InvalidPayload('Invalid chunk size.');
        }

        return ['backup_uuid' => InvalidPayload::uuid((string) $payload['backup_uuid']), 'reason' => $payload['reason'], 'chunk_size' => $chunk];
    }

    public function handle(array $payload): JobResult
    {
        $uuid = $payload['backup_uuid'];
        @set_time_limit(0);
        ignore_user_abort(true);

        try {
            $this->prepare($payload);

            return $this->upload_and_finalize($uuid);
        } catch (BackupFailure $e) {
            $this->cleanup($uuid);
            Logger::warning('backup.failed', ['backup' => $uuid, 'code' => $e->error_code]);

            return JobResult::failed($e->error_code, $e->getMessage(), $e->details);
        } catch (\Throwable $e) {
            $this->cleanup($uuid);

            throw $e;
        }
    }

    /**
     * Build the objects locally and remember them, so an interrupted upload
     * can resume without building again.
     */
    public function prepare(array $payload): void
    {
        $uuid = $payload['backup_uuid'];
        $dir = TempDir::ensure();
        DiskSpace::require($dir, $this->estimate());

        $created_at = gmdate('c');
        $objects = [];

        if ($this->type !== 'files') {
            $objects['database'] = (new DatabaseDumper)->dump($uuid);
        }
        if ($this->type !== 'database') {
            $objects['files'] = (new FilesArchiver)->build($uuid);
        }

        $this->save_state($uuid, ['payload' => $payload, 'created_at' => $created_at, 'objects' => $objects]);
    }

    public function can_resume(array $payload): bool
    {
        $state = isset($payload['backup_uuid']) ? $this->state((string) $payload['backup_uuid']) : null;

        if (! $state || empty($state['objects'])) {
            return false;
        }

        foreach ($state['objects'] as $object) {
            clearstatcache(true, $object['path']);
            if (! is_file($object['path']) || filesize($object['path']) !== $object['size']) {
                return false;
            }
        }

        return true;
    }

    /** Continue an interrupted upload. The files are re-hashed first: nothing unverified is sent. */
    public function resume(array $payload): JobResult
    {
        $uuid = (string) $payload['backup_uuid'];

        foreach ($this->state($uuid)['objects'] ?? [] as $object) {
            if (! hash_equals($object['sha256'], (string) hash_file('sha256', $object['path']))) {
                $this->cleanup($uuid);

                return JobResult::failed('JOB_INTERRUPTED', 'The backup was interrupted and its local files changed.');
            }
        }

        try {
            return $this->upload_and_finalize($uuid);
        } catch (BackupFailure $e) {
            $this->cleanup($uuid);

            return JobResult::failed($e->error_code, $e->getMessage(), $e->details);
        }
    }

    private function upload_and_finalize(string $uuid): JobResult
    {
        $state = $this->state($uuid);
        $chunk = (int) $state['payload']['chunk_size'];

        foreach ($state['objects'] as $name => $object) {
            $this->uploader->upload($uuid, $name, $object['path'], $object['size'], $object['sha256'], $chunk);
        }

        $manifest = $this->manifest($uuid, $state);
        $response = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $response = $this->api->post("backups/{$uuid}/finalize", ['manifest' => $manifest]);
            if ($response->ok() || ! $response->is_temporary()) {
                break;
            }
        }

        if (! $response->ok()) {
            throw new BackupFailure('FINALIZE_FAILED', 'WP CareGrid did not accept the backup manifest.', ['code' => (string) ($response->error_code ?? 'HTTP_'.$response->status)]);
        }

        BackupIndex::record($uuid, $this->type, array_map(
            fn (array $object) => ['size' => $object['size'], 'sha256' => $object['sha256'], 'uncompressed_size' => $object['uncompressed_size']],
            $state['objects']
        ));
        $this->cleanup($uuid);
        Logger::info('backup.uploaded', ['backup' => $uuid, 'type' => $this->type]);

        return JobResult::success('BACKUP_UPLOADED', ['backup' => [
            'uuid' => $uuid,
            'type' => $this->type,
            'files_size' => $state['objects']['files']['size'] ?? null,
            'database_size' => $state['objects']['database']['size'] ?? null,
        ]]);
    }

    private function manifest(string $uuid, array $state): array
    {
        global $wpdb;

        $describe = fn (array $object, array $keys) => array_intersect_key($object, array_flip($keys));

        return array_filter([
            'format' => 'wp-caregrid-backup/1',
            'backup_uuid' => $uuid,
            'type' => $this->type,
            'installation_id' => InstallationId::get(),
            'connector_version' => WP_CAREGRID_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'created_at' => $state['created_at'],
            'completed_at' => gmdate('c'),
            'site' => ['multisite' => is_multisite(), 'table_prefix' => $wpdb->base_prefix],
            'files' => isset($state['objects']['files']) ? $describe($state['objects']['files'], ['size', 'sha256', 'compression', 'format', 'file_count', 'uncompressed_size', 'policy', 'wp_config', 'skipped']) : null,
            'database' => isset($state['objects']['database']) ? $describe($state['objects']['database'], ['size', 'sha256', 'compression', 'engine', 'server_version', 'charset', 'table_prefix', 'tables', 'rows', 'excluded']) : null,
        ], fn ($value) => $value !== null);
    }

    private function estimate(): int
    {
        return ($this->type !== 'database' ? FilesArchiver::estimate() : 0)
            + ($this->type !== 'files' ? DatabaseDumper::estimate() : 0);
    }

    private function state(string $uuid): ?array
    {
        $runs = get_option(self::STATE, []);

        return is_array($runs) && isset($runs[$uuid]) && is_array($runs[$uuid]) ? $runs[$uuid] : null;
    }

    private function save_state(string $uuid, array $state): void
    {
        $runs = get_option(self::STATE, []);
        $runs = is_array($runs) ? $runs : [];
        $runs[$uuid] = $state;
        update_option(self::STATE, $runs, false);
    }

    private function cleanup(string $uuid): void
    {
        TempDir::remove_job($uuid);
        $runs = get_option(self::STATE, []);
        if (is_array($runs) && isset($runs[$uuid])) {
            unset($runs[$uuid]);
            update_option(self::STATE, $runs, false);
        }
    }
}
