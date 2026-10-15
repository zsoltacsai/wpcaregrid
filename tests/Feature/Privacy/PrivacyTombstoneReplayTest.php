<?php

namespace Tests\Feature\Privacy;

use App\Models\Customer;
use App\Models\PrivacyTombstoneReplaySession;
use App\Models\Subscription;
use App\Privacy\PrivacyRequestService;
use App\Privacy\PrivacyRequestSource;
use App\Privacy\PrivacyRequestStatus;
use App\Privacy\PrivacyRequestType;
use App\Privacy\PrivacyTombstoneJournal;
use App\Privacy\PrivacyTombstoneReplayApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrivacyTombstoneReplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_replay_applies_post_snapshot_account_suppression_and_is_repeatable(): void
    {
        config(['platform.recovery_mode' => true]);
        $customer = Customer::factory()->withUser()->create();
        $case = app(PrivacyRequestService::class)->create($customer, PrivacyRequestType::AccountClosure, PrivacyRequestSource::Support, 'replay-t0-synthetic-0001');
        $event = $this->event($customer->uuid, $case->uuid);
        $journal = $this->journal([$event]);
        $this->app->instance(PrivacyTombstoneJournal::class, $journal);

        $first = app(PrivacyTombstoneReplayApplier::class)->replay((string) Str::uuid(), '01JAB0M5W0B7H9K6Q1M2V3X4YZ');
        $this->assertSame('complete', $first->status);
        $this->assertSame(1, $first->events_applied);
        $this->assertFalse($customer->fresh()->active);
        $this->assertSame(PrivacyRequestStatus::AccessRestricted, $case->fresh()->status);

        $second = app(PrivacyTombstoneReplayApplier::class)->replay((string) Str::uuid(), '01JAB0M5W0B7H9K6Q1M2V3X4YZ');
        $this->assertSame('complete', $second->status);
        $this->assertSame(1, $second->events_already_applied);
        $this->assertSame(2, PrivacyTombstoneReplaySession::query()->where('status', 'complete')->count());
        $this->assertSame(2, PrivacyTombstoneReplaySession::query()->count());
        $this->assertFalse($customer->fresh()->active);
    }

    public function test_replay_blocks_when_restored_billing_state_is_still_active(): void
    {
        config(['platform.recovery_mode' => true]);
        $customer = Customer::factory()->withUser()->create();
        $case = app(PrivacyRequestService::class)->create($customer, PrivacyRequestType::AccountClosure, PrivacyRequestSource::Support, 'replay-billing-synthetic-0001');
        Subscription::factory()->for($customer)->create();
        $this->app->instance(PrivacyTombstoneJournal::class, $this->journal([$this->event($customer->uuid, $case->uuid)]));

        $session = app(PrivacyTombstoneReplayApplier::class)->replay((string) Str::uuid(), null);

        $this->assertSame('blocked', $session->status);
        $this->assertSame(1, $session->events_blocked);
        $this->assertTrue($customer->fresh()->active);
        $this->assertSame(PrivacyRequestStatus::Requested, $case->fresh()->status);
        $this->assertDatabaseHas('privacy_tombstone_replay_events', ['replay_session_id' => $session->id, 'blocker_code' => 'billing_reconciliation_required']);
    }

    public function test_replay_blocks_a_cross_tenant_event_and_keeps_quarantine_blocked(): void
    {
        config(['platform.recovery_mode' => true]);
        $customer = Customer::factory()->withUser()->create();
        $case = app(PrivacyRequestService::class)->create($customer, PrivacyRequestType::AccountClosure, PrivacyRequestSource::Support, 'replay-tenant-synthetic-0001');
        $event = $this->event($customer->uuid, $case->uuid);
        $event['tenant_uuid'] = (string) Str::uuid();
        $journal = $this->journal([$event]);
        $this->app->instance(PrivacyTombstoneJournal::class, $journal);

        $session = app(PrivacyTombstoneReplayApplier::class)->replay((string) Str::uuid(), null);

        $this->assertSame('blocked', $session->status);
        $this->assertSame(1, $session->events_blocked);
        $this->assertTrue($customer->fresh()->active);
        $this->assertSame(PrivacyRequestStatus::Requested, $case->fresh()->status);
        $this->assertDatabaseHas('privacy_tombstone_replay_events', [
            'replay_session_id' => $session->id,
            'blocker_code' => 'tenant_scope_mismatch',
        ]);
    }

    private function event(string $subjectUuid, string $requestUuid): array
    {
        return [
            'schema_version' => 1,
            'event_id' => hash('sha256', 'replay-event-'.$requestUuid),
            'sequence' => '01JAB0M5W1B7H9K6Q1M2V3X4YZ',
            'subject_uuid' => $subjectUuid,
            'tenant_uuid' => $subjectUuid,
            'scope' => 'customer_account',
            'action' => 'account_access_restricted',
            'request_uuid' => $requestUuid,
            'effective_at' => now('UTC')->toIso8601String(),
            'journaled_at' => now('UTC')->toIso8601String(),
        ];
    }

    private function journal(array $events): PrivacyTombstoneJournal
    {
        return new class($events) implements PrivacyTombstoneJournal
        {
            public function __construct(private readonly array $events) {}

            public function readiness(): array
            {
                return ['state' => 'ready', 'reason' => 'journal_integrity_verified', 'watermark' => $this->watermark(), 'replay_verified' => false, 'verified_events' => count($this->events)];
            }

            public function append(array $event): array
            {
                return $event;
            }

            public function watermark(): ?string
            {
                $sequences = array_column($this->events, 'sequence');
                sort($sequences);

                return $sequences === [] ? null : end($sequences);
            }

            public function eventsAfter(?string $snapshotWatermark): iterable
            {
                return array_values(array_filter($this->events, static fn (array $event): bool => $snapshotWatermark === null || strcmp($event['sequence'], $snapshotWatermark) > 0));
            }
        };
    }
}
