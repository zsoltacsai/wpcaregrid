<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupObjectName;
use App\Backups\CustomerDownloads\BackupDownloadGrants;
use App\Backups\CustomerDownloads\BackupDownloadRefused;
use App\Backups\CustomerDownloads\DownloadRange;
use App\Backups\CustomerDownloads\DownloadResponseOutput;
use App\Backups\CustomerDownloads\PrepareBackupDownloadGrant;
use App\Backups\Storage\BackupStorage;
use App\Backups\Storage\StorageManager;
use App\Backups\Storage\StreamableBackupStorage;
use App\Backups\Storage\Verification;
use App\Billing\InternalPlan;
use App\Billing\SubscriptionStatus;
use App\Models\Backup;
use App\Models\BackupDownloadGrant;
use App\Models\BackupObject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Concerns\CreatesBackups;
use Tests\TestCase;

class CustomerBackupDownloadTest extends TestCase
{
    use CreatesBackups, RefreshDatabase;

    private FakeStreamableBackupStorage $storage;

    private StorageManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups();
        config([
            'backup.downloads.enabled' => true,
            'backup.downloads.fingerprint_block_size' => 1024 * 1024,
            'backup.downloads.max_range_size' => 1024 * 1024,
            'backup.providers.sftp' => ['driver' => 'sftp', 'host' => 'fixture.invalid', 'username' => 'fixture', 'password' => 'fixture', 'host_fingerprint' => 'SHA256:fixture', 'root' => '/fixture'],
        ]);
        $this->storage = new FakeStreamableBackupStorage;
        $this->manager = new StorageManager;
        $this->manager->set('sftp', $this->storage);
        $this->app->instance(StorageManager::class, $this->manager);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownBackups();
        parent::tearDown();
    }

    public function test_download_feature_is_disabled_by_default(): void
    {
        config(['backup.downloads.enabled' => false]);
        $site = $this->backupReadySite();
        $backup = $this->verifiedSftpBackup($site['website']);

        $this->actingAs($site['website']->customer->user)->postJson($this->grantUrl($site['website'], $backup, 'files'))->assertNotFound();
        $this->assertDatabaseCount('backup_download_grants', 0);
    }

    public function test_owner_can_prepare_and_resume_a_verified_download_with_bounded_ranges(): void
    {
        $site = $this->backupReadySite();
        $backup = $this->verifiedSftpBackup($site['website']);
        $object = $backup->objects()->where('name', 'files')->firstOrFail();
        $content = file_get_contents($this->storageRoot.'/'.$backup->objectKey(BackupObjectName::Files));
        $this->storage->objects[$backup->objectKey(BackupObjectName::Files)] = $content;
        $this->bindObject($backup, $object, $content);
        Log::spy();

        $response = $this->actingAs($site['website']->customer->user)->postJson($this->grantUrl($site['website'], $backup, 'files'))->assertAccepted();
        $grant = BackupDownloadGrant::sole();
        $this->assertSame(BackupDownloadGrant::PREPARING, $response->json('status'));
        (new PrepareBackupDownloadGrant($grant->id))->handle(app(BackupDownloadGrants::class), $this->manager);
        $grant->refresh();
        $this->assertSame(BackupDownloadGrant::READY, $grant->status);
        $this->assertNotSame($content, $grant->getRawOriginal('block_hashes'));
        Log::shouldHaveReceived('info')->with('Customer backup download event.', \Mockery::on(fn (array $context) => ($context['event'] ?? null) === 'grant_ready'
            && array_key_exists('object_key', $context) === false && array_key_exists('sha256', $context) === false
            && array_key_exists('url', $context) === false))->once();

        $first = $this->actingAs($site['website']->customer->user)->withHeader('Range', 'bytes=0-4')
            ->getJson(route('account.backup-downloads.content', $grant));
        $first->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-4/'.strlen($content));
        $this->assertSame(substr($content, 0, 5), $first->streamedContent());

        $second = $this->withHeader('Range', 'bytes=5-9')->get(route('account.backup-downloads.content', $grant));
        $second->assertStatus(206)->assertHeader('Content-Range', 'bytes 5-9/'.strlen($content));
        $this->assertSame(substr($content, 5, 5), $second->streamedContent());
        $this->assertSame(2, $grant->fresh()->range_responses);
        $this->assertSame(10, $grant->fresh()->bytes_served);
        $this->assertLessThanOrEqual(strlen($content), $this->storage->largestRead);
    }

    public function test_foreign_tenant_cannot_create_or_use_another_customers_grant(): void
    {
        $a = $this->backupReadySite('https://first.example');
        $backup = $this->verifiedSftpBackup($a['website']);
        $object = $backup->objects()->where('name', 'files')->firstOrFail();
        $content = 'synthetic bytes';
        $this->bindObject($backup, $object, $content);
        $grant = app(BackupDownloadGrants::class)->create($a['website']->customer->user, $a['website'], $backup, $object);

        $b = $this->backupReadySite('https://second.example');
        $this->actingAs($b['website']->customer->user)->postJson($this->grantUrl($b['website'], $backup, 'files'))->assertNotFound();
        $this->actingAs($b['website']->customer->user)->getJson(route('account.backup-downloads.status', $grant))->assertNotFound();
        $this->actingAs($b['website']->customer->user)->deleteJson(route('account.backup-downloads.revoke', $grant))->assertNotFound();
    }

    public function test_repeated_create_reuses_one_live_preparation_and_revocation_invalidates_it(): void
    {
        $site = $this->backupReadySite();
        $backup = $this->verifiedSftpBackup($site['website']);
        $object = $backup->objects()->where('name', 'files')->firstOrFail();
        $user = $site['website']->customer->user;
        $first = app(BackupDownloadGrants::class)->create($user, $site['website'], $backup, $object);
        $second = app(BackupDownloadGrants::class)->create($user, $site['website'], $backup, $object);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('backup_download_grants', 1);

        app(BackupDownloadGrants::class)->revoke($user, $first);
        $this->assertSame(BackupDownloadGrant::REVOKED, $first->fresh()->status);
        $this->assertNull($first->fresh()->block_hashes);
    }

    public function test_changed_object_bytes_fail_closed_before_any_response_body(): void
    {
        $site = $this->backupReadySite();
        $backup = $this->verifiedSftpBackup($site['website']);
        $object = $backup->objects()->where('name', 'files')->firstOrFail();
        $content = 'verified object content';
        $this->bindObject($backup, $object, $content);
        $user = $site['website']->customer->user;
        $grant = app(BackupDownloadGrants::class)->create($user, $site['website'], $backup, $object);
        (new PrepareBackupDownloadGrant($grant->id))->handle(app(BackupDownloadGrants::class), $this->manager);
        $this->storage->objects[$grant->object_key] = 'tampered object content';

        $response = $this->actingAs($user)->withHeader('Range', 'bytes=0-5')->getJson(route('account.backup-downloads.content', $grant));
        $response->assertStatus(503)->assertExactJson(['code' => 'OBJECT_INTEGRITY_MISMATCH']);
        $this->assertNotInstanceOf(StreamedResponse::class, $response->baseResponse);
    }

    public function test_deletion_started_during_range_read_fails_closed_before_response_body(): void
    {
        $site = $this->backupReadySite();
        $backup = $this->verifiedSftpBackup($site['website']);
        $object = $backup->objects()->where('name', 'files')->firstOrFail();
        $content = 'verified bytes before deletion begins';
        $this->bindObject($backup, $object, $content);
        $user = $site['website']->customer->user;
        $grant = app(BackupDownloadGrants::class)->create($user, $site['website'], $backup, $object);
        (new PrepareBackupDownloadGrant($grant->id))->handle(app(BackupDownloadGrants::class), $this->manager);
        $this->storage->afterRangeRead = fn () => $backup->forceFill(['deletion_state' => 'reconciliation_required'])->save();

        $response = $this->actingAs($user)->withHeader('Range', 'bytes=0-5')
            ->getJson(route('account.backup-downloads.content', $grant));

        $response->assertGone()->assertExactJson(['code' => 'BACKUP_UNAVAILABLE']);
        $this->assertNotInstanceOf(StreamedResponse::class, $response->baseResponse);
    }

    public function test_revocation_between_response_creation_and_stream_emission_sends_no_bytes(): void
    {
        [$user, $grant, $response] = $this->preparedStreamResponse('revocation race bytes');
        app(BackupDownloadGrants::class)->revoke($user, $grant);

        ob_start();
        $response->baseResponse->sendContent();
        $body = (string) ob_get_clean();

        $this->assertSame('', $body);
        $this->assertSame(0, $grant->fresh()->bytes_served);
        $this->assertNotNull($grant->fresh()->last_response_interrupted_at);
        $this->assertNull($grant->fresh()->last_response_completed_at);
    }

    public function test_backup_deletion_between_response_creation_and_emission_sends_no_bytes(): void
    {
        [, $grant, $response] = $this->preparedStreamResponse('deletion race bytes');
        $grant->backup->forceFill(['deletion_state' => 'reconciliation_required'])->save();

        ob_start();
        $response->baseResponse->sendContent();
        $body = (string) ob_get_clean();

        $this->assertSame('', $body);
        $this->assertSame(0, $grant->fresh()->bytes_served);
        $this->assertNull($grant->fresh()->last_response_completed_at);
    }

    public function test_service_termination_between_response_creation_and_emission_sends_no_bytes(): void
    {
        [, $grant, $response] = $this->preparedStreamResponse('service termination race bytes');
        $grant->website->subscription->forceFill(['status' => SubscriptionStatus::Canceled])->save();

        ob_start();
        $response->baseResponse->sendContent();
        $body = (string) ob_get_clean();

        $this->assertSame('', $body);
        $this->assertSame(0, $grant->fresh()->bytes_served);
        $this->assertNull($grant->fresh()->last_response_completed_at);
    }

    public function test_ready_grant_denies_new_range_after_website_service_ends(): void
    {
        [$user, $grant] = $this->preparedGrant('ready grant service termination');
        $this->assertSame(BackupDownloadGrant::READY, $grant->fresh()->status);
        $grant->website->subscription->forceFill(['status' => SubscriptionStatus::Canceled])->save();

        $response = $this->actingAs($user)->withHeader('Range', 'bytes=0-5')
            ->get(route('account.backup-downloads.content', $grant));

        $response->assertGone()->assertExactJson(['code' => 'SERVICE_UNAVAILABLE']);
        $this->assertNotInstanceOf(StreamedResponse::class, $response->baseResponse);
        $this->assertSame(0, $grant->fresh()->bytes_served);
        $this->assertSame(0, $grant->fresh()->range_responses);
    }

    public function test_client_disconnect_counts_submitted_bytes_and_records_incomplete_206(): void
    {
        Log::spy();
        $output = new FakeDownloadResponseOutput;
        $output->abortOnFlush = 1;
        [, $grant, $response] = $this->preparedStreamResponse(str_repeat('d', 300 * 1024), $output);

        $body = $response->streamedContent();
        $expectedLength = 300 * 1024;
        $submitted = 256 * 1024;

        $this->assertSame((string) $expectedLength, $response->headers->get('Content-Length'));
        $this->assertSame($submitted, strlen($body));
        $this->assertLessThan((int) $response->headers->get('Content-Length'), strlen($body));
        $this->assertSame($submitted, $grant->fresh()->bytes_served);
        $this->assertNull($grant->fresh()->last_response_completed_at);
        $this->assertNotNull($grant->fresh()->last_response_interrupted_at);
        Log::shouldHaveReceived('info')->with('Customer backup download event.', \Mockery::on(
            fn (array $context) => ($context['event'] ?? null) === 'range_interrupted'
                && ($context['code'] ?? null) === 'CLIENT_DISCONNECTED'
                && ($context['bytes'] ?? null) === $submitted,
        ))->once();
    }

    public function test_partial_output_records_stream_incomplete_and_only_attempted_bytes(): void
    {
        Log::spy();
        $output = new FakeDownloadResponseOutput;
        $output->partialWriteBytes = 12_345;
        [, $grant, $response] = $this->preparedStreamResponse(str_repeat('i', 300 * 1024), $output);

        $body = $response->streamedContent();

        $this->assertSame(12_345, strlen($body));
        $this->assertSame(12_345, $grant->fresh()->bytes_served);
        $this->assertNotNull($grant->fresh()->last_response_interrupted_at);
        $this->assertNull($grant->fresh()->last_response_completed_at);
        Log::shouldHaveReceived('info')->with('Customer backup download event.', \Mockery::on(
            fn (array $context) => ($context['event'] ?? null) === 'range_interrupted'
                && ($context['code'] ?? null) === 'STREAM_INCOMPLETE'
                && ($context['bytes'] ?? null) === 12_345,
        ))->once();
    }

    public function test_revocation_during_controller_stream_stops_before_the_next_chunk(): void
    {
        Log::spy();
        $grant = null;
        $user = null;
        $output = new FakeDownloadResponseOutput;
        $output->afterFlush = function () use (&$user, &$grant): void {
            if ($user instanceof User && $grant instanceof BackupDownloadGrant && $grant->fresh()->status === BackupDownloadGrant::READY) {
                app(BackupDownloadGrants::class)->revoke($user, $grant);
            }
        };
        [$user, $grant, $response] = $this->preparedStreamResponse(str_repeat('r', 300 * 1024), $output);

        $body = $response->streamedContent();

        $this->assertSame(256 * 1024, strlen($body));
        $this->assertSame(256 * 1024, $grant->fresh()->bytes_served);
        $this->assertSame(BackupDownloadGrant::REVOKED, $grant->fresh()->status);
        $this->assertNull($grant->fresh()->last_response_completed_at);
        Log::shouldHaveReceived('info')->with('Customer backup download event.', \Mockery::on(
            fn (array $context) => ($context['event'] ?? null) === 'range_interrupted'
                && ($context['code'] ?? null) === 'GRANT_REVOKED'
                && ($context['bytes'] ?? null) === 256 * 1024,
        ))->once();
    }

    public function test_user_role_change_before_stream_emission_revokes_download_access(): void
    {
        [$user, $grant, $response] = $this->preparedStreamResponse('role change race bytes');
        $user->forceFill(['role' => User::ROLE_OPERATOR])->save();

        ob_start();
        $response->baseResponse->sendContent();
        $body = (string) ob_get_clean();

        $this->assertSame('', $body);
        $this->assertSame(0, $grant->fresh()->bytes_served);
        $this->assertNull($grant->fresh()->last_response_completed_at);
    }

    public function test_stream_rechecks_access_between_bounded_chunks(): void
    {
        [$user, $grant, $response] = $this->preparedStreamResponse(str_repeat('c', 300 * 1024));
        $chunks = app(BackupDownloadGrants::class)->responseChunks(
            $user,
            $grant,
            new DownloadRange(0, 300 * 1024, 300 * 1024, str_repeat('c', 300 * 1024)),
        );

        $chunks->rewind();
        $this->assertSame(256 * 1024, strlen($chunks->current()));
        app(BackupDownloadGrants::class)->revoke($user, $grant);
        try {
            $chunks->next();
            $this->fail('A revoked grant must not yield a subsequent response chunk.');
        } catch (BackupDownloadRefused $exception) {
            $this->assertSame('GRANT_REVOKED', $exception->errorCode);
        }
    }

    public function test_stream_stops_at_chunk_boundary_when_website_service_ends(): void
    {
        [$user, $grant] = $this->preparedStreamResponse(str_repeat('s', 300 * 1024));
        $chunks = app(BackupDownloadGrants::class)->responseChunks(
            $user,
            $grant,
            new DownloadRange(0, 300 * 1024, 300 * 1024, str_repeat('s', 300 * 1024)),
        );

        $chunks->rewind();
        $this->assertSame(256 * 1024, strlen($chunks->current()));
        $grant->website->subscription->forceFill(['status' => SubscriptionStatus::Canceled])->save();
        try {
            $chunks->next();
            $this->fail('A terminated website entitlement must stop the next bounded chunk.');
        } catch (BackupDownloadRefused $exception) {
            $this->assertSame('SERVICE_UNAVAILABLE', $exception->errorCode);
        }
    }

    public function test_entitlement_is_site_specific_and_fails_closed_at_effective_termination(): void
    {
        $site = $this->backupReadySite('https://entitlement.example');
        $backup = $this->verifiedSftpBackup($site['website']);
        $object = $backup->objects()->where('name', 'files')->firstOrFail();
        $user = $site['website']->customer->user;

        foreach ([SubscriptionStatus::Active, SubscriptionStatus::Trialing] as $status) {
            $site['website']->subscription->forceFill(['status' => $status, 'cancel_at_period_end' => false])->save();
            app(BackupDownloadGrants::class)->create($user, $site['website'], $backup, $object);
        }

        $site['website']->subscription->forceFill(['status' => SubscriptionStatus::Active, 'cancel_at_period_end' => true])->save();
        app(BackupDownloadGrants::class)->create($user, $site['website'], $backup, $object);

        $site['website']->subscription->forceFill(['status' => SubscriptionStatus::Canceled, 'cancel_at_period_end' => false])->save();
        $this->expectException(BackupDownloadRefused::class);
        $this->expectExceptionMessage('SERVICE_UNAVAILABLE');
        app(BackupDownloadGrants::class)->create($user, $site['website'], $backup, $object);
    }

    public function test_expired_internal_plan_inactive_customer_and_missing_site_subscription_are_denied(): void
    {
        $site = $this->backupReadySite('https://service-denied.example');
        $backup = $this->verifiedSftpBackup($site['website']);
        $object = $backup->objects()->where('name', 'files')->firstOrFail();
        $user = $site['website']->customer->user;
        $subscription = $site['website']->subscription;

        $subscription->forceFill([
            'internal_plan' => InternalPlan::Probono,
            'status' => SubscriptionStatus::Active,
            'internal_ends_at' => now(),
        ])->save();
        try {
            app(BackupDownloadGrants::class)->create($user, $site['website'], $backup, $object);
            $this->fail('An internal plan at its exclusive end timestamp has no entitlement.');
        } catch (BackupDownloadRefused $exception) {
            $this->assertSame('SERVICE_UNAVAILABLE', $exception->errorCode);
        }

        $subscription->forceFill(['internal_plan' => null, 'status' => SubscriptionStatus::Active, 'internal_ends_at' => null])->save();
        $site['website']->customer->forceFill(['active' => false])->save();
        try {
            app(BackupDownloadGrants::class)->create($user, $site['website'], $backup, $object);
            $this->fail('An inactive customer has no download entitlement.');
        } catch (BackupDownloadRefused $exception) {
            $this->assertSame('SERVICE_UNAVAILABLE', $exception->errorCode);
        }

        $site['website']->customer->forceFill(['active' => true])->save();
        $site['website']->forceFill(['subscription_id' => null])->save();
        try {
            app(BackupDownloadGrants::class)->create($user, $site['website'], $backup, $object);
            $this->fail('A website without its own subscription has no entitlement.');
        } catch (BackupDownloadRefused $exception) {
            $this->assertSame('SERVICE_UNAVAILABLE', $exception->errorCode);
        }
    }

    public function test_terminated_entitlement_fails_existing_range_read_and_preparation(): void
    {
        $site = $this->backupReadySite('https://termination.example');
        $backup = $this->verifiedSftpBackup($site['website']);
        $object = $backup->objects()->where('name', 'files')->firstOrFail();
        $content = 'service ended before preparation';
        $this->bindObject($backup, $object, $content);
        $user = $site['website']->customer->user;
        $grant = app(BackupDownloadGrants::class)->create($user, $site['website'], $backup, $object);
        $site['website']->subscription->forceFill(['status' => SubscriptionStatus::Canceled])->save();

        (new PrepareBackupDownloadGrant($grant->id))->handle(app(BackupDownloadGrants::class), $this->manager);
        $this->assertSame(BackupDownloadGrant::FAILED, $grant->fresh()->status);
        $this->assertSame('SERVICE_UNAVAILABLE', $grant->fresh()->failure_code);
        $this->actingAs($user)->withHeader('Range', 'bytes=0-4')
            ->getJson(route('account.backup-downloads.content', $grant))->assertGone();
    }

    public function test_entitlement_loss_during_preparation_prevents_ready_transition(): void
    {
        $site = $this->backupReadySite('https://ready-transition.example');
        $backup = $this->verifiedSftpBackup($site['website']);
        $object = $backup->objects()->where('name', 'files')->firstOrFail();
        $content = 'service ends while object is read';
        $this->bindObject($backup, $object, $content);
        $user = $site['website']->customer->user;
        $grant = app(BackupDownloadGrants::class)->create($user, $site['website'], $backup, $object);
        $this->storage->afterStream = fn () => $site['website']->subscription
            ->forceFill(['status' => SubscriptionStatus::Canceled])->save();

        (new PrepareBackupDownloadGrant($grant->id))->handle(app(BackupDownloadGrants::class), $this->manager);

        $this->assertSame(BackupDownloadGrant::FAILED, $grant->fresh()->status);
        $this->assertSame('PREPARATION_FAILED', $grant->fresh()->failure_code);
        $this->assertNull($grant->fresh()->block_hashes);
    }

    public function test_range_is_single_bounded_and_invalid_ranges_are_rejected(): void
    {
        $site = $this->backupReadySite();
        $backup = $this->verifiedSftpBackup($site['website']);
        $object = $backup->objects()->where('name', 'files')->firstOrFail();
        $content = str_repeat('x', 2 * 1024 * 1024);
        $this->bindObject($backup, $object, $content);
        $user = $site['website']->customer->user;
        $grant = app(BackupDownloadGrants::class)->create($user, $site['website'], $backup, $object);
        (new PrepareBackupDownloadGrant($grant->id))->handle(app(BackupDownloadGrants::class), $this->manager);

        $this->actingAs($user)->getJson(route('account.backup-downloads.content', $grant))->assertStatus(416);
        $this->withHeader('Range', 'bytes=0-1,4-5')->getJson(route('account.backup-downloads.content', $grant))->assertStatus(416);
        $this->withHeader('Range', 'bytes=0-1048576')->getJson(route('account.backup-downloads.content', $grant))->assertStatus(416);
        $this->withHeader('Range', 'bytes=600000-')->getJson(route('account.backup-downloads.content', $grant))->assertStatus(416);
    }

    public function test_storage_failure_is_sanitized_and_never_marks_a_grant_ready(): void
    {
        $site = $this->backupReadySite();
        $backup = $this->verifiedSftpBackup($site['website']);
        $object = $backup->objects()->where('name', 'files')->firstOrFail();
        $user = $site['website']->customer->user;
        $grant = app(BackupDownloadGrants::class)->create($user, $site['website'], $backup, $object);
        $this->storage->failWithSensitiveProviderMessage = true;

        (new PrepareBackupDownloadGrant($grant->id))->handle(app(BackupDownloadGrants::class), $this->manager);

        $grant->refresh();
        $this->assertSame(BackupDownloadGrant::FAILED, $grant->status);
        $this->assertSame('STORAGE_UNAVAILABLE', $grant->failure_code);
        $this->assertNull($grant->block_hashes);
        $this->assertStringNotContainsString('private-fixture-secret', (string) $grant->failure_code);
    }

    public function test_expired_and_deletion_pending_backups_cannot_create_grants(): void
    {
        $site = $this->backupReadySite();
        $expired = $this->verifiedSftpBackup($site['website']);
        $expired->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->actingAs($site['website']->customer->user)
            ->postJson($this->grantUrl($site['website'], $expired, 'files'))->assertGone();

        $deleting = $this->verifiedSftpBackup($site['website'], at: now()->subMinute());
        $deleting->forceFill(['deletion_state' => 'reconciliation_required'])->save();
        $this->actingAs($site['website']->customer->user)
            ->postJson($this->grantUrl($site['website'], $deleting, 'files'))->assertGone();
        $this->assertDatabaseCount('backup_download_grants', 0);
    }

    public function test_large_object_preparation_and_range_reads_are_bounded(): void
    {
        $site = $this->backupReadySite();
        $backup = $this->verifiedSftpBackup($site['website']);
        $object = $backup->objects()->where('name', 'files')->firstOrFail();
        $content = str_repeat('z', 10 * 1024 * 1024 + 123);
        $this->bindObject($backup, $object, $content);
        $user = $site['website']->customer->user;
        $grant = app(BackupDownloadGrants::class)->create($user, $site['website'], $backup, $object);
        (new PrepareBackupDownloadGrant($grant->id))->handle(app(BackupDownloadGrants::class), $this->manager);

        $this->actingAs($user)->withHeader('Range', 'bytes=100000-1099999')->get(route('account.backup-downloads.content', $grant))->assertStatus(206);
        $this->assertLessThanOrEqual(1024 * 1024 + 1024 * 1024, $this->storage->largestRead);
        $this->assertGreaterThanOrEqual(160, $this->storage->largestConsumerChunk);
        $this->assertLessThanOrEqual(64 * 1024, $this->storage->largestConsumerChunk);
    }

    public function test_range_crossing_a_fingerprint_boundary_reads_both_full_edge_blocks(): void
    {
        $blockSize = 1024 * 1024;
        $content = str_repeat('b', 2 * $blockSize);
        [$user, $grant] = $this->preparedGrant($content);
        $this->storage->largestRead = 0;

        $response = $this->actingAs($user)->withHeader('Range', 'bytes='.(($blockSize - 1)).'-'.$blockSize)
            ->get(route('account.backup-downloads.content', $grant));

        $response->assertStatus(206)->assertHeader('Content-Length', '2');
        $this->assertSame(substr($content, $blockSize - 1, 2), $response->streamedContent());
        $this->assertSame(2 * $blockSize, $this->storage->largestRead);
    }

    private function bindObject(Backup $backup, BackupObject $object, string $content): void
    {
        $this->storage->objects[$backup->objectKey(BackupObjectName::from($object->name))] = $content;
        $object->forceFill(['size' => strlen($content), 'sha256' => hash('sha256', $content), 'remote_size' => strlen($content), 'status' => BackupObject::VERIFIED, 'verified_at' => now()])->save();
    }

    private function verifiedSftpBackup($website, ?\DateTimeInterface $at = null): Backup
    {
        $backup = $this->verifiedBackup($website, at: $at);
        $backup->forceFill(['storage_provider' => 'sftp'])->save();

        return $backup->refresh();
    }

    /** @return array{0: User, 1: BackupDownloadGrant, 2: TestResponse} */
    private function preparedStreamResponse(string $content, ?DownloadResponseOutput $output = null): array
    {
        [$user, $grant] = $this->preparedGrant($content);
        if ($output !== null) {
            $this->app->instance(DownloadResponseOutput::class, $output);
        }
        $response = $this->actingAs($user)->withHeader('Range', 'bytes=0-'.(strlen($content) - 1))
            ->get(route('account.backup-downloads.content', $grant));
        $response->assertStatus(206);

        return [$user, $grant, $response];
    }

    /** @return array{0: User, 1: BackupDownloadGrant} */
    private function preparedGrant(string $content): array
    {
        $site = $this->backupReadySite();
        $backup = $this->verifiedSftpBackup($site['website']);
        $object = $backup->objects()->where('name', 'files')->firstOrFail();
        $this->bindObject($backup, $object, $content);
        $user = $site['website']->customer->user;
        $grant = app(BackupDownloadGrants::class)->create($user, $site['website'], $backup, $object);
        (new PrepareBackupDownloadGrant($grant->id))->handle(app(BackupDownloadGrants::class), $this->manager);
        $grant->refresh();

        return [$user, $grant];
    }

    private function grantUrl($website, Backup $backup, string $object): string
    {
        return route('account.websites.backups.download-grants.store', [$website, $backup, $object]);
    }
}

