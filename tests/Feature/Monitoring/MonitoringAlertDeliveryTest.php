<?php

namespace Tests\Feature\Monitoring;

use App\Mail\MonitoringIncidentMail;
use App\Models\MonitoringAlertDelivery;
use App\Models\MonitoringIncident;
use App\Models\MonitoringTransition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class MonitoringAlertDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_delivery_command_sends_each_outbox_row_once(): void
    {
        Mail::fake();
        $owner = User::factory()->create(['role' => User::ROLE_OWNER, 'email_verified_at' => now()]);
        $incident = MonitoringIncident::query()->create([
            'incident_key' => hash('sha256', 'platform.database|platform|platform|primary'),
            'generation' => 1,
            'check_id' => 'platform.database',
            'scope' => 'platform',
            'subject_type' => 'platform',
            'subject_id' => 'primary',
            'severity' => 'critical',
            'reason_code' => 'database.unavailable',
            'status' => 'open',
            'opened_at' => now(),
            'last_observed_at' => now(),
        ]);
        $transition = MonitoringTransition::query()->create([
            'incident_id' => $incident->id,
            'event_type' => 'opened',
            'check_id' => $incident->check_id,
            'scope' => $incident->scope->value,
            'subject_type' => $incident->subject_type,
            'subject_id' => $incident->subject_id,
            'to_state' => 'critical',
            'reason_code' => $incident->reason_code,
            'source' => 'queue',
            'created_at' => now(),
        ]);
        $delivery = MonitoringAlertDelivery::query()->create([
            'incident_id' => $incident->id,
            'transition_id' => $transition->id,
            'recipient_user_id' => $owner->id,
            'channel' => 'email',
            'status' => 'pending',
            'idempotency_key' => hash('sha256', 'monitoring-alert-once'),
        ]);

        $this->artisan('monitoring:deliver-alerts')->assertSuccessful();
        $this->artisan('monitoring:deliver-alerts')->assertSuccessful();

        Mail::assertSent(MonitoringIncidentMail::class, 1);
        $this->assertSame('sent', $delivery->refresh()->status);
        $this->assertSame(1, $delivery->attempts);
    }

    public function test_interrupted_smtp_delivery_is_failed_without_blind_resend(): void
    {
        $delivery = MonitoringAlertDelivery::query()->create([
            'incident_id' => MonitoringIncident::query()->create([
                'incident_key' => hash('sha256', 'platform.database|platform|platform|primary'),
                'generation' => 1,
                'check_id' => 'platform.database',
                'scope' => 'platform',
                'subject_type' => 'platform',
                'subject_id' => 'primary',
                'severity' => 'critical',
                'reason_code' => 'database.unavailable',
                'status' => 'open',
                'opened_at' => now(),
                'last_observed_at' => now(),
            ])->id,
            'transition_id' => MonitoringTransition::query()->create([
                'event_type' => 'opened',
                'check_id' => 'platform.database',
                'scope' => 'platform',
                'subject_type' => 'platform',
                'subject_id' => 'primary',
                'to_state' => 'critical',
                'reason_code' => 'database.unavailable',
                'source' => 'queue',
                'created_at' => now(),
            ])->id,
            'recipient_user_id' => User::factory()->create(['role' => User::ROLE_OWNER, 'email_verified_at' => now()])->id,
            'channel' => 'email',
            'status' => 'sending',
            'idempotency_key' => hash('sha256', 'monitoring-alert-interrupted'),
            'attempts' => 1,
            'last_attempt_at' => now()->subMinutes(11),
        ]);

        Mail::fake();
        $this->artisan('monitoring:deliver-alerts')->assertSuccessful();
        $this->artisan('monitoring:deliver-alerts')->assertSuccessful();

        Mail::assertNothingOutgoing();
        $this->assertSame('failed', $delivery->refresh()->status);
        $this->assertSame('delivery_interrupted', $delivery->failure_code);
        $this->assertSame(1, $delivery->attempts);
    }
}
