<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupReason;
use App\Backups\BackupService;
use App\Backups\BackupStatus;
use App\Backups\BackupType;
use App\Backups\RestoreStatus;
use App\Compatibility\CapabilityReadiness;
use App\Models\Backup;
use App\Models\BackupRestore;
use App\Models\WebsiteJob;
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

    public function test_unreviewed_isolation_provider_hides_customer_restore_and_rejects_direct_post_without_side_effects(): void
    {
        config(['compatibility.restore_isolation_provider_ids' => []]);
        $site = $this->backupReadySite();
        $website = $site['website'];
        $backup = $this->verifiedBackup($website);
        $user = $website->customer->user;

        $this->actingAs($user)->get(route('account.websites.backups.index', $website))
            ->assertOk()
            ->assertSee(__('backups.account.restore_isolation_unavailable'))
            ->assertDontSee(route('account.websites.backups.restore', [$website, $backup]));

        $restoreUrl = route('account.websites.backups.restore.store', [$website, $backup]);
        $this->actingAs($user)->get(route('account.websites.backups.restore', [$website, $backup]))
            ->assertOk()
            ->assertSee(__('backups.refusals.RESTORE_ISOLATION_UNAVAILABLE'))
            ->assertDontSee('action="'.$restoreUrl.'"', false);

        $backupCount = Backup::count();
        $this->actingAs($user)->post($restoreUrl, ['scope' => 'full', 'confirmation' => 'company.example', 'understood' => '1'])
            ->assertSessionHas('backup_error', __('backups.refusals.RESTORE_ISOLATION_UNAVAILABLE'));

        $this->assertSame($backupCount, Backup::count(), 'The refusal must occur before the safety backup is created.');
        $this->assertSame(0, BackupRestore::count());
        $this->assertSame(0, WebsiteJob::where('type', 'RESTORE')->count());
        $this->assertSame(0, WebsiteJob::whereIn('type', ['BACKUP_FILES', 'BACKUP_DATABASE', 'BACKUP_FULL'])->count());
    }

    public function test_provider_readiness_change_after_customer_form_render_is_rechecked_before_request(): void
    {
        config([
            'backup.restore.enabled' => true,
            'compatibility.restore_isolation_provider_ids' => ['tests.fake-host-isolation'],
        ]);
        $site = $this->backupReadySite();
        $website = $site['website'];
        $backup = $this->verifiedBackup($website);
        $user = $website->customer->user;
        $restoreUrl = route('account.websites.backups.restore.store', [$website, $backup]);

        $this->actingAs($user)->get(route('account.websites.backups.restore', [$website, $backup]))
            ->assertOk()
            ->assertSee($restoreUrl, false);

        config(['compatibility.restore_isolation_provider_ids' => []]);
        $backupCount = Backup::count();
        $this->actingAs($user)->post($restoreUrl, ['scope' => 'full', 'confirmation' => 'company.example', 'understood' => '1'])
            ->assertSessionHas('backup_error', __('backups.refusals.RESTORE_ISOLATION_UNAVAILABLE'));

        $this->assertSame($backupCount, Backup::count());
        $this->assertSame(0, BackupRestore::count());
        $this->assertSame(0, WebsiteJob::where('type', 'RESTORE')->count());
    }

    public function test_customer_can_still_request_a_backup_when_restore_isolation_is_unavailable(): void
    {
        config(['compatibility.restore_isolation_provider_ids' => []]);
        $site = $this->backupReadySite();
        $website = $site['website'];

        $this->actingAs($website->customer->user)
            ->post(route('account.websites.backups.store', $website))
            ->assertSessionHas('status', __('backups.account.queued'));

        $this->assertSame(1, Backup::count());
        $this->assertSame(BackupReason::Manual, Backup::sole()->reason);
        $this->assertSame(0, BackupRestore::count());
    }

    public function test_restore_feature_switch_still_refuses_when_provider_is_supported(): void
    {
        config(['compatibility.restore_isolation_provider_ids' => ['tests.fake-host-isolation']]);
        $site = $this->backupReadySite();
        $website = $site['website'];
        $backup = $this->verifiedBackup($website);

        $this->assertTrue(app(CapabilityReadiness::class)->restoreIsolationSupported($website));

        $this->actingAs($website->customer->user)
            ->post(route('account.websites.backups.restore.store', [$website, $backup]), ['scope' => 'full', 'confirmation' => 'company.example', 'understood' => '1'])
            ->assertSessionHas('backup_error', __('backups.refusals.RESTORE_NOT_AVAILABLE'));

        $this->assertSame(1, Backup::count());
        $this->assertSame(0, BackupRestore::count());
        $this->assertSame(0, WebsiteJob::whereIn('type', ['RESTORE', 'BACKUP_FULL'])->count());
    }
}
