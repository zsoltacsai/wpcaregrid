<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupReason;
use App\Backups\BackupService;
use App\Backups\BackupStatus;
use App\Backups\BackupType;
use App\Models\Backup;
use App\Models\BackupRestore;
use App\Models\WebsiteJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesBackups;
use Tests\TestCase;

/** Tenant isolation, credentials and authorisation around backups. */
class BackupSecurityTest extends TestCase
{
    use CreatesBackups, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups();
        config(['backup.restore.enabled' => true]);
    }

    protected function tearDown(): void
    {
        $this->tearDownBackups();
        parent::tearDown();
    }

    public function test_a_connector_cannot_touch_another_websites_backup(): void
    {
        $a = $this->backupReadySite('https://a.example');
        $b = $this->backupReadySite('https://b.example');
        $backup = app(BackupService::class)->request($a['website'], BackupReason::Manual, 'test', BackupType::Files);
        $this->pollJobs($a);

        $this->putChunk($b, $backup, 'files', 0, 'EVIL')->assertNotFound()->assertJsonPath('error.code', 'BACKUP_NOT_FOUND');
        $this->withHeaders($this->connectorHeaders($b))->getJson(route('api.v1.connector.backups.status', [$backup->uuid, 'files']))->assertNotFound();
        $this->withHeaders($this->connectorHeaders($b))->postJson(route('api.v1.connector.backups.finalize', $backup->uuid), ['manifest' => []])->assertNotFound();
        $this->withHeaders($this->connectorHeaders($b))->get(route('api.v1.connector.backups.content', [$backup->uuid, 'files']))->assertNotFound();

        $this->assertSame(0, (int) $backup->objects()->sum('received_bytes'));
    }

    public function test_a_revoked_connector_cannot_upload(): void
    {
        $site = $this->backupReadySite();
        $backup = app(BackupService::class)->request($site['website'], BackupReason::Manual, 'test', BackupType::Files);
        $this->pollJobs($site);
        $site['website']->activeConnector->revoke('test');

        $this->putChunk($site, $backup, 'files', 0, 'DATA')->assertStatus(401)->assertJsonPath('error.code', 'CONNECTOR_REVOKED');
    }

    public function test_download_needs_an_active_restore_of_that_backup(): void
    {
        $site = $this->backupReadySite();
        $backup = $this->verifiedBackup($site['website']);

        $this->withHeaders($this->connectorHeaders($site))->get(route('api.v1.connector.backups.content', [$backup->uuid, 'files']))
            ->assertStatus(403)->assertJsonPath('error.code', 'DOWNLOAD_NOT_ALLOWED');
    }

    public function test_storage_credentials_never_reach_jobs_responses_or_logs(): void
    {
        $sseCustomerKey = bin2hex(str_repeat("\x05", 32));
        config([
            'backup.storage' => 'local',
            'backup.providers.s3.secret' => 'S3-SECRET-VALUE',
            'backup.providers.s3.key' => 'S3-KEY-VALUE',
            'backup.providers.s3.sse_customer_key' => $sseCustomerKey,
            'backup.providers.sftp.password' => 'SFTP-PASSWORD-VALUE',
        ]);
        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = json_encode([$message->message, $message->context]);
        });
        $site = $this->backupReadySite();
        $backup = app(BackupService::class)->request($site['website'], BackupReason::Manual, 'test');

        $responses = json_encode($this->pollJobs($site));
        $responses .= $this->uploadBackup($site, $backup, ['files' => 'F', 'database' => 'D'])->getContent();
        $responses .= json_encode(Backup::all()->toArray()).json_encode(WebsiteJob::all()->toArray()).implode('', $logged);
        $responses .= $this->actingAs($site['website']->customer->user)->get(route('account.websites.backups.index', $site['website']))->getContent();

        foreach (['S3-SECRET-VALUE', 'S3-KEY-VALUE', 'SFTP-PASSWORD-VALUE', $sseCustomerKey, $this->storageRoot] as $secret) {
            $this->assertStringNotContainsString($secret, $responses);
        }
    }

    public function test_customers_only_see_and_act_on_their_own_backups(): void
    {
        $a = $this->backupReadySite('https://a.example');
        $b = $this->backupReadySite('https://b.example');
        $backupA = $this->verifiedBackup($a['website']);
        $userB = $b['website']->customer->user;

        $this->actingAs($userB)->get(route('account.websites.backups.index', $a['website']))->assertForbidden();
        $this->actingAs($userB)->post(route('account.websites.backups.store', $a['website']))->assertForbidden();
        $this->actingAs($userB)->get(route('account.websites.backups.restore', [$a['website'], $backupA]))->assertForbidden();
        $this->actingAs($userB)->post(route('account.websites.backups.restore.store', [$a['website'], $backupA]), ['scope' => 'full', 'confirmation' => 'a.example', 'understood' => '1'])->assertForbidden();

        // B's own website with A's backup: the backup is not found under B's website.
        $this->actingAs($userB)->get(route('account.websites.backups.restore', [$b['website'], $backupA]))->assertNotFound();
        $this->actingAs($userB)->post(route('account.websites.backups.restore.store', [$b['website'], $backupA]), ['scope' => 'full', 'confirmation' => 'b.example', 'understood' => '1'])->assertNotFound();

        $this->assertSame(0, BackupRestore::count());
        $this->assertSame(1, Backup::count());
    }

    public function test_foreign_website_action_matrix_is_denied_without_side_effects(): void
    {
        Queue::fake();
        Mail::fake();

        $owner = $this->backupReadySite('https://owner.example');
        $foreign = $this->backupReadySite('https://foreign.example');
        $backup = $this->verifiedBackup($owner['website']);
        $ownerUser = $owner['website']->customer->user;
        $foreignUser = $foreign['website']->customer->user;

        // Establish the positive side of the matrix with real, valid objects.
        $this->actingAs($ownerUser)->get(route('account.websites.show', $owner['website']))->assertOk();
        $this->actingAs($ownerUser)->get(route('account.websites.backups.index', $owner['website']))->assertOk();

        $before = [
            'backups' => Backup::count(),
            'restores' => BackupRestore::count(),
            'website_jobs' => WebsiteJob::count(),
            'connectors' => DB::table('connectors')->count(),
            'admin_access_requests' => DB::table('admin_access_requests')->count(),
            'website' => $owner['website']->fresh()->only(['active_connector_id', 'customer_id', 'url']),
        ];

        $attempts = [
            fn () => $this->actingAs($foreignUser)->get(route('account.websites.show', $owner['website']))->assertForbidden(),
            fn () => $this->actingAs($foreignUser)->get(route('account.websites.backups.index', $owner['website']))->assertForbidden(),
            fn () => $this->actingAs($foreignUser)->post(route('account.websites.backups.store', $owner['website']))->assertForbidden(),
            fn () => $this->actingAs($foreignUser)->get(route('account.websites.backups.restore', [$owner['website'], $backup]))->assertForbidden(),
            fn () => $this->actingAs($foreignUser)->post(route('account.websites.backups.restore.store', [$owner['website'], $backup]), ['scope' => 'full', 'confirmation' => 'owner.example', 'understood' => '1'])->assertForbidden(),
            fn () => $this->actingAs($foreignUser)->post(route('account.websites.pairing-code', $owner['website']))->assertForbidden(),
            fn () => $this->actingAs($foreignUser)->post(route('account.websites.disconnect', $owner['website']), ['confirm' => '1'])->assertForbidden(),
            fn () => $this->actingAs($foreignUser)->post(route('account.websites.admin-access.maintenance', $owner['website']))->assertForbidden(),
        ];

        foreach ($attempts as $attempt) {
            $attempt();
        }

        $this->assertCount(8, $attempts);
        $this->assertSame($before['backups'], Backup::count());
        $this->assertSame($before['restores'], BackupRestore::count());
        $this->assertSame($before['website_jobs'], WebsiteJob::count());
        $this->assertSame($before['connectors'], DB::table('connectors')->count());
        $this->assertSame($before['admin_access_requests'], DB::table('admin_access_requests')->count());
        $this->assertSame($before['website'], $owner['website']->fresh()->only(['active_connector_id', 'customer_id', 'url']));
        Queue::assertNothingPushed();
        Mail::assertNothingOutgoing();
    }

    public function test_customers_cannot_delete_backups(): void
    {
        $site = $this->backupReadySite();
        $backup = $this->verifiedBackup($site['website']);

        // There is no customer route that deletes backups at all.
        $this->actingAs($site['website']->customer->user)->delete('/account/websites/'.$site['website']->uuid.'/backups/'.$backup->uuid)->assertNotFound();
        $this->assertEmpty(collect(app('router')->getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'backups') && in_array('DELETE', $route->methods(), true))->all());
        $this->assertSame(BackupStatus::Verified, $backup->refresh()->status);
    }
}
