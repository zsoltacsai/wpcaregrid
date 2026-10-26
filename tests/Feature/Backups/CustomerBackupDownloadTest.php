<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupObjectName;
use App\Backups\CustomerDownloads\BackupDownloadGrants;
use App\Backups\CustomerDownloads\PrepareBackupDownloadGrant;
use App\Backups\Storage\BackupStorage;
use App\Backups\Storage\StreamableBackupStorage;
use App\Backups\Storage\Verification;
use App\Models\Backup;
use App\Models\BackupDownloadGrant;
use App\Models\BackupObject;
use App\Backups\Storage\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
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
        $this->assertSame(substr($content, 0, 5), $first->getContent());

        $second = $this->withHeader('Range', 'bytes=5-9')->get(route('account.backup-downloads.content', $grant));
        $second->assertStatus(206)->assertHeader('Content-Range', 'bytes 5-9/'.strlen($content));
        $this->assertSame(substr($content, 5, 5), $second->getContent());
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
        $this->assertSame('', $response->getContent());
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

        $response->assertGone()->assertExactJson(['code' => 'OBJECT_UNAVAILABLE']);
        $this->assertSame('', $response->getContent());
    }

    public function test_range_is_single_bounded_and_invalid_ranges_are_rejected(): void
    {
        $site = $this->backupReadySite();
        $backup = $this->verifiedSftpBackup($site['website']);
        $object = $backup->objects()->where('name', 'files')->firstOrFail();
        $content = str_repeat('x', 512 * 1024);
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

    public function name(): string
    {
        return 'sftp';
    }

    public function put(string $key, $stream, int $size, string $sha256): void
    {}

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
        return $bytes;
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key]);
    }
}
