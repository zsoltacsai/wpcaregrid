<?php

namespace Tests\Feature\Monitoring;

use App\Connector\HealthStatus;
use App\Models\MonitoringAlertDelivery;
use App\Models\MonitoringIncident;
use App\Models\MonitoringMaintenanceWindow;
use App\Models\MonitoringResult;
use App\Models\MonitoringTransition;
use App\Models\User;
use App\Monitoring\MonitoringEngine;
use App\Monitoring\MonitoringObservation;
use App\Monitoring\MonitoringReason;
use App\Monitoring\MonitoringScope;
use App\Monitoring\MonitoringSeverity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MonitoringEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_failure_threshold_opens_one_incident_and_recovery_requires_confirmation(): void
    {
        User::factory()->create(['role' => User::ROLE_OWNER, 'email_verified_at' => now()]);
        $engine = app(MonitoringEngine::class);

        $engine->record($this->failure());
        $engine->record($this->failure());
        $this->assertSame(0, MonitoringIncident::query()->count());
        $this->assertSame(HealthStatus::Attention, MonitoringResult::query()->firstOrFail()->state);
        $engine->record($this->failure());
        $engine->record($this->failure());
        $this->assertSame(1, MonitoringIncident::query()->where('status', 'open')->count());
        $this->assertSame(1, MonitoringTransition::query()->where('event_type', 'opened')->count());
        $this->assertSame(1, MonitoringAlertDelivery::query()->where('channel', 'email')->count());

        $engine->record($this->healthy());
        $this->assertSame(1, MonitoringIncident::query()->where('status', 'open')->count());
        $engine->record($this->healthy());
        $this->assertSame(0, MonitoringIncident::query()->where('status', 'open')->count());
        $this->assertSame(1, MonitoringIncident::query()->count());
        $this->assertSame(2, MonitoringTransition::query()->count());
        $this->assertSame(2, MonitoringAlertDelivery::query()->where('channel', 'email')->count());
    }

    public function test_critical_incident_only_escalates_and_never_downgrades(): void
    {
        app(MonitoringEngine::class)->record($this->failure(threshold: 1));
        $engine = app(MonitoringEngine::class);
        $incident = MonitoringIncident::query()->firstOrFail();
        $this->assertSame(MonitoringSeverity::Critical, $incident->severity);

        $warning = new MonitoringObservation(
            'website.http', MonitoringScope::Website, 'website', 'site-test',
            HealthStatus::Attention, MonitoringSeverity::Warning, MonitoringReason::HTTP_TIMEOUT,
            'active_probe', ['error_category' => 'http.timeout'], now(), 300, 1, 2,
        );
        $engine->record($warning);

        $this->assertSame(MonitoringSeverity::Critical, $incident->refresh()->severity);
        $this->assertSame(1, MonitoringTransition::query()->count());
    }

    public function test_only_authorized_operators_receive_critical_website_email(): void
    {
        User::factory()->create(['role' => User::ROLE_OWNER, 'email_verified_at' => now()]);
        $authorized = User::factory()->create([
            'role' => User::ROLE_OPERATOR, 'operator_permissions' => ['websites.view'],
            'operator_disabled_at' => null, 'email_verified_at' => now(),
        ]);
        $unverified = User::factory()->create([
            'role' => User::ROLE_OPERATOR, 'operator_permissions' => ['websites.view'],
            'operator_disabled_at' => null, 'email_verified_at' => null,
        ]);
        User::factory()->create([
            'role' => User::ROLE_OPERATOR, 'operator_permissions' => [],
            'operator_disabled_at' => null, 'email_verified_at' => now(),
        ]);

        app(MonitoringEngine::class)->record($this->failure(threshold: 1));

        $this->assertDatabaseHas('monitoring_alert_deliveries', [
            'recipient_user_id' => $authorized->id, 'channel' => 'email', 'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('monitoring_alert_deliveries', ['recipient_user_id' => $unverified->id]);
        $this->assertSame(2, MonitoringAlertDelivery::query()->where('channel', 'email')->count());
    }

    public function test_flapping_reopen_inside_cooldown_is_recorded_without_duplicate_alert(): void
    {
        User::factory()->create(['role' => User::ROLE_OWNER, 'email_verified_at' => now()]);
        $engine = app(MonitoringEngine::class);
        $engine->record($this->failure(threshold: 1));
        $engine->record($this->healthy());
        $engine->record($this->healthy());
        $engine->record($this->failure(threshold: 1));

        $this->assertSame(2, MonitoringIncident::query()->count());
        $this->assertSame(2, MonitoringTransition::query()->where('event_type', 'opened')->count());
        $this->assertSame(2, MonitoringAlertDelivery::query()->where('channel', 'email')->count());
        $this->assertSame('cooldown', MonitoringTransition::query()->where('event_type', 'opened')->latest('id')->firstOrFail()->evidence['suppression_reason']);
    }

    public function test_persistent_incident_repeats_one_escalation_after_cooldown(): void
    {
        User::factory()->create(['role' => User::ROLE_OWNER, 'email_verified_at' => now()]);
        $engine = app(MonitoringEngine::class);
        $engine->record($this->failure(threshold: 1));
        $this->travel(31)->minutes();
        $engine->record($this->failure(threshold: 1));
        $engine->record($this->failure(threshold: 1));

        $this->assertSame(1, MonitoringIncident::query()->where('status', 'open')->count());
        $this->assertSame(1, MonitoringTransition::query()->where('event_type', 'escalated')->count());
        $this->assertSame(2, MonitoringAlertDelivery::query()->where('channel', 'email')->count());
    }

    public function test_maintenance_records_state_but_suppresses_alert_delivery(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_OWNER, 'email_verified_at' => now()]);
        MonitoringMaintenanceWindow::query()->create([
            'scope' => 'website', 'subject_type' => 'website', 'subject_id' => 'site-test',
            'created_by' => $owner->id, 'reason' => 'Approved maintenance test',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addHour(),
        ]);

        app(MonitoringEngine::class)->record($this->failure(threshold: 1));

        $this->assertSame(1, MonitoringIncident::query()->count());
        $this->assertSame(0, MonitoringAlertDelivery::query()->where('channel', 'email')->count());
        $this->assertTrue(MonitoringTransition::query()->firstOrFail()->evidence['delivery_suppressed']);
    }

    public function test_different_subjects_do_not_share_incidents(): void
    {
        $engine = app(MonitoringEngine::class);
        $engine->record($this->failure(subject: 'site-a', threshold: 1));
        $engine->record($this->failure(subject: 'site-b', threshold: 1));

        $this->assertSame(2, MonitoringIncident::query()->count());
        $this->assertSame(2, MonitoringResult::query()->count());
    }

    private function failure(string $subject = 'site-test', int $threshold = 3): MonitoringObservation
    {
        return new MonitoringObservation(
            'website.http', MonitoringScope::Website, 'website', $subject,
            HealthStatus::Critical, MonitoringSeverity::Critical, MonitoringReason::HTTP_UNREACHABLE,
            'active_probe', ['error_category' => 'http.unreachable'], now(), 300, $threshold, 2,
        );
    }

    private function healthy(): MonitoringObservation
    {
        return new MonitoringObservation(
            'website.http', MonitoringScope::Website, 'website', 'site-test',
            HealthStatus::Healthy, null, MonitoringReason::MONITORING_OK, 'active_probe', ['http_status' => 200], now(), 300, 3, 2,
        );
    }
}
