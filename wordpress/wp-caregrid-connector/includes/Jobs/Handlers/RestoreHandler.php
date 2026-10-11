<?php

namespace WPCareGrid\Connector\Jobs\Handlers;

use WPCareGrid\Connector\Backups\BackupFailure;
use WPCareGrid\Connector\Backups\BackupIndexLookup;
use WPCareGrid\Connector\Backups\DatabaseDumper;
use WPCareGrid\Connector\Backups\DiskSpace;
use WPCareGrid\Connector\Backups\Downloader;
use WPCareGrid\Connector\Backups\FileRestorer;
use WPCareGrid\Connector\Backups\Maintenance;
use WPCareGrid\Connector\Backups\RestoreFaultHooks;
use WPCareGrid\Connector\Backups\SqlImporter;
use WPCareGrid\Connector\Backups\TempDir;
use WPCareGrid\Connector\Backups\WordPressBackupIndexLookup;
use WPCareGrid\Connector\Health\HealthChecker;
use WPCareGrid\Connector\Jobs\InvalidPayload;
use WPCareGrid\Connector\Jobs\JobHandler;
use WPCareGrid\Connector\Jobs\JobResult;
use WPCareGrid\Connector\Jobs\JobStore;
use WPCareGrid\Connector\Security\Logger;

/**
 * RESTORE: puts one of this site's own backups back.
 *
 *   1. The backup must be in the local index with the same checksums: the
 *      site only restores data it produced itself.
 *   2. Download and verify every object (WP CareGrid made a verified
 *      safety backup of the current site before sending this job).
 *   3. Prepare everything outside the live site: extract and validate the
 *      files; validate and load the database into temporary tables.
 *   4. Maintenance mode, then swap the tables and copy the files; maintenance
 *      mode always ends (see Maintenance).
 *   5. Post-restore checks: files present, database answering, WordPress
 *      version, and the health checks (home page, REST API, theme, plugins).
 *      Only when they pass is the restore reported as successful.
 */
final class RestoreHandler implements JobHandler
{
    private const SCOPE_OBJECTS = ['files' => ['files'], 'database' => ['database'], 'full' => ['database', 'files']];

    public function __construct(
        private readonly Downloader $downloader,
        private readonly HealthChecker $health,
        private readonly JobStore $jobs,
        private readonly ?BackupIndexLookup $backup_index = null,
        private readonly ?RestoreFaultHooks $fault_hooks = null,
    ) {}

    public function capability(array $payload = []): string
    {
        return 'restore_'.($payload['scope'] ?? 'full');
    }

    public function validate(array $payload): array
    {
        InvalidPayload::check(array_diff_key($payload, ['objects' => true]), ['restore_uuid', 'backup_uuid', 'scope'], ['restore_uuid', 'backup_uuid', 'scope']);

        if (! isset(self::SCOPE_OBJECTS[$payload['scope']])) {
            throw new InvalidPayload('Invalid restore scope.');
        }

        $objects = [];
        foreach ((array) ($payload['objects'] ?? []) as $object) {
            if (! is_array($object) || array_diff(array_keys($object), ['name', 'size', 'sha256']) || ! in_array($object['name'] ?? null, ['files', 'database'], true)
                || ! is_int($object['size'] ?? null) || $object['size'] < 1 || ! preg_match('/^[a-f0-9]{64}$/', (string) ($object['sha256'] ?? ''))) {
                throw new InvalidPayload('Invalid restore object.');
            }
            $objects[$object['name']] = ['size' => $object['size'], 'sha256' => $object['sha256']];
        }

        // Exactly the objects of the requested scope: never more, never fewer.
        $expected = self::SCOPE_OBJECTS[$payload['scope']];
        if (array_diff($expected, array_keys($objects)) || array_diff(array_keys($objects), $expected)) {
            throw new InvalidPayload('The objects do not match the restore scope.');
        }

        return [
            'restore_uuid' => InvalidPayload::uuid((string) $payload['restore_uuid']),
            'backup_uuid' => InvalidPayload::uuid((string) $payload['backup_uuid']),
            'scope' => $payload['scope'],
            'objects' => $objects,
        ];
    }

