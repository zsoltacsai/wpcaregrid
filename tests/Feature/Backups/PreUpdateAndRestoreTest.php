<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupReason;
use App\Backups\BackupRefused;
use App\Backups\BackupStatus;
use App\Backups\BackupType;
use App\Backups\RestoreService;
use App\Backups\RestoreStatus;
use App\Backups\Storage\BackupStorage;
use App\Backups\Storage\StorageManager;
use App\Backups\Storage\Verification;
use App\Connector\JobQueue;
use App\Connector\JobStatus;
use App\Connector\JobType;
use App\Models\Backup;
use App\Models\BackupRestore;
use App\Models\WebsiteEvent;
use App\Models\WebsiteJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBackups;
use Tests\TestCase;

class PreUpdateAndRestoreTest extends TestCase
{
    use CreatesBackups, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups(['pre_update_backup' => true]);
        config(['backup.restore.enabled' => true]);
    }

    protected function tearDown(): void
    {
        $this->tearDownBackups();
        parent::tearDown();
    }

    /* ----- Pre-update backups ----- */

    public function test_update_waits_for_a_verified_backup_and_is_linked_to_it(): void
    {
        $site = $this->backupReadySite();
        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.inventory'), $this->inventoryPayload())->assertStatus(202);

        $update = app(JobQueue::class)->queue($site['website']->refresh(), JobType::UpdatePlugin, ['plugin' => 'akismet/akismet.php']);

        $this->assertSame(JobStatus::Waiting, $update->status);
        $backup = $update->backup;
        $this->assertSame(BackupReason::PreUpdate, $backup->reason);
        $this->assertSame(BackupType::Full, $backup->type);
        $this->assertSame($update->id, $backup->update_job_id);
        $this->assertEquals(['kind' => 'plugin', 'identifier' => 'akismet/akismet.php', 'previous_version' => '5.3'], $backup->update_target);

        // Only the backup job is handed to the connector; the update is held back.
        $delivered = array_column($this->pollJobs($site), 'type');
        $this->assertSame(['BACKUP_FULL'], $delivered);

        $this->uploadBackup($site, $backup->refresh(), ['files' => 'FILES', 'database' => 'DB'])->assertStatus(202);
        $this->assertSame(BackupStatus::Verified, $backup->refresh()->status);

        $update->refresh();
        $this->assertSame(JobStatus::Queued, $update->status);
        $this->assertSame($backup->uuid, $update->payload['backup_uuid']);
        $jobs = collect($this->pollJobs($site))->keyBy('type');
        $this->assertSame($backup->uuid, $jobs['UPDATE_PLUGIN']['payload']['backup_uuid']);

        // Shown to the customer as protected.
        $this->actingAs($site['website']->customer->user)->get(route('account.websites.show', $site['website']))->assertSee(__('backups.account.protected'));
    }

    public function test_update_is_blocked_when_the_required_backup_fails(): void
    {
        $site = $this->backupReadySite();
        $update = app(JobQueue::class)->queue($site['website'], JobType::UpdateCore, ['policy' => 'minor']);
        $this->pollJobs($site);

        $this->reportJob($site, $this->backupJob($update->backup), 'failed', 'INSUFFICIENT_DISK_SPACE', [], ['code' => 'INSUFFICIENT_DISK_SPACE', 'message' => 'Full disk.']);

        $update->refresh();
        $this->assertSame(JobStatus::Failed, $update->status);
        $this->assertSame('BACKUP_REQUIRED_FAILED', $update->result_code);
        $this->assertSame('INSUFFICIENT_DISK_SPACE', $update->error['details']['backup']);
        $this->assertNotContains('UPDATE_CORE', array_column($this->pollJobs($site), 'type'), 'The update never runs.');
        $this->assertTrue(WebsiteEvent::where('event', 'update.blocked')->exists());
    }

    public function test_update_is_blocked_when_the_backup_cannot_even_start(): void
    {
        config(['backup.storage' => null]);
        $site = $this->backupReadySite();

        $update = app(JobQueue::class)->queue($site['website'], JobType::UpdateTheme, ['stylesheet' => 'twentytwentyfive']);

        $this->assertSame(JobStatus::Failed, $update->status);
        $this->assertSame('BACKUP_REQUIRED_FAILED', $update->result_code);
        $this->assertSame('STORAGE_NOT_CONFIGURED', $update->error['details']['backup']);
    }

    public function test_update_is_blocked_when_the_backup_is_corrupt(): void
    {
        $site = $this->backupReadySite();
        $update = app(JobQueue::class)->queue($site['website'], JobType::UpdatePlugin, ['plugin' => 'akismet/akismet.php']);
        $this->pollJobs($site);
        $backup = $update->backup;
        app(StorageManager::class)->set('local', new class implements BackupStorage
        {
            public function name(): string
            {
                return 'local';
            }

            public function put(string $key, $stream, int $size, string $sha256): void {}

            public function verify(string $key, int $size, string $sha256): Verification
            {
                return Verification::failed('CHECKSUM_MISMATCH', $size);
            }

            public function readRange(string $key, int $offset, int $length): string
            {
                return '';
            }

            public function delete(string $key): void {}
        });

        $this->uploadBackup($site, $backup, ['files' => 'FILES', 'database' => 'DB'])->assertStatus(202);

        $this->assertSame(BackupStatus::Corrupt, $backup->refresh()->status);
        $this->assertSame('BACKUP_REQUIRED_FAILED', $update->refresh()->result_code);
    }

    public function test_updates_run_directly_when_the_plan_does_not_require_a_backup(): void
    {
        $this->setUpBackups(['pre_update_backup' => false]);
        $site = $this->backupReadySite();

        $update = app(JobQueue::class)->queue($site['website'], JobType::UpdatePlugin, ['plugin' => 'akismet/akismet.php']);

        $this->assertSame(JobStatus::Queued, $update->status);
        $this->assertNull($update->backup_id);
        $this->assertSame(0, Backup::count());
    }

    /* ----- Restore ----- */

    public function test_restore_runs_only_after_a_verified_safety_backup(): void
    {
        $site = $this->backupReadySite();
        $source = $this->verifiedBackup($site['website'], at: now()->subDay());

        $restore = app(RestoreService::class)->request($site['website'], $source, BackupType::Database, 'customer:1', 'company.example');

        $this->assertSame(RestoreStatus::AwaitingSafetyBackup, $restore->status);
        $safety = $restore->safetyBackup;
        $this->assertSame(BackupReason::PreRestore, $safety->reason);
        $this->assertSame(BackupType::Full, $safety->type);
        $this->assertSame(['BACKUP_FULL'], array_column($this->pollJobs($site), 'type'), 'Nothing is restored before the safety backup.');

        $this->uploadBackup($site, $safety->refresh(), ['files' => 'NOW-FILES', 'database' => 'NOW-DB'])->assertStatus(202);

        $restore->refresh();
        $this->assertSame(RestoreStatus::Queued, $restore->status);
        $job = collect($this->pollJobs($site))->firstWhere('type', 'RESTORE');
        // Stored as JSON: key order is the database's (MySQL sorts keys), the content must match exactly.
        $this->assertEquals([
            'restore_uuid' => $restore->uuid,
            'backup_uuid' => $source->uuid,
            'scope' => 'database',
            'objects' => [['name' => 'database', 'size' => $source->objects()->where('name', 'database')->value('size'), 'sha256' => $source->objects()->where('name', 'database')->value('sha256')]],
        ], $job['payload']);
        $this->assertSame(RestoreStatus::Restoring, $restore->refresh()->status);

        // The connector downloads the backup for this restore, through WP CareGrid.
        $content = $this->withHeaders($this->connectorHeaders($site))->get(route('api.v1.connector.backups.content', [$source->uuid, 'database']).'?offset=0&length=5');
        $content->assertOk();
        $this->assertSame('DATAB', $content->getContent());
        $this->assertSame(hash('sha256', 'DATAB'), $content->headers->get('X-Chunk-SHA256'));
        $this->withHeaders($this->connectorHeaders($site))->get(route('api.v1.connector.backups.content', [$source->uuid, 'files']))->assertStatus(403);

        $this->reportJob($site, WebsiteJob::where('restore_id', $restore->id)->sole(), 'success', 'RESTORED', [
            'post_checks' => [['id' => 'files_present', 'passed' => true, 'message' => 'ok'], ['id' => 'database', 'passed' => true, 'message' => 'ok']],
            'health' => ['status' => 'healthy', 'findings' => [['id' => 'homepage', 'status' => 'pass', 'label' => 'Home page', 'message' => 'HTTP 200']]],
        ])->assertStatus(202);

        $restore->refresh();
        $this->assertSame(RestoreStatus::Restored, $restore->status);
        $this->assertSame(['restore.requested', 'restore.queued', 'restore.started', 'restore.completed'], WebsiteEvent::where('restore_id', $restore->id)->orderBy('id')->pluck('event')->all());
    }

    public function test_restore_is_not_successful_without_passing_post_checks(): void
    {
        [$site, $restore, $job] = $this->restoreInProgress();

        $this->reportJob($site, $job, 'success', 'RESTORED', ['destructive_mutation_started' => false, 'health' => ['status' => 'critical', 'findings' => [['id' => 'homepage', 'status' => 'fail', 'label' => 'Home page', 'message' => 'HTTP 500']]], 'post_checks' => [['id' => 'files_present', 'passed' => true, 'message' => 'ok']]]);

        $this->assertSame(RestoreStatus::NeedsAttention, $restore->refresh()->status);
    }

    public function test_failed_restore_is_reported(): void
    {
        [$site, $restore, $job] = $this->restoreInProgress();

        $this->reportJob($site, $job, 'failed', 'RESTORE_SQL_REJECTED', [], ['code' => 'RESTORE_SQL_REJECTED', 'message' => 'Refused.', 'details' => ['destructive_mutation_started' => false, 'failure_stage' => 'database_staging']]);

        $restore->refresh();
        $this->assertSame(RestoreStatus::Failed, $restore->status);
        $this->assertSame('RESTORE_SQL_REJECTED', $restore->error_code);
        $this->assertTrue(WebsiteEvent::where('event', 'restore.failed')->exists());
    }

    public function test_post_swap_file_failure_queues_one_verified_safety_recovery_and_records_both_outcomes(): void
    {
        [$site, $restore, $originalJob] = $this->restoreInProgress();
        $safety = $restore->safetyBackup;

        $this->reportJob($site, $originalJob, 'failed', 'RESTORE_FILES_FAILED', [], [
            'code' => 'RESTORE_FILES_FAILED',
            'message' => 'A file could not be restored.',
            'details' => ['destructive_mutation_started' => true, 'failure_stage' => 'file_apply'],
        ])->assertStatus(202);

        $restore->refresh();
        $recoveryJob = $restore->recoveryJob;
        $this->assertSame(RestoreStatus::RecoveryQueued, $restore->status);
        $this->assertSame('RESTORE_FILES_FAILED', $restore->error_code, 'The original failure remains visible.');
        $this->assertSame('file_apply', $restore->failure_stage);
        $this->assertNotNull($restore->destructive_started_at);
        $this->assertSame(1, $restore->recovery_attempts);
        $this->assertSame($safety->id, $recoveryJob->backup_id);
        $this->assertSame('full', $recoveryJob->payload['scope']);
        $this->assertSame($safety->uuid, $recoveryJob->payload['backup_uuid']);
        $this->assertSame(2, WebsiteJob::where('restore_id', $restore->id)->count());
        $this->assertSame('RESTORE_IN_PROGRESS', app(RestoreService::class)->refusal($site['website']->refresh(), $restore->backup, BackupType::Full));
        $this->assertTrue($restore->fresh()->recoveryJob()->where('website_id', $site['website']->id)->where('backup_id', $safety->id)->exists());
        $this->assertTrue($restore->website->restores()->where('safety_backup_id', $safety->id)->whereIn('status', [RestoreStatus::RecoveryQueued->value, RestoreStatus::Recovering->value])->exists());

        // The safety object is downloadable only through its designated recovery job.
        $this->withHeaders($this->connectorHeaders($site))
            ->get(route('api.v1.connector.backups.content', [$safety->uuid, 'database']).'?offset=0&length=5')
            ->assertOk();
        $this->withHeaders($this->connectorHeaders($site))
            ->get(route('api.v1.connector.backups.content', [$restore->backup->uuid, 'database']).'?offset=0&length=5')
            ->assertForbidden();

        $delivered = $this->pollJobs($site);
        $deliveredUuids = array_column($delivered, 'uuid');
        $this->assertContains($recoveryJob->uuid, $deliveredUuids);
        $this->assertNotContains($originalJob->uuid, $deliveredUuids, 'A completed failed restore must not be redelivered.');
        $restore->refresh();
        $this->assertSame(RestoreStatus::Recovering, $restore->status);

        $recoveryResponse = $this->reportJob($site, $recoveryJob, 'success', 'RESTORED', [
            'post_checks' => [['id' => 'database', 'passed' => true, 'message' => 'ok']],
            'health' => ['status' => 'healthy', 'findings' => []],
            'destructive_mutation_started' => true,
        ]);
        $recoveryResponse->assertStatus(202);

        $restore->refresh();
        $this->assertSame(RestoreStatus::Recovered, $restore->status);
        $this->assertSame('RESTORE_FILES_FAILED', $restore->error_code);
        $this->assertNull($restore->recovery_error_code);
        $this->assertFalse($restore->manual_recovery_required);
        $this->assertNotNull($restore->recovery_completed_at);
        $this->assertNotNull($restore->completed_at);
        $this->assertSame(2, WebsiteJob::where('restore_id', $restore->id)->count(), 'No recursive recovery job was created.');
        $this->assertEquals([
            'restore.requested', 'restore.queued', 'restore.started', 'restore.failed',
            'restore.recovery_requested', 'restore.recovery_started', 'restore.recovery_completed',
        ], WebsiteEvent::where('restore_id', $restore->id)->orderBy('id')->pluck('event')->all());

        // A duplicate final result is acknowledged without repeating recovery.
        $this->reportJob($site, $recoveryJob, 'success', 'RESTORED', [
            'post_checks' => [['id' => 'database', 'passed' => true, 'message' => 'ok']],
            'health' => ['status' => 'healthy'],
        ])->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(2, WebsiteJob::where('restore_id', $restore->id)->count());
        $this->assertSame(RestoreStatus::Recovered, $restore->refresh()->status);
    }

    public function test_post_swap_failure_without_eligible_safety_backup_fails_closed_for_manual_recovery(): void
    {
        [$site, $restore, $job] = $this->restoreInProgress();
        $restore->safetyBackup->forceFill(['status' => BackupStatus::Corrupt])->save();

        $this->reportJob($site, $job, 'failed', 'RESTORE_FILES_FAILED', [], [
            'code' => 'RESTORE_FILES_FAILED',
            'message' => 'A file could not be restored.',
            'details' => ['destructive_mutation_started' => true, 'failure_stage' => 'file_apply'],
        ])->assertStatus(202);

        $restore->refresh();
        $this->assertSame(RestoreStatus::ManualRecoveryRequired, $restore->status);
        $this->assertTrue($restore->manual_recovery_required);
        $this->assertSame('RESTORE_FILES_FAILED', $restore->error_code);
        $this->assertSame('SAFETY_BACKUP_NOT_ELIGIBLE', $restore->recovery_error_code);
        $this->assertNull($restore->recovery_job_id);
        $this->assertSame(1, WebsiteJob::where('restore_id', $restore->id)->count());
        $this->assertTrue(WebsiteEvent::where('restore_id', $restore->id)->where('event', 'restore.manual_recovery_required')->exists());
    }

    public function test_failed_compensating_recovery_is_bounded_and_does_not_recurse(): void
    {
        [$site, $restore, $originalJob] = $this->restoreInProgress();
        $this->reportJob($site, $originalJob, 'failed', 'RESTORE_FILES_FAILED', [], [
            'code' => 'RESTORE_FILES_FAILED', 'message' => 'Original file failure.',
            'details' => ['destructive_mutation_started' => true, 'failure_stage' => 'file_apply'],
        ])->assertStatus(202);

        $recoveryJob = $restore->refresh()->recoveryJob;
        $this->pollJobs($site);
        $this->reportJob($site, $recoveryJob, 'failed', 'RESTORE_DATABASE_FAILED', [], [
            'code' => 'RESTORE_DATABASE_FAILED', 'message' => 'Safety restore failed.',
            'details' => ['destructive_mutation_started' => false, 'failure_stage' => 'database_staging'],
        ])->assertStatus(202);

        $restore->refresh();
        $this->assertSame(RestoreStatus::RecoveryFailed, $restore->status);
        $this->assertTrue($restore->manual_recovery_required);
        $this->assertSame('RESTORE_FILES_FAILED', $restore->error_code, 'The original failure remains recorded.');
        $this->assertSame('RECOVERY_DATABASE_FAILED', $restore->recovery_error_code);
        $this->assertSame(1, $restore->recovery_attempts);
        $this->assertSame(2, WebsiteJob::where('restore_id', $restore->id)->count(), 'Recovery failure does not enqueue another recovery.');
    }

    public function test_file_failure_during_recovery_is_classified_and_requires_manual_intervention(): void
    {
        [$site, $restore, $originalJob] = $this->restoreInProgress();
        $this->reportJob($site, $originalJob, 'failed', 'RESTORE_FILES_FAILED', [], [
            'code' => 'RESTORE_FILES_FAILED', 'message' => 'Original file failure.',
            'details' => ['destructive_mutation_started' => true, 'failure_stage' => 'file_apply'],
        ])->assertStatus(202);
        $recoveryJob = $restore->refresh()->recoveryJob;
        $this->pollJobs($site);

        $this->reportJob($site, $recoveryJob, 'failed', 'RESTORE_FILES_FAILED', [], [
            'code' => 'RESTORE_FILES_FAILED', 'message' => 'Safety file copy failed.',
            'details' => ['destructive_mutation_started' => true, 'failure_stage' => 'file_apply'],
        ])->assertStatus(202);

        $restore->refresh();
        $this->assertSame(RestoreStatus::RecoveryFailed, $restore->status);
        $this->assertSame('RECOVERY_FILES_FAILED', $restore->recovery_error_code);
        $this->assertSame('RESTORE_FILES_FAILED', $restore->recovery_result['connector_error_code']);
        $this->assertTrue($restore->manual_recovery_required);
        $this->assertSame(2, WebsiteJob::where('restore_id', $restore->id)->count());
    }

    public function test_interruption_during_database_swap_is_unknown_and_requires_manual_recovery(): void
    {
        [$site, $restore, $job] = $this->restoreInProgress();
        $this->reportJob($site, $job, 'failed', 'JOB_INTERRUPTED', [], [
            'code' => 'JOB_INTERRUPTED', 'message' => 'The restore worker stopped during the database swap.',
            'details' => ['destructive_mutation_started' => null, 'failure_stage' => 'database_swap'],
        ])->assertStatus(202);

        $restore->refresh();
        $this->assertSame(RestoreStatus::ManualRecoveryRequired, $restore->status);
        $this->assertSame('DESTRUCTIVE_STATE_UNKNOWN', $restore->recovery_error_code);
        $this->assertNull($restore->destructive_started_at, 'The server does not claim a swap it cannot prove.');
        $this->assertSame(1, WebsiteJob::where('restore_id', $restore->id)->count());
    }

    public function test_failed_safety_backup_stops_the_restore(): void
    {
        $site = $this->backupReadySite();
        $source = $this->verifiedBackup($site['website'], at: now()->subDay());
        $restore = app(RestoreService::class)->request($site['website'], $source, BackupType::Full, 'customer:1', 'company.example');
        $this->pollJobs($site);

        $this->reportJob($site, $this->backupJob($restore->safetyBackup), 'failed', 'INSUFFICIENT_DISK_SPACE', [], ['code' => 'INSUFFICIENT_DISK_SPACE', 'message' => 'Full disk.']);

        $restore->refresh();
        $this->assertSame(RestoreStatus::Failed, $restore->status);
        $this->assertSame('SAFETY_BACKUP_FAILED', $restore->error_code);
        $this->assertSame(0, WebsiteJob::where('type', 'RESTORE')->count());
    }

    public function test_restore_request_refusals(): void
    {
        $site = $this->backupReadySite();
        $website = $site['website'];
        $full = $this->verifiedBackup($website, BackupType::Full, now()->subDay());
        $files = $this->verifiedBackup($website, BackupType::Files, now()->subDays(2));
        $expired = $this->verifiedBackup($website, BackupType::Full, now()->subDays(3));
        $expired->forceFill(['expires_at' => now()->subHour()])->save();
        $failed = $website->backups()->create(['type' => BackupType::Full, 'reason' => BackupReason::Manual, 'status' => BackupStatus::Failed, 'requested_by' => 'test']);
        $other = $this->backupReadySite('https://other.example');
        $foreign = $this->verifiedBackup($other['website'], BackupType::Full);

        $this->assertRefused('RESTORE_TYPE_MISMATCH', fn () => app(RestoreService::class)->request($website, $files, BackupType::Database, 'c', 'company.example'));
        $this->assertRefused('BACKUP_NOT_RESTORABLE', fn () => app(RestoreService::class)->request($website, $expired, BackupType::Full, 'c', 'company.example'));
        $this->assertRefused('BACKUP_NOT_RESTORABLE', fn () => app(RestoreService::class)->request($website, $failed, BackupType::Full, 'c', 'company.example'));
        $this->assertRefused('BACKUP_NOT_FOUND', fn () => app(RestoreService::class)->request($website, $foreign, BackupType::Full, 'c', 'company.example'));
        $this->assertRefused('CONFIRMATION_MISMATCH', fn () => app(RestoreService::class)->request($website, $full, BackupType::Full, 'c', 'other.example'));

        $website->activeConnector->update(['capabilities' => ['backup_full', 'restore_files']]);
        $this->assertRefused('CAPABILITY_MISSING', fn () => app(RestoreService::class)->request($website->refresh(), $full, BackupType::Database, 'c', 'company.example'));

        $website->activeConnector->forceFill(['last_seen_at' => now()->subHours(2)])->save();
        $this->assertRefused('NOT_CONNECTED', fn () => app(RestoreService::class)->request($website->refresh(), $full, BackupType::Files, 'c', 'company.example'));

        config(['backup.restore.enabled' => false]);
        $this->assertRefused('RESTORE_NOT_AVAILABLE', fn () => app(RestoreService::class)->request($website, $full, BackupType::Full, 'c', 'company.example'));

        $this->assertSame(0, BackupRestore::count());
    }

    public function test_stale_connector_does_not_receive_a_restore(): void
    {
        [$site, $restore, $job] = $this->restoreInProgress(deliver: false);
        $site['website']->activeConnector->forceFill(['last_seen_at' => now()->subHours(2), 'last_heartbeat_at' => now()->subHours(2)])->save();

        // Refused at delivery time too: presence is checked again.
        $this->assertSame('connector is not currently reporting in', app(JobQueue::class)->refusal($site['website']->refresh(), $site['website']->activeConnector, JobType::Restore, ['scope' => 'full']));
    }

    /** @return array{0: array, 1: BackupRestore, 2: WebsiteJob} */
    private function restoreInProgress(bool $deliver = true): array
    {
        $site = $this->backupReadySite();
        $source = $this->verifiedBackup($site['website'], at: now()->subDay());
        $restore = app(RestoreService::class)->request($site['website'], $source, BackupType::Full, 'customer:1', 'company.example');
        $this->pollJobs($site);
        $this->uploadBackup($site, $restore->safetyBackup, ['files' => 'NOW-FILES', 'database' => 'NOW-DB']);
        if ($deliver) {
            $this->pollJobs($site);
        }

        return [$site, $restore->refresh(), WebsiteJob::where('restore_id', $restore->id)->sole()];
    }

    private function assertRefused(string $code, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected refusal {$code}.");
        } catch (BackupRefused $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }
}
