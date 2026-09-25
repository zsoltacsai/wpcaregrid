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
use App\Backups\Storage\LocalStorage;
use App\Backups\Storage\StorageManager;
use App\Backups\Storage\StorageUnavailable;
use App\Backups\Storage\Verification;
use App\Jobs\TransferBackup;
use App\Models\Backup;
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

        $job = new TransferBackup($backup->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(StorageManager::class), app(BackupService::class), app(Retention::class));
        $job->assertReleased(60);
        $this->assertSame(BackupStatus::Uploaded, $backup->refresh()->status, 'Waiting for another attempt, not verified.');

        // Last attempt: the backup fails and says why.
        $last = (new TransferBackup($backup->id))->withFakeQueueInteractions();
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
        $this->assertNotNull($backup->objects_deleted_at, 'Unusable objects are removed; the record stays.');
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

    public function test_the_only_verified_backup_needs_explicit_confirmation_to_delete(): void
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

        $this->artisan('backup:admin', ['action' => 'delete', 'uuid' => $only->uuid, '--confirm-only-backup' => true])->assertSuccessful();
        $this->assertSame(BackupStatus::Deleted, $only->refresh()->status);
        $this->assertTrue(WebsiteEvent::where('event', 'backup.deleted')->where('actor', 'operator')->exists());
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
