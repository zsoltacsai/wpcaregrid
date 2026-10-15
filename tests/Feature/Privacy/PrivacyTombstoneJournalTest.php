<?php

namespace Tests\Feature\Privacy;

use App\Platform\DisasterRecovery\PlatformBackupCryptography;
use App\Privacy\ObjectStoragePrivacyTombstoneJournal;
use App\Privacy\PrivacyTombstoneObjectStore;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PrivacyTombstoneJournalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('platform_backups');
        config([
            'platform.deployment_environment' => 'testing',
            'platform_backup.prefix' => 'platform-database',
            'platform_backup.encryption.current_key_id' => 'test-key',
            'platform_backup.encryption.keys.test-key' => base64_encode(random_bytes(32)),
        ]);
    }

    public function test_journal_append_is_encrypted_idempotent_ordered_and_integrity_checked(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00.123 UTC');
        $journal = $this->journal();
        $event = [
            'event_id' => hash('sha256', 'synthetic-closure-event'),
            'subject_uuid' => (string) Str::uuid(),
            'tenant_uuid' => null,
            'scope' => 'customer_account',
            'action' => 'account_access_restricted',
            'request_uuid' => (string) Str::uuid(),
            'effective_at' => now('UTC')->toIso8601String(),
        ];
        $event['tenant_uuid'] = $event['subject_uuid'];

        $first = $journal->append($event);
        $second = $journal->append($event);
        $path = 'platform-database/privacy-tombstones/testing/events/'.$event['event_id'].'.json.enc';
        $stored = Storage::disk('platform_backups')->get($path);

        $this->assertSame($first['sequence'], $second['sequence']);
        $this->assertSame($first['event_id'], $second['event_id']);
        $this->assertStringNotContainsString($event['subject_uuid'], $stored);
        $this->assertSame($first['sequence'], $journal->watermark());
        $this->assertCount(0, iterator_to_array((function () use ($journal, $first) {
            yield from $journal->eventsAfter($first['sequence']);
        })()));
        $this->assertCount(1, iterator_to_array((function () use ($journal) {
            yield from $journal->eventsAfter(null);
        })()));
        $this->assertSame('ready', $journal->readiness()['state']);

        $nextEvent = $event;
        $nextEvent['event_id'] = hash('sha256', 'synthetic-second-closure-event');
        $nextEvent['request_uuid'] = (string) Str::uuid();
        $next = $journal->append($nextEvent);
        Carbon::setTestNow();
        $this->assertGreaterThan($first['sequence'], $next['sequence']);
        $this->assertSame($next['sequence'], $journal->watermark());

        Storage::disk('platform_backups')->put($path, '{broken');
        $this->assertSame('blocked', $journal->readiness()['state']);
        $this->assertSame('privacy_journal_integrity_failed', $journal->readiness()['reason']);
        $this->expectException(RuntimeException::class);
        iterator_to_array((function () use ($journal) {
            yield from $journal->eventsAfter(null);
        })());
    }

    public function test_idempotency_key_cannot_be_reused_for_a_different_privacy_action(): void
    {
        $journal = $this->journal();
        $event = [
            'event_id' => hash('sha256', 'event-conflict'),
            'subject_uuid' => (string) Str::uuid(),
            'tenant_uuid' => null,
            'scope' => 'customer_account',
            'action' => 'account_access_restricted',
            'request_uuid' => (string) Str::uuid(),
            'effective_at' => now('UTC')->toIso8601String(),
        ];
        $event['tenant_uuid'] = $event['subject_uuid'];
        $journal->append($event);

        $event['action'] = 'privacy_export_revoked';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('privacy_journal_idempotency_conflict');
        $journal->append($event);
    }

    private function journal(): ObjectStoragePrivacyTombstoneJournal
    {
        $storage = new class implements PrivacyTombstoneObjectStore
        {
            public function configured(): bool
            {
                return true;
            }

            public function driver(): string
            {
                return 's3';
            }

            public function disk(): Filesystem
            {
                return Storage::disk('platform_backups');
            }
        };

        return new ObjectStoragePrivacyTombstoneJournal($storage, app(PlatformBackupCryptography::class));
    }
}
