<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupDeletion;
use App\Backups\BackupPolicy;
use App\Backups\BackupReason;
use App\Backups\BackupRefused;
use App\Backups\BackupService;
use App\Backups\BackupStatus;
use App\Backups\BackupType;
use App\Backups\Retention;
use App\Backups\Storage\BackupStorage;
use App\Backups\Storage\InspectableBackupStorage;
use App\Backups\Storage\LocalStorage;
use App\Backups\Storage\ObjectPresence;
use App\Backups\Storage\StorageManager;
use App\Backups\Storage\StorageUnavailable;
use App\Backups\Storage\Verification;
use App\Jobs\TransferBackup;
use App\Models\Backup;
use App\Models\BackupObjectDeletion;
use App\Models\WebsiteEvent;
use App\Pricing\PlanTier;
use App\Pricing\ProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesBackups;
use Tests\TestCase;

class TransferAndRetentionTest extends TestCase
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

    /* ----- Transfer and verification ----- */

    public function test_queued_transfer_is_a_no_op_when_the_backup_was_deleted(): void
    {
        $site = $this->backupReadySite();
        Queue::fake();
        $backup = app(BackupService::class)->request($site['website'], BackupReason::Manual, 'test', BackupType::Files);
        $backupId = $backup->id;

        $backup->delete();
        (new TransferBackup($backupId, (int) $site['website']->id, (string) $backup->storage_provider, []))->handle(app(StorageManager::class), app(BackupService::class), app(Retention::class));

        $this->assertDatabaseMissing('backups', ['id' => $backupId]);
        $this->assertDatabaseMissing('website_events', ['event' => 'backup.verified', 'website_id' => $site['website']->id]);
    }

    public function test_queued_transfer_is_a_no_op_when_backup_is_no_longer_uploaded(): void
    {
        $site = $this->backupReadySite();
        Queue::fake();
        $backup = app(BackupService::class)->request($site['website'], BackupReason::Manual, 'test', BackupType::Files);
        $backup->forceFill(['status' => BackupStatus::Verified])->save();

        TransferBackup::forBackup($backup)->handle(app(StorageManager::class), app(BackupService::class), app(Retention::class));

        $this->assertSame(BackupStatus::Verified, $backup->refresh()->status);
        $this->assertDatabaseMissing('website_events', ['event' => 'backup.verified', 'website_id' => $site['website']->id]);
    }

    public function test_queued_transfer_does_not_follow_a_backup_reparented_to_another_website(): void
    {
        $siteA = $this->backupReadySite('https://tenant-a.example');
        $siteB = $this->backupReadySite('https://tenant-b.example');
        Queue::fake();
        $backup = app(BackupService::class)->request($siteA['website'], BackupReason::Manual, 'test', BackupType::Files);
        $this->pollJobs($siteA);
        $this->uploadBackup($siteA, $backup, ['files' => 'SYNTHETIC TENANT A'])->assertStatus(202);

        $job = TransferBackup::forBackup($backup->refresh());

        $backup->forceFill(['website_id' => $siteB['website']->id])->save();

        $job->handle(app(StorageManager::class), app(BackupService::class), app(Retention::class));

        $this->assertSame(BackupStatus::Uploaded, $backup->refresh()->status);
        $this->assertDatabaseMissing('website_events', ['event' => 'backup.verified', 'website_id' => $siteB['website']->id]);
    }

    public function test_queued_transfer_is_a_no_op_when_the_object_dependency_set_changes(): void
    {
        $siteA = $this->backupReadySite('https://tenant-a.example');
        $siteB = $this->backupReadySite('https://tenant-b.example');
        Queue::fake();

        $backup = app(BackupService::class)->request($siteA['website'], BackupReason::Manual, 'test', BackupType::Files);
        $this->pollJobs($siteA);
        $this->uploadBackup($siteA, $backup, ['files' => 'SYNTHETIC TENANT A'])->assertStatus(202);
        $job = TransferBackup::forBackup($backup->refresh());

        $otherBackup = app(BackupService::class)->request($siteB['website'], BackupReason::Manual, 'test', BackupType::Files);
        $object = $backup->objects()->firstOrFail();
        $object->forceFill(['backup_id' => $otherBackup->id])->save();

        $job->handle(app(StorageManager::class), app(BackupService::class), app(Retention::class));

        $this->assertSame(BackupStatus::Uploaded, $backup->refresh()->status);
        $this->assertSame($otherBackup->id, $object->refresh()->backup_id);
        $this->assertDatabaseMissing('website_events', ['event' => 'backup.verified', 'website_id' => $siteA['website']->id]);
    }

    public function test_queued_transfer_is_a_no_op_when_the_storage_provider_changes(): void
    {
        $site = $this->backupReadySite();
        Queue::fake();
        $backup = app(BackupService::class)->request($site['website'], BackupReason::Manual, 'test', BackupType::Files);
        $this->pollJobs($site);
        $this->uploadBackup($site, $backup, ['files' => 'SYNTHETIC FILES'])->assertStatus(202);
        $job = TransferBackup::forBackup($backup->refresh());

        $backup->forceFill(['storage_provider' => 'alternate'])->save();

        $job->handle(app(StorageManager::class), app(BackupService::class), app(Retention::class));

        $this->assertSame(BackupStatus::Uploaded, $backup->refresh()->status);
        $this->assertDatabaseMissing('website_events', ['event' => 'backup.verified', 'website_id' => $site['website']->id]);
    }

    public function test_legacy_transfer_without_enqueue_scope_is_a_safe_no_op(): void
    {
        $site = $this->backupReadySite();
        Queue::fake();
        $backup = app(BackupService::class)->request($site['website'], BackupReason::Manual, 'test', BackupType::Files);
        $this->pollJobs($site);
        $this->uploadBackup($site, $backup, ['files' => 'SYNTHETIC FILES'])->assertStatus(202);

        (new TransferBackup($backup->id))->handle(app(StorageManager::class), app(BackupService::class), app(Retention::class));

        $this->assertSame(BackupStatus::Uploaded, $backup->refresh()->status);
        $this->assertDatabaseMissing('website_events', ['event' => 'backup.verified', 'website_id' => $site['website']->id]);
    }

    public function test_storage_outage_is_retried_then_fails_without_a_false_green(): void
    {
        $site = $this->backupReadySite();
        $this->useStorage(new class implements BackupStorage
        {
            public function name(): string
            {
                return 'local';
            }

            public function put(string $key, $stream, int $size, string $sha256): void
            {
                throw new StorageUnavailable('down');
            }

            public function verify(string $key, int $size, string $sha256): Verification
            {
                throw new StorageUnavailable('down');
            }

            public function readRange(string $key, int $offset, int $length): string
            {
                throw new StorageUnavailable('down');
            }

            public function delete(string $key): void {}
        });
        Queue::fake();
        $backup = app(BackupService::class)->request($site['website'], BackupReason::Manual, 'test', BackupType::Files);
        $this->pollJobs($site);
        $this->uploadBackup($site, $backup, ['files' => 'FILES'])->assertStatus(202);
        Queue::assertPushed(TransferBackup::class);

        $job = TransferBackup::forBackup($backup);
        $job->withFakeQueueInteractions();
        $job->handle(app(StorageManager::class), app(BackupService::class), app(Retention::class));
        $job->assertReleased(60);
        $this->assertSame(BackupStatus::Uploaded, $backup->refresh()->status, 'Waiting for another attempt, not verified.');

        // Last attempt: the backup fails and says why.
        $last = TransferBackup::forBackup($backup)->withFakeQueueInteractions();
        $last->job->attempts = 3;
        $last->handle(app(StorageManager::class), app(BackupService::class), app(Retention::class));
        $backup->refresh();
        $this->assertSame(BackupStatus::Failed, $backup->status);
        $this->assertSame('STORAGE_UNAVAILABLE', $backup->error_code);
        $this->assertSame(2, $backup->transfer_attempts);
        $this->assertTrue(WebsiteEvent::where('event', 'backup.failed')->exists());
    }

    public static function badStorage(): array
    {
        return [
            'checksum mismatch' => ['CHECKSUM_MISMATCH', 'VERIFICATION_CHECKSUM_MISMATCH'],
            'size mismatch' => ['SIZE_MISMATCH', 'VERIFICATION_SIZE_MISMATCH'],
            'object missing' => ['MISSING', 'VERIFICATION_MISSING'],
            'no way to verify' => ['UNVERIFIABLE', 'VERIFICATION_UNAVAILABLE'],
        ];
    }

    #[DataProvider('badStorage')]
    public function test_failed_verification_marks_the_backup_corrupt(string $failure, string $code): void
    {
        $site = $this->backupReadySite();
        $this->useStorage(new class($this->storageRoot, $failure) implements BackupStorage
        {
            private LocalStorage $local;

            public function __construct(string $root, private string $failure)
            {
                $this->local = new LocalStorage($root);
            }

            public function name(): string
            {
                return 'local';
            }

            public function put(string $key, $stream, int $size, string $sha256): void
            {
                $this->local->put($key, $stream, $size, $sha256);
            }

            public function verify(string $key, int $size, string $sha256): Verification
            {
                return Verification::failed($this->failure, $size);
            }

            public function readRange(string $key, int $offset, int $length): string
            {
                return '';
            }

            public function delete(string $key): void
            {
                $this->local->delete($key);
            }
        });

        $backup = app(BackupService::class)->request($site['website'], BackupReason::Manual, 'test', BackupType::Files);
        $this->pollJobs($site);
        $this->uploadBackup($site, $backup, ['files' => 'FILES'])->assertStatus(202);

        $backup->refresh();
        $this->assertSame(BackupStatus::Corrupt, $backup->status);
        $this->assertSame($code, $backup->error_code);
        $this->assertNull($backup->verified_at);
        $this->assertNull($backup->objects_deleted_at, 'Unverified provider cleanup must not be recorded as complete.');
        $this->assertSame('reconciliation_required', $backup->deletion_state);
        $this->assertSame(3, BackupObjectDeletion::where('backup_id', $backup->id)->where('state', 'reconciliation_required')->count());
        $this->assertTrue(WebsiteEvent::where('event', 'backup.corrupt')->exists());
    }

    public function test_readback_verification_catches_data_changed_in_storage(): void
    {
        $site = $this->backupReadySite();
        $backup = $this->makeVerifiedBackupThroughApi($site, app(BackupService::class)->request($site['website'], BackupReason::Manual, 'test', BackupType::Files));
        $this->assertSame(BackupStatus::Verified, $backup->status);

        file_put_contents($this->storageRoot.'/'.$backup->storage_path.'files.tar.gz', 'TAMPERED-BYTES-XXXX');

        $this->artisan('backup:admin', ['action' => 'verify', 'uuid' => $backup->uuid])->assertFailed();
        $this->assertSame(BackupStatus::Corrupt, $backup->refresh()->status);
    }

    /* ----- Policy and scheduling ----- */

    public static function plans(): array
    {
        return [
            ['wordpress', 'basic', 'monthly'], ['wordpress', 'business', 'weekly'], ['wordpress', 'secure', 'daily'],
            ['woocommerce', 'basic', 'weekly'], ['woocommerce', 'business', 'daily'], ['woocommerce', 'secure', 'daily'],
        ];
    }

    #[DataProvider('plans')]
    public function test_frequency_comes_from_the_plan(string $product, string $tier, string $frequency): void
    {
        $site = $this->backupReadySite();
        $site['website']->subscription->update(['product_type' => ProductType::from($product), 'plan' => PlanTier::from($tier)]);

        $decision = app(BackupPolicy::class)->for($site['website']->refresh());

        $this->assertTrue($decision->eligible);
        $this->assertSame($frequency, $decision->frequency);
        $this->assertSame(BackupType::Full, $decision->type);
        $this->assertSame('local', $decision->storage);
    }

    public function test_retention_has_no_invented_default(): void
    {
        $this->assertNull(config('backup.plans.wordpress.basic.retention_count'));
        $this->assertNull(config('backup.plans.wordpress.basic.retention_days'));
        $this->assertFalse(app(BackupPolicy::class)->for($this->backupReadySite()['website'])->hasRetention());
    }

    public function test_schedule_windows(): void
    {
        $at = new \DateTimeImmutable('2026-09-25 10:00:00', new \DateTimeZone('UTC'));
        $this->assertSame('daily:2026-09-25', BackupPolicy::window('daily', $at));
        $this->assertSame('weekly:2026-W39', BackupPolicy::window('weekly', $at));
        $this->assertSame('monthly:2026-09', BackupPolicy::window('monthly', $at));
    }

    public function test_scheduler_queues_one_backup_per_window(): void
    {
        $site = $this->backupReadySite();
        $site['website']->subscription->update(['product_type' => ProductType::WordPress, 'plan' => PlanTier::Secure]);

        $this->artisan('backup:schedule')->assertSuccessful();
        $this->artisan('backup:schedule')->assertSuccessful();

        $backups = Backup::all();
        $this->assertCount(1, $backups);
        $this->assertSame(BackupReason::Scheduled, $backups[0]->reason);
        $this->assertSame('daily:'.now()->utc()->format('Y-m-d'), $backups[0]->schedule_window);
        $this->assertSame(1, $site['website']->jobs()->where('type', 'BACKUP_FULL')->count());

        // The database refuses a duplicate even if two schedulers race.
        $this->expectException(BackupRefused::class);
        app(BackupService::class)->request($site['website'], BackupReason::Scheduled, 'race', null, ['schedule_window' => $backups[0]->schedule_window]);
    }

    public function test_scheduler_skips_ineligible_and_absent_sites(): void
    {
        $inactive = $this->backupReadySite('https://inactive.example');
        $inactive['website']->subscription->update(['status' => 'past_due']);
        $stale = $this->backupReadySite('https://stale.example');
        $stale['website']->activeConnector->forceFill(['last_seen_at' => now()->subHours(2)])->save();

        $this->artisan('backup:schedule')->assertSuccessful();

        $this->assertSame(0, Backup::count());
    }

    /* ----- Retention ----- */

    public function test_retention_count_keeps_the_newest(): void
    {
        $this->setUpBackups(['retention_count' => 2]);
        $website = $this->backupReadySite()['website'];
        $old = $this->verifiedBackup($website, at: now()->subDays(3));
        $middle = $this->verifiedBackup($website, at: now()->subDays(2));
        $new = $this->verifiedBackup($website, at: now()->subDay());

        $this->assertSame([$old->uuid], app(Retention::class)->apply($website));

        $this->assertSame(BackupStatus::Expired, $old->refresh()->status);
        $this->assertNotNull($old->objects_deleted_at);
        $this->assertFileDoesNotExist($this->storageRoot.'/'.$old->storage_path.'files.tar.gz');
        $this->assertSame(BackupStatus::Verified, $middle->refresh()->status);
        $this->assertSame(BackupStatus::Verified, $new->refresh()->status);
        $this->assertTrue(WebsiteEvent::where('event', 'backup.expired')->where('backup_id', $old->id)->exists());
        $this->assertNotNull(Backup::find($old->id), 'Metadata is kept.');
    }

    public function test_retention_days_never_expires_the_newest_verified_backup(): void
    {
        $this->setUpBackups(['retention_days' => 7]);
        $website = $this->backupReadySite()['website'];
        $ancient = $this->verifiedBackup($website, at: now()->subDays(40));
        $newest = $this->verifiedBackup($website, at: now()->subDays(30));
        // Failed backups do not count as a replacement.
        $website->backups()->create(['type' => BackupType::Full, 'reason' => BackupReason::Scheduled, 'status' => BackupStatus::Failed, 'requested_by' => 'test']);

        app(Retention::class)->apply($website);

        $this->assertSame(BackupStatus::Expired, $ancient->refresh()->status);
        $this->assertSame(BackupStatus::Verified, $newest->refresh()->status);
    }

    public function test_without_retention_values_nothing_is_deleted(): void
    {
        $website = $this->backupReadySite()['website'];
        foreach (range(1, 5) as $day) {
            $this->verifiedBackup($website, at: now()->subDays(100 + $day));
        }

        $this->assertSame([], app(Retention::class)->apply($website));
        $this->assertSame(5, $website->backups()->verified()->count());
    }

    public function test_the_only_verified_recovery_point_cannot_be_deleted_even_with_legacy_confirmation(): void
    {
        $website = $this->backupReadySite()['website'];
        $only = $this->verifiedBackup($website);

        try {
            app(BackupDeletion::class)->remove($only, BackupStatus::Deleted, 'operator');
            $this->fail('Deleted the only verified backup.');
        } catch (BackupRefused $e) {
            $this->assertSame('ONLY_VERIFIED_BACKUP', $e->errorCode);
        }
        $this->artisan('backup:admin', ['action' => 'delete', 'uuid' => $only->uuid])->assertFailed();
        $this->assertSame(BackupStatus::Verified, $only->refresh()->status);

        $this->artisan('backup:admin', ['action' => 'delete', 'uuid' => $only->uuid, '--confirm-only-backup' => true])->assertFailed();
        $this->assertSame(BackupStatus::Verified, $only->refresh()->status);
        $this->assertSame('not_requested', $only->deletion_state);
        $this->assertFalse(WebsiteEvent::where('event', 'backup.deleted')->where('actor', 'operator')->exists());
    }

    public function test_a_verified_backup_on_another_destination_does_not_protect_this_destination_last_point(): void
    {
        $website = $this->backupReadySite()['website'];
        $onlyLocal = $this->verifiedBackup($website);
        $otherDestination = $this->verifiedBackup($website, at: now()->subMinute());
        $otherDestination->forceFill(['storage_provider' => 'sftp'])->save();

        try {
            app(BackupDeletion::class)->remove($onlyLocal, BackupStatus::Deleted, 'operator');
            $this->fail('A backup on another destination protected the only local recovery point.');
        } catch (BackupRefused $e) {
            $this->assertSame('ONLY_VERIFIED_BACKUP', $e->errorCode);
        }
    }

    public function test_partial_object_delete_is_persisted_and_backup_cannot_be_restored(): void
    {
        $website = $this->backupReadySite()['website'];
        $backup = $this->verifiedBackup($website, at: now()->subDays(2));
        $this->verifiedBackup($website);
        $local = new LocalStorage($this->storageRoot);
        $this->useStorage(new class($local, $backup) implements InspectableBackupStorage
        {
            public function __construct(private LocalStorage $local, private Backup $backup) {}

            public function name(): string
            {
                return 'local';
            }

            public function put(string $key, $stream, int $size, string $sha256): void
            {
                $this->local->put($key, $stream, $size, $sha256);
            }

            public function verify(string $key, int $size, string $sha256): Verification
            {
                return $this->local->verify($key, $size, $sha256);
            }

            public function readRange(string $key, int $offset, int $length): string
            {
                return $this->local->readRange($key, $offset, $length);
            }

            public function delete(string $key): void
            {
                $this->local->delete($key);
            }

            public function presence(string $key): ObjectPresence
            {
                return str_ends_with($key, 'database.sql.gz') ? ObjectPresence::Present : $this->local->presence($key);
            }
        });

        try {
            app(BackupDeletion::class)->remove($backup, BackupStatus::Expired, 'retention');
            $this->fail('A partial object deletion was reported as complete.');
        } catch (BackupRefused $exception) {
            $this->assertSame('DELETION_RECONCILIATION_REQUIRED', $exception->errorCode);
        }

        $backup->refresh();
        $this->assertSame(BackupStatus::Verified, $backup->status);
        $this->assertSame('partially_deleted', $backup->deletion_state);
        $this->assertNull($backup->objects_deleted_at);
        $this->assertFalse($backup->isRestorable());
        $this->assertSame(2, $backup->objectDeletions()->where('state', 'provider_confirmed')->count());
        $this->assertSame(1, $backup->objectDeletions()->where('state', 'reconciliation_required')->count());
        $this->assertTrue(WebsiteEvent::where('event', 'backup.deletion_partial')->where('backup_id', $backup->id)->exists());
    }

    public function test_successful_read_after_delete_records_logical_absence_per_object(): void
    {
        $website = $this->backupReadySite()['website'];
        $backup = $this->verifiedBackup($website, at: now()->subDays(2));
        $this->verifiedBackup($website);

        app(BackupDeletion::class)->remove($backup, BackupStatus::Expired, 'retention');

        $this->assertSame(BackupStatus::Expired, $backup->refresh()->status);
        $this->assertSame('provider_confirmed', $backup->deletion_state);
        $this->assertNotNull($backup->objects_deleted_at);
        $this->assertSame(3, $backup->objectDeletions()->where('state', 'provider_confirmed')->count());
        $this->assertSame(3, $backup->objectDeletions()->where('verification_state', 'logical_absence_verified')->count());
        $attempts = $backup->objectDeletions()->sum('attempts');

        app(BackupDeletion::class)->remove($backup, BackupStatus::Expired, 'retention-retry');

        $this->assertSame($attempts, $backup->objectDeletions()->sum('attempts'));
        $this->assertSame(BackupStatus::Expired, $backup->refresh()->status);
    }

    public function test_operator_can_request_and_retry_backups(): void
    {
        $site = $this->backupReadySite();

        $this->artisan('backup:admin', ['action' => 'run', 'uuid' => $site['website']->uuid, '--type' => 'files'])->assertSuccessful();
        $failed = Backup::sole();
        app(BackupService::class)->fail($failed, 'STORAGE_UNAVAILABLE');

        $this->artisan('backup:admin', ['action' => 'retry', 'uuid' => $failed->uuid])->assertSuccessful();
        $this->artisan('backup:admin', ['action' => 'show', 'uuid' => $failed->uuid])->assertSuccessful()->expectsOutputToContain('backup.failed');

        $retry = Backup::where('retry_of_id', $failed->id)->sole();
        $this->assertSame(BackupType::Files, $retry->type);
        $this->assertSame('operator', $retry->requested_by);
    }

    private function useStorage(BackupStorage $storage): void
    {
        app(StorageManager::class)->set('local', $storage);
    }
}
