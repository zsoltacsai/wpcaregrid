<?php

namespace Tests\Feature\Privacy;

use App\Models\PrivacyTombstoneReplaySession;
use App\Privacy\PrivacyRestoreSafety;
use App\Privacy\PrivacyTombstoneJournal;
use App\Privacy\UnavailablePrivacyTombstoneJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrivacyRestoreSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_independent_tombstone_journal_blocks_recovery_readiness(): void
    {
        $this->app->instance(PrivacyTombstoneJournal::class, new UnavailablePrivacyTombstoneJournal);
        config(['platform.recovery_mode' => true]);

        $status = app(PrivacyRestoreSafety::class)->status();

        $this->assertSame('blocked', $status['state']);
        $this->assertSame('independent_journal_not_configured', $status['reason']);
        $this->assertTrue($status['recovery_mode']);
        $this->assertFalse($status['destructive_retention_enabled']);
        $this->artisan('privacy:restore-readiness --json')->assertFailed()->expectsOutputToContain('independent_journal_not_configured');
    }

    public function test_journal_availability_without_verified_replay_does_not_release_quarantine(): void
    {
        config(['platform.recovery_mode' => true]);
        $this->app->instance(PrivacyTombstoneJournal::class, new class implements PrivacyTombstoneJournal
        {
            public function readiness(): array
            {
                return ['state' => 'ready', 'reason' => 'available', 'watermark' => '01JAB0M5W0B7H9K6Q1M2V3X4YZ', 'replay_verified' => false, 'verified_events' => 0];
            }

            public function append(array $event): array
            {
                return $event;
            }

            public function watermark(): ?string
            {
                return '01JAB0M5W0B7H9K6Q1M2V3X4YZ';
            }

            public function eventsAfter(?string $snapshotWatermark): iterable
            {
                return [];
            }
        });

        $this->assertSame('blocked', app(PrivacyRestoreSafety::class)->status()['state']);
    }

    public function test_only_replayed_and_verified_journal_can_report_ready(): void
    {
        $this->app->instance(PrivacyTombstoneJournal::class, new class implements PrivacyTombstoneJournal
        {
            public function readiness(): array
            {
                return ['state' => 'ready', 'reason' => 'verified', 'watermark' => '01JAB0M5W0B7H9K6Q1M2V3X4YZ', 'replay_verified' => false, 'verified_events' => 0];
            }

            public function append(array $event): array
            {
                return $event;
            }

            public function watermark(): ?string
            {
                return '01JAB0M5W0B7H9K6Q1M2V3X4YZ';
            }

            public function eventsAfter(?string $snapshotWatermark): iterable
            {
                return [];
            }
        });

        $recoveryId = (string) Str::uuid();
        $sourceBackupUuid = (string) Str::uuid();
        config([
            'platform.recovery_mode' => true,
            'platform.recovery_id' => $recoveryId,
            'platform.recovery_source_backup_uuid' => $sourceBackupUuid,
            'platform.recovery_snapshot_watermark' => null,
        ]);
        PrivacyTombstoneReplaySession::query()->create([
            'recovery_id' => $recoveryId, 'source_backup_uuid' => $sourceBackupUuid, 'snapshot_watermark' => null,
            'ending_watermark' => '01JAB0M5W0B7H9K6Q1M2V3X4YZ',
            'status' => 'complete', 'verification_status' => 'verified', 'started_at' => now('UTC'), 'completed_at' => now('UTC'),
        ]);

        $this->assertSame('ready', app(PrivacyRestoreSafety::class)->status()['state']);
    }
}