    public function handle(array $payload): JobResult
    {
        $uuid = $payload['restore_uuid'];
        $importer = null;
        $destructiveMutationStarted = false;
        $failureStage = 'source_validation';
        $this->progress($uuid, $destructiveMutationStarted, $failureStage);
        @set_time_limit(0);
        ignore_user_abort(true);

        try {
            $failureStage = 'source_validation';
            $this->progress($uuid, $destructiveMutationStarted, $failureStage);
            $index = $this->check_index($payload);
            $this->fault_hooks?->reach('index_validated', $uuid);

            $failureStage = 'backup_download';
            $this->progress($uuid, $destructiveMutationStarted, $failureStage);
            $dir = TempDir::ensure();
            $needed = 0;
            foreach ($payload['objects'] as $name => $object) {
                $needed += $object['size'] + (int) ($index['objects'][$name]['uncompressed_size'] ?? $object['size'] * 3);
            }
            DiskSpace::require($dir, $needed);

            $paths = [];
            foreach ($payload['objects'] as $name => $object) {
                $paths[$name] = TempDir::file($uuid, $name.'.download');
                $this->downloader->download($payload['backup_uuid'], $name, $object['size'], $object['sha256'], $paths[$name]);
            }
            $this->fault_hooks?->reach('objects_downloaded', $uuid);

            $files = null;
            if (isset($paths['files'])) {
                $failureStage = 'file_staging';
                $this->progress($uuid, $destructiveMutationStarted, $failureStage);
                $files = new FileRestorer;
                $filesStaging = TempDir::file($uuid, 'files-staging');
                $files->extract($paths['files'], $filesStaging);
                $files->preflight($filesStaging);
                $this->fault_hooks?->reach('files_prepared', $uuid);
            }

            if (isset($paths['database'])) {
                $failureStage = 'database_staging';
                $this->progress($uuid, $destructiveMutationStarted, $failureStage);
                if (! DatabaseDumper::supported()) {
                    throw new BackupFailure('DATABASE_NOT_SUPPORTED', 'Database restores need MySQL or MariaDB.');
                }
                global $wpdb;
                $importer = new SqlImporter($uuid, $wpdb->base_prefix);
                $importer->import($paths['database']);
                $this->fault_hooks?->reach('database_prepared', $uuid);
            }

            // This boundary is after all remote and archive validation but before
            // maintenance mode or any live table/file mutation.
            $this->fault_hooks?->reach('before_destructive_mutation', $uuid);
            Maintenance::enable($uuid);
            try {
                do_action('wp_caregrid_restore_critical_section', $payload['scope']);
                $failureStage = 'database_swap';
                $this->progress($uuid, $destructiveMutationStarted, $failureStage);
                if ($importer !== null) {
                    $importer->swap(function () use (&$destructiveMutationStarted, $uuid) {
                        $this->progress($uuid, true, 'database_swap');
                        $destructiveMutationStarted = true;
                    });
                    $importer = null;
                    $this->fault_hooks?->reach('after_database_swap', $uuid);
                }
                $failureStage = 'file_apply';
                // Files-only restores have no DB swap. Conservatively mark
                // the boundary before the first live file can be replaced.
                if ($files !== null) {
                    $destructiveMutationStarted = true;
                }
                $this->progress($uuid, $destructiveMutationStarted, $failureStage);
                if ($files !== null) {
                    $this->fault_hooks?->reach('before_file_apply', $uuid);
                }
                $files?->apply(TempDir::file($uuid, 'files-staging'));
                if ($files !== null) {
                    $this->fault_hooks?->reach('after_file_apply', $uuid);
                }
            } finally {
                Maintenance::disable();
            }

            $failureStage = 'post_restore_health';
            $this->progress($uuid, $destructiveMutationStarted, $failureStage);
            $this->fault_hooks?->reach('before_post_restore_health', $uuid);
            $checks = $this->post_checks();
            $health = $this->health->run();
            $passed = ! in_array(false, array_column($checks, 'passed'), true) && $health['status'] !== 'critical';
            $data = ['post_checks' => $checks, 'health' => $health, 'restored' => ['scope' => $payload['scope'], 'backup_uuid' => $payload['backup_uuid'], 'files' => $files?->stats, 'file_plan' => $files?->plan_summary()], 'wordpress_version' => $this->version_on_disk(), 'destructive_mutation_started' => $destructiveMutationStarted, 'failure_stage' => $failureStage];

            Logger::info('restore.finished', ['restore' => $uuid, 'passed' => $passed]);

            return $passed
                ? JobResult::success('RESTORED', $data)
                : JobResult::partial('RESTORED_CHECKS_FAILED', 'The backup was restored, but the checks afterwards found problems.', $data);
        } catch (BackupFailure $e) {
            $importer?->discard();
            Logger::warning('restore.failed', ['restore' => $uuid, 'code' => $e->error_code]);

            return JobResult::failed($e->error_code, $e->getMessage(), array_merge($e->details, [
                'destructive_mutation_started' => $destructiveMutationStarted,
                'failure_stage' => $failureStage,
            ]));
        } catch (\Throwable) {
            // Unexpected errors must still report whether live state may have
            // changed, without leaking exception text or a filesystem path.
            $importer?->discard();
            Logger::warning('restore.failed', ['restore' => $uuid, 'code' => 'RESTORE_FAILED', 'stage' => $failureStage]);

            return JobResult::failed('RESTORE_FAILED', 'The restore failed during a controlled operation.', [
                'destructive_mutation_started' => $destructiveMutationStarted,
                'failure_stage' => $failureStage,
            ]);
        } finally {
            TempDir::remove_job($uuid);
        }
    }

