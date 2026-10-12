<?php

namespace Tests\Feature\Monitoring;

use App\Connector\HealthStatus;
use App\Models\MonitoringIncident;
use App\Models\MonitoringMaintenanceWindow;
use App\Models\MonitoringResult;
use App\Models\User;
use App\Models\Website;
use App\Monitoring\MonitoringReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

final class MonitoringConsoleTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    public function test_owner_can_view_platform_incidents_and_observations(): void
    {
        $this->asOwner();
        MonitoringIncident::query()->create([
            'incident_key' => hash('sha256', 'platform.database|platform|platform|primary'),
            'generation' => 1, 'check_id' => 'platform.database', 'scope' => 'platform',
            'subject_type' => 'platform', 'subject_id' => 'primary', 'severity' => 'critical',
            'reason_code' => 'database.unavailable', 'status' => 'open', 'opened_at' => now(), 'last_observed_at' => now(),
        ]);
        MonitoringResult::query()->create([
            'check_id' => 'platform.database', 'scope' => 'platform', 'subject_type' => 'platform', 'subject_id' => 'primary',
            'state' => HealthStatus::Healthy, 'severity' => null, 'reason_code' => 'monitoring.ok', 'source' => 'queue',
            'observed_at' => now(), 'fresh_until' => now()->addMinutes(5),
        ]);

        $this->get(route('owner.monitoring.index'))
            ->assertOk()->assertSee(__('m10.active_incidents'))
            ->assertSee(MonitoringReason::label('database.unavailable'))
            ->assertSee('platform.database');
    }

    public function test_owner_maintenance_form_renders_a_submit_button(): void
    {
        $this->asOwner();

        $this->get(route('owner.monitoring.index'))
            ->assertOk()
            ->assertSee('<button type="submit" data-testid="maintenance-window-submit"', false)
            ->assertSee(__('m10.create_maintenance'));
    }

    public function test_expired_healthy_observation_is_displayed_as_unknown_not_healthy(): void
    {
        $this->asOwner();
        MonitoringResult::query()->create([
            'check_id' => 'platform.database', 'scope' => 'platform', 'subject_type' => 'platform', 'subject_id' => 'primary',
            'state' => HealthStatus::Healthy, 'severity' => null, 'reason_code' => 'monitoring.ok', 'source' => 'queue',
            'observed_at' => now()->subMinutes(10), 'fresh_until' => now()->subMinute(),
        ]);

        $this->get(route('owner.monitoring.index'))
            ->assertOk()
            ->assertSee(__('site_health.states.unknown'))
            ->assertSee(MonitoringReason::label(MonitoringReason::MONITORING_SOURCE_UNVERIFIED))
            ->assertDontSee(__('site_health.states.healthy'));
    }

    public function test_operator_can_only_view_incidents_for_the_requested_website_identity(): void
    {
        $website = Website::factory()->create();
        $otherWebsite = Website::factory()->create();
        $operator = User::factory()->create([
            'role' => User::ROLE_OPERATOR,
            'operator_permissions' => ['websites.view'],
            'operator_disabled_at' => null,
            'email_verified_at' => now(),
        ]);
        foreach ([$website, $otherWebsite] as $index => $subject) {
            MonitoringIncident::query()->create([
                'incident_key' => hash('sha256', 'website.http|website|website|'.$subject->uuid),
                'generation' => 1, 'check_id' => 'website.http', 'scope' => 'website',
                'subject_type' => 'website', 'subject_id' => $subject->uuid, 'severity' => 'critical',
                'reason_code' => 'http.unreachable', 'status' => 'open', 'opened_at' => now(), 'last_observed_at' => now(),
                'evidence' => ['http_status' => 503, 'site_marker' => 'scope-'.$index],
            ]);
        }

        $this->actingAs($operator, 'operator')->get(route('operator.websites.monitoring', $website))
            ->assertOk()->assertSee('scope-0')->assertDontSee('scope-1');
    }

    public function test_operator_without_website_view_permission_is_refused(): void
    {
        $website = Website::factory()->create();
        $operator = User::factory()->create([
            'role' => User::ROLE_OPERATOR, 'operator_permissions' => [], 'operator_disabled_at' => null, 'email_verified_at' => now(),
        ]);

        $this->actingAs($operator, 'operator')->get(route('operator.websites.monitoring', $website))->assertForbidden();
    }

    public function test_owner_can_create_site_scoped_maintenance_window(): void
    {
        $this->asOwner();
        $website = Website::factory()->create();

        $this->post(route('owner.monitoring.maintenance.store'), [
            'scope' => 'website',
            'website_uuid' => $website->uuid,
            'check_family' => 'website',
            'reason' => 'M10 staging maintenance test',
            'starts_at' => now()->format('Y-m-d\\TH:i'),
            'ends_at' => now()->addMinutes(20)->format('Y-m-d\\TH:i'),
        ])->assertRedirect(route('owner.monitoring.index'));

        $window = MonitoringMaintenanceWindow::query()->where('subject_id', $website->uuid)->firstOrFail();
        $this->assertSame('website', $window->scope->value);
        $this->assertSame('website', $window->check_family);
        $this->assertDatabaseHas('admin_audit_events', [
            'event' => 'monitoring.maintenance_created',
            'subject_type' => 'maintenance_window',
            'subject_key' => (string) $window->id,
        ]);
    }
}
