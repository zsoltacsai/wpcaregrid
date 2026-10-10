<?php

namespace Tests\Feature\Operator;

use App\AdminAccess\AccessLevel;
use App\AdminAccess\ActorType;
use App\AdminAccess\AdminAccessService;
use App\AdminAccess\AdminAccessStatus;
use App\Backups\BackupReason;
use App\Backups\BackupStatus;
use App\Backups\RestoreStatus;
use App\Connector\DisconnectWebsite;
use App\Connector\JobStatus;
use App\Connector\JobType;
use App\Models\AdminAccessRequest;
use App\Models\Backup;
use App\Models\BackupRestore;
use App\Models\User;
use App\Models\WebsiteEvent;
use App\Models\WebsiteJob;
use App\SyntheticFixtures\SyntheticFixtureProvisioner;
use App\Websites\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OperatesConsole;
use Tests\TestCase;

class OperatorActionsTest extends TestCase
{
    use OperatesConsole, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups(['manual' => true, 'restore' => true, 'pre_update_backup' => false]);
        config(['backup.restore.enabled' => true]);
    }

    protected function tearDown(): void
    {
        $this->tearDownBackups();
        parent::tearDown();
    }

    public function test_synthetic_site_is_visibly_classified_and_uses_normal_operator_pairing_flow(): void
    {
        config([
            'app.env' => 'production',
            'app.url' => 'https://staging.wpcaregrid.com',
            'app.deployment_environment' => 'staging',
            'app.deployment_hostname' => 'stg1.wpcaregrid.com',
            'synthetic_fixtures.provisioning_enabled' => true,
            'synthetic_fixtures.controlled_execution_enabled' => false,
        ]);
        $website = app(SyntheticFixtureProvisioner::class)->provision(
            'WP CareGrid M8 E2E', 'm8-e2e@example.test', 'https://m8-e2e.staging.wpcaregrid.com',
        )['website'];
        $this->asOperator();

        $this->get(route('operator.websites.show', $website))
            ->assertOk()->assertSee(__('owner.synthetic_fixture_badge'));

        $this->actingAs(User::factory()->owner()->create(), 'owner')
            ->get(route('owner.customers.show', $website->customer))
            ->assertOk()->assertSee(__('owner.synthetic_fixture_badge'))
            ->assertDontSee(__('owner.offers.new'));

        $this->asOperator();
        $this->post(route('operator.websites.pairing-code', $website))
            ->assertRedirect(route('operator.websites.show', $website))
            ->assertSessionHas('pairing_code');

        $this->get(route('operator.websites.show', $website))
            ->assertOk()->assertSee(__('owner.synthetic_fixture_badge'));
    }

    /* ----- Backups ----- */

    public function test_backup_request_goes_through_the_backup_service_and_names_the_operator(): void
    {
        $site = $this->consoleSite();
        $operator = $this->asOperator();

        $this->post(route('operator.websites.backups.store', $site['website']))->assertRedirect()->assertSessionHas('status', __('operator.backups.queued'));

        $backup = Backup::sole();
        $this->assertSame(BackupReason::Manual, $backup->reason);
        $this->assertSame(BackupStatus::Queued, $backup->status);
        $this->assertSame('operator:'.$operator->id, $backup->requested_by);
        $this->assertSame('BACKUP_FULL', $this->backupJob($backup)->type->value, 'A connector job, not a second backup mechanism.');

        $event = WebsiteEvent::where('event', 'backup.requested')->sole();
        $this->assertSame('operator', $event->actor_type);
        $this->assertSame($operator->id, $event->actor_user_id);

        // The whole normal lifecycle applies: the connector uploads, storage verifies.
        $this->makeVerifiedBackupThroughApi($site, $backup);
        $this->assertSame(BackupStatus::Verified, $backup->refresh()->status);

        $this->post(route('operator.websites.backups.store', $site['website']))
            ->assertSessionHas('operator_error', fn ($e) => $e['code'] === 'TOO_SOON' && $e['kind'] === 'busy');
        $this->assertSame(1, Backup::count());
    }

    public function test_backup_on_a_disconnected_site_says_the_site_is_offline(): void
    {
        $site = $this->consoleSite();
        app(DisconnectWebsite::class)($site['website'], 'disconnected_by_customer', 'system');
        $this->asOperator();

        $this->post(route('operator.websites.backups.store', $site['website']))
            ->assertSessionHas('operator_error', fn ($e) => $e['code'] === 'NOT_CONNECTED' && $e['kind'] === 'offline');
        $this->assertSame(0, Backup::count());
    }

    /* ----- Updates ----- */

    public function test_plugin_update_is_queued_as_a_connector_job_and_its_lifecycle_is_audited(): void
    {
        $site = $this->consoleSite();
        $operator = $this->asOperator();

        $this->post(route('operator.websites.updates.store', $site['website']), ['kind' => 'plugin', 'target' => 'akismet/akismet.php'])
            ->assertRedirect()->assertSessionHas('status', __('operator.updates.queued'));

        $job = WebsiteJob::sole();
        $this->assertSame(JobType::UpdatePlugin, $job->type);
        $this->assertSame(JobStatus::Queued, $job->status);
        $this->assertSame(['plugin' => 'akismet/akismet.php'], $job->payload);
        $this->assertSame('operator:'.$operator->id, $job->requested_by);

        // Delivered by the normal poll, reported by the connector.
        $this->assertSame([$job->uuid], array_column($this->pollJobs($site), 'uuid'));
        $this->reportJob($site, $job->refresh(), 'success', 'UPDATED', ['from_version' => '5.3', 'to_version' => '5.4'])->assertSuccessful();

        $events = WebsiteEvent::where('event', 'like', 'update.%')->orderBy('id')->get();
        $this->assertSame(['update.requested', 'update.started', 'update.completed'], $events->pluck('event')->all());
        $this->assertSame('operator', $events[0]->actor_type);
        $this->assertSame($operator->id, $events[0]->actor_user_id);
        $this->assertSame('akismet/akismet.php', $events[0]->data['target']);
        $this->assertSame('system', $events[2]->actor_type);

        $this->get(route('operator.websites.show', $site['website']))->assertOk()->assertSee(__('operator.updates.last_result'))->assertSee('UPDATED');
    }

    public function test_failed_update_is_recorded_as_failed(): void
    {
        $site = $this->consoleSite();
        $this->asOperator();
        $this->post(route('operator.websites.updates.store', $site['website']), ['kind' => 'theme', 'target' => 'twentytwentyfive'])
            ->assertSessionHas('operator_error', fn ($e) => $e['code'] === 'NO_UPDATE_AVAILABLE');

        $this->post(route('operator.websites.updates.store', $site['website']), ['kind' => 'core'])->assertSessionHas('status');
        $job = WebsiteJob::sole();
        $this->assertSame(['policy' => 'minor'], $job->payload);
        $this->pollJobs($site);
        $this->reportJob($site, $job->refresh(), 'failed', 'UPDATE_FAILED', [], ['code' => 'UPDATE_FAILED', 'message' => 'Download failed.'])->assertSuccessful();

        $failed = WebsiteEvent::where('event', 'update.failed')->sole();
        $this->assertSame('UPDATE_FAILED', $failed->data['result']);
    }

    public function test_only_updates_the_site_reports_can_be_requested_once_at_a_time(): void
    {
        $site = $this->consoleSite();
        $this->asOperator();
        $url = route('operator.websites.updates.store', $site['website']);

        foreach ([
            [['kind' => 'plugin', 'target' => 'wp-rocket/wp-rocket.php'], 'NO_UPDATE_AVAILABLE'],   // update_available unknown
            [['kind' => 'plugin', 'target' => 'not-installed/x.php'], 'NO_UPDATE_AVAILABLE'],
            [['kind' => 'plugin', 'target' => '../../wp-config.php'], 'NO_UPDATE_AVAILABLE'],
        ] as [$data, $code]) {
            $this->post($url, $data)->assertSessionHas('operator_error', fn ($e) => $e['code'] === $code);
        }
        $this->post($url, ['kind' => 'shell', 'target' => 'rm -rf'])->assertSessionHasErrors('kind');
        $this->post($url, ['kind' => 'plugin'])->assertSessionHasErrors('target');
        $this->assertSame(0, WebsiteJob::count());

        $this->post($url, ['kind' => 'plugin', 'target' => 'akismet/akismet.php'])->assertSessionHas('status');
        $this->post($url, ['kind' => 'plugin', 'target' => 'akismet/akismet.php'])->assertSessionHas('operator_error', fn ($e) => $e['code'] === 'UPDATE_IN_PROGRESS' && $e['kind'] === 'busy');
        $this->assertSame(1, WebsiteJob::count());
    }

    public function test_updates_wait_for_the_pre_update_backup_when_the_plan_requires_it(): void
    {
        $this->setUpBackups(['manual' => true, 'restore' => true, 'pre_update_backup' => true]);
        $site = $this->consoleSite();
        $this->asOperator();

        $this->post(route('operator.websites.updates.store', $site['website']), ['kind' => 'plugin', 'target' => 'akismet/akismet.php'])
            ->assertSessionHas('status', __('operator.updates.queued_after_backup'));

        $update = WebsiteJob::where('type', 'UPDATE_PLUGIN')->sole();
        $this->assertSame(JobStatus::Waiting, $update->status, 'Never delivered before a verified backup exists.');
        $this->assertSame(BackupReason::PreUpdate, $update->backup->reason);
    }

    public function test_update_needs_a_connector_that_supports_updates(): void
    {
        $site = $this->consoleSite();
        $site['website']->activeConnector->update(['capabilities' => ['inventory', 'health']]);
        $this->asOperator();

        $this->post(route('operator.websites.updates.store', $site['website']), ['kind' => 'core'])
            ->assertSessionHas('operator_error', fn ($e) => $e['code'] === 'CONNECTOR_OUTDATED' && $e['kind'] === 'outdated');
        $this->assertSame(0, WebsiteJob::count());
    }

    /* ----- Restore ----- */

    public function test_restore_uses_the_existing_safety_model(): void
    {
        $site = $this->consoleSite();
        $website = $site['website'];
        $backup = $this->verifiedBackup($website, at: now()->subDay());
        $operator = $this->asOperator();

        $this->get(route('operator.websites.show', $website))->assertSee(route('operator.websites.backups.restore', [$website, $backup]));
        $this->get(route('operator.websites.backups.restore', [$website, $backup]))->assertOk()->assertSee(__('backups.restore.safety'));

        // Confirmation: the address typed out, and the checkbox.
        $this->post(route('operator.websites.backups.restore.store', [$website, $backup]), ['scope' => 'full', 'confirmation' => 'company.example'])->assertSessionHasErrors('understood');
        $this->post(route('operator.websites.backups.restore.store', [$website, $backup]), ['scope' => 'full', 'confirmation' => 'other.example', 'understood' => '1'])
            ->assertSessionHas('operator_error', fn ($e) => $e['code'] === 'CONFIRMATION_MISMATCH');
        $this->assertSame(0, BackupRestore::count());

        $this->post(route('operator.websites.backups.restore.store', [$website, $backup]), ['scope' => 'full', 'confirmation' => 'COMPANY.example', 'understood' => '1'])
            ->assertRedirect(route('operator.websites.show', $website).'#backups');

        $restore = BackupRestore::sole();
        $this->assertSame(RestoreStatus::AwaitingSafetyBackup, $restore->status);
        $this->assertSame('operator:'.$operator->id, $restore->requested_by);
        $this->assertSame(BackupReason::PreRestore, $restore->safetyBackup->reason, 'A safety backup runs first.');
        $this->assertFalse(WebsiteJob::where('type', 'RESTORE')->exists(), 'Nothing is restored before the safety backup is verified.');
        $this->assertSame('operator', WebsiteEvent::where('event', 'restore.requested')->sole()->actor_type);
    }

    public function test_restore_is_not_offered_for_unverified_backups_or_offline_sites(): void
    {
        $site = $this->consoleSite();
        $website = $site['website'];
        $failed = $website->backups()->create(['type' => 'full', 'reason' => 'manual', 'status' => BackupStatus::Failed, 'requested_by' => 'test']);
        $verified = $this->verifiedBackup($website);
        $this->asOperator();

        $this->get(route('operator.websites.show', $website))
            ->assertDontSee(route('operator.websites.backups.restore', [$website, $failed]))
            ->assertSee(route('operator.websites.backups.restore', [$website, $verified]));
        $this->post(route('operator.websites.backups.restore.store', [$website, $failed]), ['scope' => 'full', 'confirmation' => 'company.example', 'understood' => '1'])
            ->assertSessionHas('operator_error', fn ($e) => $e['code'] === 'BACKUP_NOT_RESTORABLE');

        $website->activeConnector->forceFill(['last_seen_at' => now()->subHours(3)])->save();
        $this->get(route('operator.websites.show', $website))->assertDontSee(route('operator.websites.backups.restore', [$website, $verified]));
        $this->get(route('operator.websites.backups.restore', [$website, $verified]))->assertOk()->assertSee(__('operator.refusals.NOT_CONNECTED'));
        $this->post(route('operator.websites.backups.restore.store', [$website, $verified]), ['scope' => 'full', 'confirmation' => 'company.example', 'understood' => '1'])
            ->assertSessionHas('operator_error', fn ($e) => $e['code'] === 'NOT_CONNECTED' && $e['kind'] === 'offline');

        $this->assertSame(0, BackupRestore::count());
    }

    /* ----- Admin access ----- */

    public function test_maintenance_access_goes_through_the_admin_access_service(): void
    {
        $site = $this->consoleSite();
        $operator = $this->asOperator();

        $response = $this->post(route('operator.websites.admin-access.maintenance', $site['website']))->assertOk()
            ->assertSee('action="https://company.example/wp-login.php?action=wp_caregrid_access"', false)
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $request = AdminAccessRequest::sole();
        $this->assertSame(ActorType::Operator, $request->actor_type);
        $this->assertSame(AccessLevel::Maintenance, $request->access_level);
        $this->assertSame($operator->id, $request->requested_by_user_id);
        $this->assertSame('CREATE_ADMIN_SESSION', $request->job->type->value);
        $event = WebsiteEvent::where('event', 'admin_access.requested')->sole();
        $this->assertSame('operator', $event->actor_type);
        $this->assertSame($operator->id, $event->actor_user_id);
    }

    public function test_active_access_is_shown_and_can_be_revoked_by_an_operator(): void
    {
        $site = $this->consoleSite();
        $website = $site['website'];
        [$request] = app(AdminAccessService::class)->request($website, $website->customer->user, ActorType::Customer, AccessLevel::Maintenance);
        $request->forceFill(['status' => AdminAccessStatus::Active, 'session_expires_at' => now()->addMinutes(20)])->save();
        $operator = $this->asOperator();

        $this->get(route('operator.websites.show', $website))->assertOk()
            ->assertSee(__('operator.admin_access.maintenance_active'))
            ->assertSee(__('operator.admin_access.expires_in', ['minutes' => 19]))
            ->assertSee(__('operator.admin_access.revoke'));

        $this->post(route('operator.websites.admin-access.revoke', [$website, $request]))
            ->assertRedirect(route('operator.websites.show', $website).'#admin-access');

        $request->refresh();
        $this->assertSame(AdminAccessStatus::Revoked, $request->status);
        $this->assertSame('revoked_by_operator', $request->end_reason);
        $this->assertSame($operator->id, $request->revoked_by_user_id);
        $event = WebsiteEvent::where('event', 'admin_access.revoked')->sole();
        $this->assertSame('operator', $event->actor_type);
        $this->assertSame($operator->id, $event->actor_user_id);

        $this->get(route('operator.websites.show', $website))->assertSee(__('operator.admin_access.none'));
    }

    public function test_full_admin_active_is_labelled_as_such(): void
    {
        $site = $this->consoleSite();
        $website = $site['website'];
        [$request] = app(AdminAccessService::class)->request($website, $website->customer->user, ActorType::Customer, AccessLevel::FullAdmin, true);
        $request->forceFill(['status' => AdminAccessStatus::Active, 'session_expires_at' => now()->addMinutes(10)])->save();
        $this->asOperator();

        $this->get(route('operator.websites.show', $website))->assertOk()->assertSee(__('operator.admin_access.full_active'));
    }

    /* ----- Connector ----- */

    public function test_disconnect_needs_confirmation_and_is_audited(): void
    {
        $site = $this->consoleSite();
        $website = $site['website'];
        $operator = $this->asOperator();

        $this->post(route('operator.websites.disconnect', $website))->assertSessionHasErrors('confirm');
        $this->assertNotNull($website->refresh()->activeConnector);

        $this->post(route('operator.websites.disconnect', $website), ['confirm' => '1'])->assertSessionHas('status', __('operator.connector.disconnected'));

        $this->assertNull($website->refresh()->activeConnector);
        $this->assertSame('disconnected_by_operator', $website->connectors()->sole()->revoked_reason);
        $event = WebsiteEvent::where('event', 'connector.disconnected')->sole();
        $this->assertSame('operator', $event->actor_type);
        $this->assertSame($operator->id, $event->actor_user_id);
        $this->withHeaders($this->connectorHeaders($site))->getJson(route('api.v1.connector.jobs'))->assertUnauthorized();
    }

    public function test_re_pair_shows_a_one_time_code(): void
    {
        $site = $this->consoleSite();
        $this->asOperator();

        $response = $this->followingRedirects()->post(route('operator.websites.pairing-code', $site['website']))->assertOk()->assertSee(__('operator.connector.code_label'));
        $this->assertMatchesRegularExpression('/[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}/', $response->getContent());
        $this->assertSame(1, $site['website']->pairingCodes()->whereNull('used_at')->count());
        $this->assertNotNull($site['website']->refresh()->activeConnector, 'The current connector keeps working until the code is used.');

        $this->get(route('operator.websites.show', $site['website']))->assertDontSee(__('operator.connector.code_label'));
    }

    /* ----- Audit actors ----- */

    public function test_audit_log_stores_actor_type_and_user_as_data(): void
    {
        $site = $this->consoleSite();
        $website = $site['website'];
        $customer = $website->customer->user;
        $operator = $this->operator();

        $this->assertSame(['customer', $customer->id], $this->actorOf(AuditLog::record($website, 'backup.requested', actor: 'customer:'.$customer->id)));
        $this->assertSame(['operator', $operator->id], $this->actorOf(AuditLog::record($website, 'backup.requested', actor: 'operator:'.$operator->id)));
        $this->assertSame(['system', null], $this->actorOf(AuditLog::record($website, 'backup.requested')));
        $this->assertSame(['customer', null], $this->actorOf(AuditLog::record($website, 'backup.requested', actor: 'customer:999999')), 'A label naming no user keeps only its type.');
        $this->assertSame(['system', null], $this->actorOf(AuditLog::record($website, 'backup.requested', actor: 'pairing')));

        // Customer actions in the customer area are recorded as customer actions.
        $this->actingAs($customer)->post(route('account.websites.backups.store', $website))->assertRedirect();
        $this->assertSame(['customer', $customer->id], $this->actorOf(WebsiteEvent::latest('id')->first()));
    }

    public function test_license_expiry_reported_by_a_plugin_is_audited_once(): void
    {
        $site = $this->consoleSite();
        $payload = $this->inventoryPayload();
        $payload['plugins'][1]['license'] = ['status' => 'expired', 'expires_at' => '2026-09-01T00:00:00Z', 'source' => 'wp-rocket'];

        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.inventory'), $payload)->assertStatus(202);
        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.inventory'), $payload)->assertStatus(202);

        $this->assertSame(1, WebsiteEvent::where('event', 'license.expired')->count());
        $this->assertSame(1, $site['website']->inventory->refresh()->licenses_expired);

        $this->asOperator();
        $this->get(route('operator.websites.show', $site['website']))->assertOk()->assertSee(__('operator.licenses.states.expired'))->assertSee('wp-rocket');
    }

    /** @return array{0: ?string, 1: ?int} */
    private function actorOf(WebsiteEvent $event): array
    {
        return [$event->actor_type, $event->actor_user_id];
    }
}