final class FakeStreamableBackupStorage implements BackupStorage, StreamableBackupStorage
{
    /** @var array<string, string> */
    public array $objects = [];

    public int $largestRead = 0;

    public int $largestConsumerChunk = 0;

    public bool $failWithSensitiveProviderMessage = false;

    public ?\Closure $afterRangeRead = null;

    public ?\Closure $afterStream = null;

    public function name(): string
    {
        return 'sftp';
    }

    public function put(string $key, $stream, int $size, string $sha256): void {}

    public function verify(string $key, int $size, string $sha256): Verification
    {
        return Verification::passed(Verification::READBACK, $size);
    }

    public function readRange(string $key, int $offset, int $length): string
    {
        $this->largestRead = max($this->largestRead, $length);
        $bytes = substr($this->objects[$key] ?? '', $offset, $length);
        if ($this->afterRangeRead !== null) {
            ($this->afterRangeRead)();
        }

        return $bytes;
    }

    public function streamObject(string $key, callable $consume): int
    {
        if ($this->failWithSensitiveProviderMessage) {
            throw new \RuntimeException('private-fixture-secret');
        }
        $data = $this->objects[$key] ?? '';
        $bytes = 0;
        foreach (str_split($data, 16 * 1024) as $chunk) {
            $this->largestConsumerChunk = max($this->largestConsumerChunk, strlen($chunk));
            $consume($chunk);
            $bytes += strlen($chunk);
        }
        if ($this->afterStream !== null) {
            ($this->afterStream)();
        }

        return $bytes;
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key]);
    }
}

final class FakeDownloadResponseOutput extends DownloadResponseOutput
{
    public ?int $abortOnFlush = null;

    public ?int $partialWriteBytes = null;

    public int $flushes = 0;

    public ?\Closure $afterFlush = null;

    public function write(string $chunk): int
    {
        $attempted = $this->partialWriteBytes === null ? strlen($chunk) : min(strlen($chunk), $this->partialWriteBytes);
        echo substr($chunk, 0, $attempted);

        return $attempted;
    }

    public function flushAndCheckAbort(): bool
    {
        $this->flushes++;
        if ($this->afterFlush !== null) {
            ($this->afterFlush)();
        }

        return $this->abortOnFlush === $this->flushes;
    }
}
