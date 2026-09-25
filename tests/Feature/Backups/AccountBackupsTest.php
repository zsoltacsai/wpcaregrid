<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupReason;
use App\Backups\BackupService;
use App\Backups\BackupStatus;
use App\Backups\BackupType;
use App\Backups\RestoreStatus;
use App\Models\Backup;
use App\Models\BackupRestore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBackups;
use Tests\TestCase;

/** The customer's view: only verified backups are shown as successful. */
class AccountBackupsTest extends TestCase
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

    public function test_backup_now_queues_a_job_and_does_not_run_it_in_the_request(): void
    {
        $site = $this->backupReadySite();
        $user = $site['website']->customer->user;

        $this->actingAs($user)->post(route('account.websites.backups.store', $site['website']))
            ->assertRedirect()->assertSessionHas('status', __('backups.account.queued'));

        $backup = Backup::sole();
        $this->assertSame(BackupStatus::Queued, $backup->status);
        $this->assertSame(BackupReason::Manual, $backup->reason);
        $this->assertSame('customer:'.$user->id, $backup->requested_by);

        // A second click right away is refused politely.
        $this->actingAs($user)->post(route('account.websites.backups.store', $site['website']))->assertSessionHas('backup_error', __('backups.refusals.BACKUP_IN_PROGRESS'));
        $this->assertSame(1, Backup::count());
    }

    public function test_backup_now_needs_an_active_plan_and_a_connection(): void
    {
        $site = $this->backupReadySite();
        $user = $site['website']->customer->user;
        $site['website']->activeConnector->revoke('test');

        $this->actingAs($user)->post(route('account.websites.backups.store', $site['website']))->assertSessionHas('backup_error', __('backups.refusals.NOT_CONNECTED'));

        $site['website']->subscription->update(['status' => 'unpaid']);
        $this->actingAs($user)->post(route('account.websites.backups.store', $site['website']))->assertForbidden();
        $this->assertSame(0, Backup::count());
    }

    public function test_website_page_shows_the_latest_verified_backup(): void
    {
        $site = $this->backupReadySite();
        $backup = $this->verifiedBackup($site['website'], BackupType::Full, now()->subHours(3));
        $backup->update(['total_size' => 148_897_792]);

        $this->actingAs($site['website']->customer->user)->get(route('account.websites.show', $site['website']))
            ->assertOk()
            ->assertSeeInOrder([__('backups.account.latest'), __('backups.statuses.verified'), __('backups.account.type'), 'Full', __('backups.account.size'), '142 MB'])
            ->assertSee(__('backups.account.backup_now'))
            ->assertSee(__('backups.account.view_all'));
    }

    public function test_unverified_and_failed_backups_are_never_green(): void
    {
        $site = $this->backupReadySite();
        $website = $site['website'];

        // In progress: not "verified", not "created".
        app(BackupService::class)->request($website, BackupReason::Manual, 'test');
        $page = $this->actingAs($website->customer->user)->get(route('account.websites.show', $website));
        $page->assertSee(__('backups.account.in_progress'))->assertSee(__('backups.account.none_verified'))->assertDontSee('Backup created');

        // Failed: the reason in plain words, retry available, no internals.
        $backup = Backup::sole();
        app(BackupService::class)->fail($backup, 'STORAGE_UNAVAILABLE', 'S3 storage error: AccessDenied at /var/www/app');
        $this->actingAs($website->customer->user)->get(route('account.websites.show', $website))
            ->assertSee(__('backups.account.failed_title'))
            ->assertSee('Remote storage unavailable')
            ->assertSee(__('backups.account.retry_available'))
            ->assertDontSee('AccessDenied')
            ->assertDontSee('/var/www');
    }

    public function test_backup_history_lists_every_backup(): void
    {
        $site = $this->backupReadySite();
        $website = $site['website'];
        $this->verifiedBackup($website, BackupType::Full, now()->subDay());
        $this->verifiedBackup($website, BackupType::Full, now()->subDays(2), BackupReason::PreUpdate);
        $failed = $website->backups()->create(['type' => BackupType::Full, 'reason' => BackupReason::Scheduled, 'status' => BackupStatus::Failed, 'error_code' => 'BACKUP_TIMED_OUT', 'requested_by' => 'scheduler']);

        $this->actingAs($website->customer->user)->get(route('account.websites.backups.index', $website))
            ->assertOk()
            ->assertSee(['Pre-update', 'Scheduled', 'Verified', 'Failed', 'The backup did not finish in time'])
            ->assertSee(__('backups.account.restore_disabled'))
            ->assertDontSee(route('account.websites.backups.restore', [$website, $failed]));
    }

    public function test_restore_confirmation_flow(): void
    {
        config(['backup.restore.enabled' => true]);
        $site = $this->backupReadySite();
        $website = $site['website'];
        $backup = $this->verifiedBackup($website, BackupType::Full, now()->subDay());
        $user = $website->customer->user;

        $this->actingAs($user)->get(route('account.websites.backups.index', $website))->assertSee(route('account.websites.backups.restore', [$website, $backup]));
        $this->actingAs($user)->get(route('account.websites.backups.restore', [$website, $backup]))
            ->assertOk()->assertSee(__('backups.restore.safety'))->assertSee(__('backups.restore.scopes.database.label'));

        // Without typing the address, nothing happens.
        $this->actingAs($user)->post(route('account.websites.backups.restore.store', [$website, $backup]), ['scope' => 'full', 'confirmation' => 'nope', 'understood' => '1'])
            ->assertSessionHas('backup_error', __('backups.refusals.CONFIRMATION_MISMATCH'));
        $this->actingAs($user)->post(route('account.websites.backups.restore.store', [$website, $backup]), ['scope' => 'full', 'confirmation' => 'company.example'])
            ->assertSessionHasErrors('understood');
        $this->assertSame(0, BackupRestore::count());

        $this->actingAs($user)->post(route('account.websites.backups.restore.store', [$website, $backup]), ['scope' => 'files', 'confirmation' => 'Company.Example', 'understood' => '1'])
            ->assertRedirect(route('account.websites.backups.index', $website));

        $restore = BackupRestore::sole();
        $this->assertSame(RestoreStatus::AwaitingSafetyBackup, $restore->status);
        $this->assertSame(BackupType::Files, $restore->scope);
        $this->assertSame('customer:'.$user->id, $restore->requested_by);
        $this->actingAs($user)->get(route('account.websites.backups.index', $website))->assertSee(__('backups.restore_statuses.awaiting_safety_backup'));
    }
}
