<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupReason;
use App\Backups\BackupRefused;
use App\Backups\BackupService;
use App\Backups\BackupStatus;
use App\Backups\BackupType;
use App\Models\Backup;
use App\Models\WebsiteEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesBackups;
use Tests\TestCase;

/** A backup from request to verified, through the real connector API and a (local) storage provider. */
class BackupFlowTest extends TestCase
{
    use CreatesBackups, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups();
    }

    protected function tearDown(): void
    {
        $this->tearDownBackups();
        parent::tearDown();
    }

    public function test_full_backup_is_verified_only_after_storage_confirms_it(): void
    {
        $site = $this->backupReadySite();
        $backup = app(BackupService::class)->request($site['website'], BackupReason::Manual, 'test');

        $this->assertSame(BackupStatus::Queued, $backup->status);
        $this->assertSame($site['website']->uuid.'/'.$backup->uuid.'/', $backup->storage_path);

        $jobs = $this->pollJobs($site);
        $this->assertSame('BACKUP_FULL', $jobs[0]['type']);
        $this->assertEqualsCanonicalizing(['backup_uuid', 'reason', 'chunk_size'], array_keys($jobs[0]['payload']), 'Only logical information; no storage, paths or credentials.');
        $this->assertSame(BackupStatus::Running, $backup->refresh()->status);

        $objects = ['files' => str_repeat('F', 23), 'database' => str_repeat('D', 11)];
        $this->uploadBackup($site, $backup, $objects)->assertStatus(202);

        // The sync queue ran TransferBackup: stored, then verified by reading back.
        $backup->refresh();
        $this->assertSame(BackupStatus::Verified, $backup->status);
        $this->assertNotNull($backup->verified_at);
        $this->assertSame(34, $backup->total_size);
        $this->assertSame(hash('sha256', $objects['files']), $backup->checksums['files.tar.gz']);
        $this->assertSame(hash('sha256', $objects['database']), $backup->checksums['database.sql.gz']);
        $this->assertSame('7.1.2', $backup->wordpress_version);
        $this->assertSame('0.2.0', $backup->connector_version);
        foreach (['files.tar.gz' => $objects['files'], 'database.sql.gz' => $objects['database']] as $file => $bytes) {
            $this->assertSame($bytes, file_get_contents($this->storageRoot.'/'.$backup->storage_path.$file));
        }
        $manifest = json_decode(file_get_contents($this->storageRoot.'/'.$backup->storage_path.'manifest.json'), true);
        $this->assertSame($backup->uuid, $manifest['backup_uuid']);
        $this->assertSame($site['website']->uuid, $manifest['website_uuid']);
        $this->assertSame(['algorithm' => 'sha256', 'files.tar.gz' => hash('sha256', $objects['files']), 'database.sql.gz' => hash('sha256', $objects['database'])], $manifest['checksums']);
        $this->assertSame(['verified', 'verified', 'verified'], $backup->objects()->pluck('status')->all());
        $this->assertDirectoryDoesNotExist(config('backup.upload.staging_path').'/'.$backup->uuid, 'Staged data is removed once verified.');

        $this->assertSame(['backup.requested', 'backup.started', 'backup.uploaded', 'backup.verified'], WebsiteEvent::where('event', 'like', 'backup.%')->orderBy('id')->pluck('event')->all());
        $this->actingAs($site['website']->customer->user)->get(route('account.websites.backups.index', $site['website']))
            ->assertSee(['Backup requested', 'Backup verified'])->assertDontSee('backups.events');

        // The job result afterwards changes nothing.
        $this->reportJob($site, $this->backupJob($backup), 'success', 'BACKUP_UPLOADED')->assertStatus(202);
        $this->assertSame(BackupStatus::Verified, $backup->refresh()->status);
    }

    public function test_files_and_database_backups_contain_only_their_objects(): void
    {
        $site = $this->backupReadySite();

        $files = app(BackupService::class)->request($site['website'], BackupReason::Manual, 'test', BackupType::Files);
        $this->pollJobs($site);
        $this->putChunk($site, $files, 'database', 0, 'x')->assertNotFound()->assertJsonPath('error.code', 'INVALID_OBJECT');
        $this->uploadBackup($site, $files, ['files' => 'FILES'])->assertStatus(202);
        $this->assertSame(BackupStatus::Verified, $files->refresh()->status);
        $this->assertSame(['files', 'manifest'], $files->objects()->orderBy('name')->pluck('name')->all());
    }

    public function test_upload_offsets_are_enforced_and_resumable(): void
    {
        $site = $this->backupReadySite();
        $backup = app(BackupService::class)->request($site['website'], BackupReason::Manual, 'test', BackupType::Files);
        $this->pollJobs($site);

        $this->putChunk($site, $backup, 'files', 0, 'AAAAA')->assertStatus(202)->assertJson(['received_bytes' => 5]);
        // Skipping ahead is refused, with the offset to continue from.
        $this->putChunk($site, $backup, 'files', 10, 'CCCCC')->assertStatus(409)->assertJsonPath('error.code', 'OFFSET_MISMATCH')->assertJsonPath('error.details.expected_offset', 5);
        // A corrupted chunk is refused and not stored.
        $this->putChunk($site, $backup, 'files', 5, 'BBBBB', hash('sha256', 'other'))->assertStatus(422)->assertJsonPath('error.code', 'CHUNK_REJECTED');
        $this->withHeaders($this->connectorHeaders($site))->getJson(route('api.v1.connector.backups.status', [$backup->uuid, 'files']))->assertOk()->assertJson(['received_bytes' => 5]);

        $this->putChunk($site, $backup, 'files', 5, 'BBBBB')->assertStatus(202)->assertJson(['received_bytes' => 10]);
        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.backups.complete', [$backup->uuid, 'files']), ['size' => 10, 'sha256' => hash('sha256', 'AAAAABBBBB')])->assertOk();
    }

    public function test_checksum_mismatch_on_completion_discards_the_data(): void
    {
        $site = $this->backupReadySite();
        $backup = app(BackupService::class)->request($site['website'], BackupReason::Manual, 'test', BackupType::Files);
        $this->pollJobs($site);
        $this->putChunk($site, $backup, 'files', 0, 'AAAAA');

        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.backups.complete', [$backup->uuid, 'files']), ['size' => 5, 'sha256' => hash('sha256', 'BBBBB')])
            ->assertStatus(422)->assertJsonPath('error.code', 'CHECKSUM_MISMATCH');

        $this->assertSame(0, $backup->objects()->where('name', 'files')->value('received_bytes'));
        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.backups.finalize', $backup->uuid), ['manifest' => $this->manifestFor($backup, ['files' => 'AAAAA'])])
            ->assertStatus(409)->assertJsonPath('error.code', 'OBJECTS_MISSING');
    }

    public function test_manifest_is_strictly_validated(): void
    {
        $site = $this->backupReadySite();

        $cases = [
            'secret smuggled in' => ['database' => ['db_password' => 'hunter2']],
            'unknown top-level key' => ['wp_options' => ['admin_email' => 'x@company.example']],
            'wrong checksum' => ['files' => ['sha256' => str_repeat('0', 64)]],
            'wrong size' => ['database' => ['size' => 999]],
            'other backup' => ['backup_uuid' => '01a0da4e-2cb8-7171-a360-8a68030cc999'],
            'bad format' => ['format' => 'zip'],
        ];

        foreach ($cases as $case => $override) {
            $backup = app(BackupService::class)->request($site['website']->refresh(), BackupReason::Recovery, 'test');
            $this->pollJobs($site);
            $response = $this->uploadBackup($site, $backup, ['files' => 'FILES', 'database' => 'DB'], $override);

            $response->assertStatus(422);
            $this->assertContains($response->json('error.code'), ['INVALID_MANIFEST', 'MANIFEST_MISMATCH'], $case);
            $this->assertSame(BackupStatus::Uploading, $backup->refresh()->status, $case);
            $this->assertStringNotContainsString('hunter2', json_encode($backup->manifest));
            $this->reportJob($site, $this->backupJob($backup), 'failed', 'FINALIZE_FAILED', [], ['code' => 'FINALIZE_FAILED', 'message' => 'Refused.']);
        }
    }

    public function test_failed_job_fails_the_backup_with_its_reason(): void
    {
        $site = $this->backupReadySite();
        $backup = app(BackupService::class)->request($site['website'], BackupReason::Manual, 'test');
        $this->pollJobs($site);

        $this->reportJob($site, $this->backupJob($backup), 'failed', 'INSUFFICIENT_DISK_SPACE', [], ['code' => 'INSUFFICIENT_DISK_SPACE', 'message' => 'Not enough free disk space for the backup.'])->assertStatus(202);

        $backup->refresh();
        $this->assertSame(BackupStatus::Failed, $backup->status);
        $this->assertSame('INSUFFICIENT_DISK_SPACE', $backup->error_code);
        $this->putChunk($site, $backup, 'files', 0, 'late')->assertStatus(409)->assertJsonPath('error.code', 'BACKUP_NOT_ACCEPTING');
    }

    public function test_job_reported_successful_without_finalized_upload_is_not_a_backup(): void
    {
        $site = $this->backupReadySite();
        $backup = app(BackupService::class)->request($site['website'], BackupReason::Manual, 'test');
        $this->pollJobs($site);

        $this->reportJob($site, $this->backupJob($backup), 'success', 'BACKUP_UPLOADED')->assertStatus(202);

        $this->assertSame(BackupStatus::Failed, $backup->refresh()->status);
        $this->assertSame('UPLOAD_INCOMPLETE', $backup->error_code);
    }

    public function test_states_never_jump(): void
    {
        $site = $this->backupReadySite();
        $backup = app(BackupService::class)->request($site['website'], BackupReason::Manual, 'test');

        $this->expectException(\LogicException::class);
        $backup->transitionTo(BackupStatus::Verified);
    }

    public function test_stuck_backups_time_out_and_staging_is_cleaned(): void
    {
        $site = $this->backupReadySite();
        $backup = app(BackupService::class)->request($site['website'], BackupReason::Manual, 'test', BackupType::Files);
        $this->pollJobs($site);
        $this->putChunk($site, $backup, 'files', 0, 'PARTIAL');
        $staging = config('backup.upload.staging_path').'/'.$backup->uuid;
        $this->assertDirectoryExists($staging);

        $this->travel(7)->hours();
        $this->artisan('backup:monitor')->assertSuccessful();
        $this->assertSame(BackupStatus::Failed, $backup->refresh()->status);
        $this->assertSame('BACKUP_TIMED_OUT', $backup->error_code);

        $this->travel(25)->hours();
        $this->artisan('backup:monitor')->assertSuccessful();
        $this->assertDirectoryDoesNotExist($staging);
    }

    public function test_staging_files_are_private(): void
    {
        $site = $this->backupReadySite();
        $backup = app(BackupService::class)->request($site['website'], BackupReason::Manual, 'test', BackupType::Files);
        $this->pollJobs($site);
        $this->putChunk($site, $backup, 'files', 0, 'DATA');

        $path = config('backup.upload.staging_path').'/'.$backup->uuid.'/files.tar.gz';
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory(dirname($path)));
        $this->assertFileExists($path);

        if (PHP_OS_FAMILY === 'Windows') {
            // Only this assertion depends on the platform; Linux CI runs it (the authoritative result).
            $this->markTestSkipped('Unix file permissions (0600/0700) cannot be checked on Windows; verified on Linux.');
        }

        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms(dirname($path))), -4));
    }

    public function test_missing_storage_configuration_queues_nothing(): void
    {
        config(['backup.storage' => null]);
        $site = $this->backupReadySite();

        try {
            app(BackupService::class)->request($site['website'], BackupReason::Manual, 'test');
            $this->fail('A backup was queued without storage.');
        } catch (BackupRefused $e) {
            $this->assertSame('STORAGE_NOT_CONFIGURED', $e->errorCode);
        }

        $this->assertSame(0, Backup::count());
        $this->assertSame(0, $site['website']->jobs()->count());
    }
}
