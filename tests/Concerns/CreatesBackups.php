<?php

namespace Tests\Concerns;

use App\Backups\BackupObjectName;
use App\Backups\BackupReason;
use App\Backups\BackupStatus;
use App\Backups\BackupType;
use App\Models\Backup;
use App\Models\BackupObject;
use App\Models\Website;
use App\Models\WebsiteJob;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/**
 * Backups for tests: local storage in a temporary directory (so nothing
 * leaves the machine), a connector that uploads like the plugin does, and
 * verified backups created directly.
 */
trait CreatesBackups
{
    use PairsConnectors;

    protected string $storageRoot;

    protected function setUpBackups(array $plan = []): void
    {
        $this->storageRoot = sys_get_temp_dir().'/caregrid-storage-'.uniqid();
        config([
            'backup.storage' => 'local',
            'backup.providers.local.root' => $this->storageRoot,
            'backup.upload.staging_path' => $this->storageRoot.'-staging',
        ]);

        $backupPlan = $plan;
        unset($backupPlan['manual'], $backupPlan['restore']);

        foreach (['wordpress', 'woocommerce'] as $product) {
            foreach (['basic', 'business', 'secure'] as $tier) {
                config(["backup.plans.{$product}.{$tier}" => $backupPlan + config("backup.plans.{$product}.{$tier}")]);
                if (array_key_exists('manual', $plan)) {
                    config(["pricing.plans.{$product}.{$tier}.manual_backup_allowed" => (bool) $plan['manual']]);
                }
                if (array_key_exists('restore', $plan)) {
                    config(["pricing.plans.{$product}.{$tier}.restore_eligible" => (bool) $plan['restore']]);
                }
            }
        }
        app()->forgetInstance(\App\Pricing\PricingCatalog::class);
    }

    protected function tearDownBackups(): void
    {
        File::deleteDirectory($this->storageRoot);
        File::deleteDirectory($this->storageRoot.'-staging');
    }

    /** A paired website whose connector can back up and restore everything. */
    protected function backupReadySite(string $url = 'https://company.example'): array
    {
        $site = $this->pairedWebsite($url);
        $site['website']->activeConnector->update(['capabilities' => ['inventory', 'health', 'updates', 'license_check', 'backup_files', 'backup_database', 'backup_full', 'restore_files', 'restore_database', 'restore_full']]);
        $this->refreshCapabilityEvidence($site['website']);
        $site['website']->jobs()->delete();

        return ['website' => $site['website']->refresh(), 'credential' => $site['credential']];
    }

    /** Poll as the connector; returns the delivered jobs. */
    protected function pollJobs(array $site): array
    {
        return $this->withHeaders($this->connectorHeaders($site))->getJson(route('api.v1.connector.jobs'))->assertOk()->json('jobs');
    }

