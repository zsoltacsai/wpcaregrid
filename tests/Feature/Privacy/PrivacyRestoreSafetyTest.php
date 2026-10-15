<?php

namespace Tests\Feature\Privacy;

use App\Privacy\PrivacyRestoreSafety;
use App\Privacy\PrivacyTombstoneJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyRestoreSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_independent_tombstone_journal_blocks_recovery_readiness(): void
    {
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
                return ['state' => 'ready', 'reason' => 'available', 'watermark' => 'snapshot-42', 'replay_verified' => false];
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
                return ['state' => 'ready', 'reason' => 'verified', 'watermark' => 'snapshot-42', 'replay_verified' => true];
            }

            public function eventsAfter(?string $snapshotWatermark): iterable
            {
                return [];
            }
        });

        $this->assertSame('ready', app(PrivacyRestoreSafety::class)->status()['state']);
    }
}