    /** Only backups this installation made, with the same checksums. */
    private function check_index(array $payload): array
    {
        $known = ($this->backup_index ?? new WordPressBackupIndexLookup)->find($payload['backup_uuid']);

        foreach ($payload['objects'] as $name => $object) {
            $local = $known['objects'][$name] ?? null;
            if (! $local || (int) $local['size'] !== $object['size'] || ! hash_equals((string) $local['sha256'], $object['sha256'])) {
                throw new BackupFailure('RESTORE_BACKUP_UNKNOWN', 'This site did not make this backup, or it does not match; nothing was restored.');
            }
        }

        return $known;
    }

    private function progress(string $uuid, bool $destructive, string $stage): void
    {
        $this->jobs->mark_restore_progress($uuid, $destructive, $stage);
    }

    /** @return list<array{id: string, passed: bool, message: string}> */
    private function post_checks(): array
    {
        global $wpdb;

        wp_cache_flush();
        wp_clean_themes_cache(false);
        $stylesheet = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'stylesheet'));
        $version = $this->version_on_disk();

        return [
            ['id' => 'files_present', 'passed' => is_dir(WP_CONTENT_DIR) && is_file(ABSPATH.'wp-settings.php'), 'message' => 'WordPress files are in place.'],
            ['id' => 'database', 'passed' => (string) $wpdb->get_var('SELECT 1') === '1' && (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'siteurl')) !== '', 'message' => 'The database answers and has the site settings.'],
            ['id' => 'wordpress_version', 'passed' => $version !== '', 'message' => $version !== '' ? 'WordPress '.$version : 'The WordPress version could not be read.'],
            ['id' => 'active_theme', 'passed' => $stylesheet !== '' && is_dir(get_theme_root($stylesheet).'/'.$stylesheet), 'message' => $stylesheet !== '' ? 'Active theme: '.$stylesheet : 'No active theme.'],
        ];
    }

    private function version_on_disk(): string
    {
        $wp_version = '';
        include ABSPATH.WPINC.'/version.php';

        return (string) $wp_version;
    }
}