    /**
     * Upload a backup the way the plugin does: chunks, complete, finalize.
     *
     * @param  array<string, string>  $objects  name => bytes
     */
    protected function uploadBackup(array $site, Backup $backup, array $objects, array $manifestOverrides = [], int $chunk = 5): TestResponse
    {
        foreach ($objects as $name => $bytes) {
            foreach (str_split($bytes, $chunk) as $i => $piece) {
                $this->putChunk($site, $backup, $name, $i * $chunk, $piece)->assertStatus(202);
            }
            $this->withHeaders($this->connectorHeaders($site))
                ->postJson(route('api.v1.connector.backups.complete', [$backup->uuid, $name]), ['size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)])
                ->assertOk();
        }

        return $this->withHeaders($this->connectorHeaders($site))
            ->postJson(route('api.v1.connector.backups.finalize', $backup->uuid), ['manifest' => $this->manifestFor($backup, $objects, $manifestOverrides)]);
    }

    protected function putChunk(array $site, Backup $backup, string $name, int $offset, string $bytes, ?string $sha = null): TestResponse
    {
        return $this->call('PUT', route('api.v1.connector.backups.chunk', [$backup->uuid, $name]).'?offset='.$offset, [], [], [], $this->transformHeadersToServerVars($this->connectorHeaders($site) + [
            'X-Chunk-SHA256' => $sha ?? hash('sha256', $bytes),
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) strlen($bytes),
            'Accept' => 'application/json',
        ]), $bytes);
    }

    protected function manifestFor(Backup $backup, array $objects, array $overrides = []): array
    {
        $manifest = [
            'format' => 'wp-caregrid-backup/1',
            'backup_uuid' => $backup->uuid,
            'type' => $backup->type->value,
            'installation_id' => '3a3d2344-d737-4288-8755-c449d99d4593',
            'connector_version' => '0.2.0',
            'wordpress_version' => '7.1.2',
            'php_version' => '8.4.19',
            'created_at' => now()->subMinute()->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
            'site' => ['multisite' => false, 'table_prefix' => 'wp_'],
        ];

        if (isset($objects['files'])) {
            $manifest['files'] = ['size' => strlen($objects['files']), 'sha256' => hash('sha256', $objects['files']), 'compression' => 'gzip', 'format' => 'ustar', 'file_count' => 12, 'uncompressed_size' => 4096, 'policy' => 'standard-1', 'wp_config' => 'redacted', 'skipped' => ['symlinks' => 0, 'too_large' => 0, 'unreadable' => 0, 'excluded' => 3]];
        }
        if (isset($objects['database'])) {
            $manifest['database'] = ['size' => strlen($objects['database']), 'sha256' => hash('sha256', $objects['database']), 'compression' => 'gzip', 'engine' => 'mariadb', 'server_version' => '10.11.14-MariaDB', 'charset' => 'utf8mb4', 'table_prefix' => 'wp_', 'tables' => 12, 'rows' => 340, 'excluded' => ['transients', 'connector_state']];
        }

        return array_replace_recursive($manifest, $overrides);
    }

    /** The whole happy path for one backup: delivered, uploaded, stored, verified. */
    protected function makeVerifiedBackupThroughApi(array $site, Backup $backup, array $objects = ['files' => 'FILES-ARCHIVE-BYTES', 'database' => 'DATABASE-DUMP-BYTES']): Backup
    {
        $this->pollJobs($site);
        $this->uploadBackup($site, $backup->refresh(), array_intersect_key($objects, array_flip($backup->type->objects())))->assertStatus(202);

        return $backup->refresh();
    }

    /** A verified backup record with objects in storage, without going through the API. */
    protected function verifiedBackup(Website $website, BackupType $type = BackupType::Full, ?\DateTimeInterface $at = null, BackupReason $reason = BackupReason::Scheduled): Backup
    {
        $at ??= now();
        $backup = $website->backups()->create([
            'type' => $type, 'reason' => $reason, 'status' => BackupStatus::Verified, 'storage_provider' => 'local',
            'total_size' => 0, 'verified_at' => $at, 'completed_at' => $at, 'requested_by' => 'test',
        ]);
        $backup->forceFill(['created_at' => $at, 'storage_path' => $backup->storagePrefix()])->save();

        $total = 0;
        foreach ([...$type->objects(), 'manifest'] as $name) {
            $bytes = strtoupper($name).'-'.$backup->uuid;
            $key = $backup->objectKey(BackupObjectName::from($name));
            File::ensureDirectoryExists(dirname($this->storageRoot.'/'.$key));
            file_put_contents($this->storageRoot.'/'.$key, $bytes);
            $backup->objects()->create(['name' => $name, 'filename' => BackupObjectName::from($name)->filename(), 'status' => BackupObject::VERIFIED, 'size' => strlen($bytes), 'received_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'verification_method' => 'readback', 'verified_at' => $at]);
            $total += $name === 'manifest' ? 0 : strlen($bytes);
        }
        $backup->forceFill(['total_size' => $total])->save();

        $this->refreshCapabilityEvidence($website);

        return $backup->refresh();
    }

    protected function backupJob(Backup $backup): WebsiteJob
    {
        return WebsiteJob::where('backup_id', $backup->id)->whereIn('type', ['BACKUP_FILES', 'BACKUP_DATABASE', 'BACKUP_FULL'])->sole();
    }

    protected function reportJob(array $site, WebsiteJob $job, string $status, string $code, array $data = [], ?array $error = null): TestResponse
    {
        return $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.jobs.result', $job->uuid), array_filter([
            'status' => $status, 'result_code' => $code, 'data' => $data, 'error' => $error,
            'started_at' => now()->toIso8601String(), 'completed_at' => now()->toIso8601String(),
        ]));
    }
}
