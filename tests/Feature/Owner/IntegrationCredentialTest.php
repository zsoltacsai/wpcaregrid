<?php

namespace Tests\Feature\Owner;

use App\Models\AdminAuditEvent;
use App\Models\Customer;
use App\Models\User;
use App\Operator\Permission;
use App\Owner\IntegrationCredentials;
use App\Platform\DisasterRecovery\PlatformBackupStorage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

class IntegrationCredentialTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    public function test_owner_can_store_replace_and_clear_a_credential_without_exposing_it(): void
    {
        config(['owner_integration_credentials.environment_fallbacks.stripe_secret' => false]);
        $owner = $this->asOwner();
        $first = 'test-only-'.str_repeat('a', 48);
        $replacement = 'test-only-'.str_repeat('b', 48);

        Log::spy();
        $queryBindings = [];
        DB::listen(function (QueryExecuted $query) use (&$queryBindings): void {
            $queryBindings[] = json_encode($query->bindings);
        });
        $this->from(route('owner.integrations.index'))
            ->post(route('owner.integrations.store'), ['id' => 'stripe_secret', 'value' => $first])
            ->assertRedirect(route('owner.integrations.index'))
            ->assertSessionMissing('_old_input')
            ->assertDontSee($first);

        $row = DB::table('integration_credentials')->where('id', 'stripe_secret')->sole();
        $this->assertNotSame($first, $row->value);
        $this->assertSame($first, Crypt::decryptString($row->value));
        $this->assertSame(['owner_managed' => true, 'environment_fallback' => false], app(IntegrationCredentials::class)->configured()['stripe_secret']);
        $this->assertSame($owner->id, AdminAuditEvent::where('event', 'integration_credential.updated')->sole()->actor_user_id);
        $audit = AdminAuditEvent::where('event', 'integration_credential.updated')->sole();
        $this->assertSame('stripe_secret', $audit->subject_key);
        $this->assertNull($audit->data);
        $this->assertStringNotContainsString($first, json_encode($audit->toArray()));
        $this->assertStringNotContainsString($row->value, json_encode($audit->toArray()));
        $this->assertStringNotContainsString($first, json_encode($queryBindings));
        Log::shouldHaveReceived('info')->once()->withArgs(fn ($message, $context) => ! str_contains(json_encode([$message, $context]), $first) && ! str_contains(json_encode([$message, $context]), $row->value));

        $this->get(route('owner.integrations.index'))
            ->assertOk()
            ->assertSee(__('owner.integrations.owner_managed'))
            ->assertDontSee($first);

        app(IntegrationCredentials::class)->apply();
        $this->assertSame($first, config('services.stripe.secret'));

        $this->post(route('owner.integrations.store'), ['id' => 'stripe_secret', 'value' => $replacement])
            ->assertRedirect(route('owner.integrations.index'))
            ->assertSessionMissing('_old_input')
            ->assertDontSee($replacement);

        $row = DB::table('integration_credentials')->where('id', 'stripe_secret')->sole();
        $this->assertSame($replacement, Crypt::decryptString($row->value));
        $this->assertSame(1, DB::table('integration_credentials')->where('id', 'stripe_secret')->count());
        app(IntegrationCredentials::class)->apply();
        $this->assertSame($replacement, config('services.stripe.secret'));

        config(['services.stripe.secret' => null]);
        $this->delete(route('owner.integrations.destroy', 'stripe_secret'))
            ->assertRedirect(route('owner.integrations.index'))
            ->assertDontSee($replacement);

        $this->assertDatabaseMissing('integration_credentials', ['id' => 'stripe_secret']);
        $cleared = AdminAuditEvent::where('event', 'integration_credential.cleared')->sole();
        $this->assertSame($owner->id, $cleared->actor_user_id);
        $this->assertSame('stripe_secret', $cleared->subject_key);
        $this->assertNull($cleared->data);
        $this->assertSame(['owner_managed' => false, 'environment_fallback' => false], app(IntegrationCredentials::class)->configured()['stripe_secret']);
    }

    public function test_owner_page_reports_environment_credentials_without_rendering_them(): void
    {
        $secret = 'test-only-'.str_repeat('c', 48);
        config(['services.stripe.secret' => $secret]);
        config(['owner_integration_credentials.environment_fallbacks.stripe_secret' => true]);
        $this->asOwner();

        $this->get(route('owner.integrations.index'))
            ->assertOk()
            ->assertSee(__('owner.integrations.environment_managed'))
            ->assertDontSee($secret);
    }

    public function test_app_key_readiness_shows_only_status_and_never_the_key_or_derived_value(): void
    {
        $this->asOwner();
        $key = str_repeat('APP_KEY_SECRET_MARKER', 2);
        $key = substr($key, 0, 32);
        config(['app.key' => $key]);

        $this->get(route('owner.integrations.index'))
            ->assertOk()
            ->assertSee(__('owner.integrations.app_key.configured'))
            ->assertDontSee($key)
            ->assertDontSee(hash('sha256', $key));

        config(['app.key' => null]);
        $this->get(route('owner.integrations.index'))
            ->assertOk()
            ->assertSee(__('owner.integrations.app_key.missing'))
            ->assertSee(__('owner.integrations.app_key.missing_warning'))
            ->assertDontSee($key);
    }

    public function test_app_key_is_not_an_owner_credential_field_or_audited_value(): void
    {
        $this->asOwner();
        $key = 'base64:APP_KEY_MUST_NOT_BE_ACCEPTED';

        $this->get(route('owner.integrations.index'))->assertOk()
            ->assertDontSee('name="app_key"', false)
            ->assertDontSee($key);
        $this->post(route('owner.integrations.store'), ['id' => 'app_key', 'value' => $key])
            ->assertSessionHasErrors('id');

        $this->assertDatabaseMissing('integration_credentials', ['id' => 'app_key']);
        $this->assertDatabaseMissing('admin_audit_events', ['event' => 'integration_credential.updated']);
        $this->assertStringNotContainsString($key, json_encode(AdminAuditEvent::query()->get()->toArray()));
    }

    public function test_platform_backup_credentials_are_dedicated_encrypted_and_resolved_fresh_for_each_operation(): void
    {
        $this->asOwner();
        config([
            'platform_backup.storage_driver' => 's3',
            'filesystems.disks.platform_backups.driver' => 's3',
            'filesystems.disks.platform_backups.endpoint' => 'https://fsn1.your-objectstorage.com',
            'filesystems.disks.platform_backups.region' => 'fsn1',
            'filesystems.disks.platform_backups.bucket' => 'm9-platform-test-bucket',
            'filesystems.disks.platform_backups.key' => 'platform-env-access',
            'filesystems.disks.platform_backups.secret' => 'platform-env-secret',
            'backup.providers.s3.key' => 'customer-access-must-not-be-used',
            'backup.providers.s3.secret' => 'customer-secret-must-not-be-used',
            'owner_integration_credentials.environment_fallbacks.platform_backup_s3_access_key' => true,
            'owner_integration_credentials.environment_fallbacks.platform_backup_s3_secret_key' => true,
        ]);
        $credentials = app(IntegrationCredentials::class);
        $accessKey = 'm9-platform-owner-access-'.str_repeat('a', 24);
        $secretKey = 'm9-platform-owner-secret-'.str_repeat('b', 24);
        $replacement = 'm9-platform-owner-replacement-'.str_repeat('c', 20);

        $this->get(route('owner.integrations.index'))
            ->assertOk()
            ->assertSee(__('owner.integrations.platform_backup_s3.title'))
            ->assertSee(__('owner.integrations.platform_backup_s3.description'));
        $this->post(route('owner.integrations.store'), ['id' => 'platform_backup_s3_access_key', 'value' => $accessKey])
            ->assertRedirect(route('owner.integrations.index'))
            ->assertDontSee($accessKey);
        $this->post(route('owner.integrations.store'), ['id' => 'platform_backup_s3_secret_key', 'value' => $secretKey])
            ->assertRedirect(route('owner.integrations.index'))
            ->assertDontSee($secretKey);

        foreach ([
            'platform_backup_s3_access_key' => $accessKey,
            'platform_backup_s3_secret_key' => $secretKey,
        ] as $id => $plain) {
            $stored = DB::table('integration_credentials')->where('id', $id)->sole()->value;
            $this->assertNotSame($plain, $stored);
            $this->assertSame($plain, Crypt::decryptString($stored));
            $this->assertStringNotContainsString($plain, (string) $stored);
        }

        // The generic boot-time credential applier must not freeze platform secrets
        // into config; the dedicated resolver reads the current encrypted row.
        $credentials->apply();
        $this->assertSame('platform-env-access', config('filesystems.disks.platform_backups.key'));
        $this->assertSame('platform-env-secret', config('filesystems.disks.platform_backups.secret'));

        $configuration = app(PlatformBackupStorage::class)->configuration();
        $this->assertSame($accessKey, $configuration['key']);
        $this->assertSame($secretKey, $configuration['secret']);
        $this->assertNotSame(config('backup.providers.s3.key'), $configuration['key']);
        $this->assertNotSame(config('backup.providers.s3.secret'), $configuration['secret']);

        $this->post(route('owner.integrations.store'), ['id' => 'platform_backup_s3_access_key', 'value' => $replacement])
            ->assertRedirect(route('owner.integrations.index'))
            ->assertDontSee($replacement);
        $this->assertSame($replacement, app(PlatformBackupStorage::class)->configuration()['key']);
        $this->get(route('owner.integrations.index'))->assertDontSee($accessKey)->assertDontSee($secretKey)->assertDontSee($replacement);

        $this->delete(route('owner.integrations.destroy', 'platform_backup_s3_access_key'))
            ->assertRedirect(route('owner.integrations.index'));
        $this->assertSame('platform-env-access', app(PlatformBackupStorage::class)->configuration()['key']);
        $this->assertSame(['owner_managed' => false, 'environment_fallback' => true], $credentials->configured()['platform_backup_s3_access_key']);
        $this->assertDatabaseHas('admin_audit_events', ['event' => 'integration_credential.cleared', 'subject_key' => 'platform_backup_s3_access_key']);
        $this->assertDatabaseHas('integration_credentials', ['id' => 'platform_backup_s3_secret_key']);
    }

    public function test_customer_s3_credentials_cannot_satisfy_missing_platform_backup_credentials(): void
    {
        config([
            'platform_backup.storage_driver' => 's3',
            'filesystems.disks.platform_backups.driver' => 's3',
            'filesystems.disks.platform_backups.endpoint' => 'https://fsn1.your-objectstorage.com',
            'filesystems.disks.platform_backups.region' => 'fsn1',
            'filesystems.disks.platform_backups.bucket' => 'm9-platform-test-bucket',
            'filesystems.disks.platform_backups.key' => null,
            'filesystems.disks.platform_backups.secret' => null,
            'backup.providers.s3.key' => 'customer-access',
            'backup.providers.s3.secret' => 'customer-secret',
        ]);

        $this->assertFalse(app(PlatformBackupStorage::class)->configured());
        try {
            app(PlatformBackupStorage::class)->configuration();
            $this->fail('Customer backup credentials must not satisfy the platform destination.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Dedicated platform backup S3 credentials are not configured.', $exception->getMessage());
        }
    }

    public function test_smtp_environment_fallback_owner_override_and_clear_use_the_runtime_mailer_config(): void
    {
        $this->asOwner();
        $credentials = app(IntegrationCredentials::class);
        $fallback = 'test-only-'.str_repeat('s', 48);
        $ownerValue = 'test-only-'.str_repeat('t', 48);

        config(['mail.mailers.smtp.password' => $fallback]);
        config(['owner_integration_credentials.environment_fallbacks.smtp_password' => true]);

        $this->assertSame(['owner_managed' => false, 'environment_fallback' => true], $credentials->configured()['smtp_password']);
        $this->get(route('owner.integrations.index'))
            ->assertOk()
            ->assertSee(__('owner.integrations.environment_managed'))
            ->assertSee(__('owner.integrations.environment_fallback_active'))
            ->assertDontSee($fallback);

        $this->post(route('owner.integrations.store'), ['id' => 'smtp_password', 'value' => $ownerValue])
            ->assertRedirect(route('owner.integrations.index'))
            ->assertDontSee($ownerValue);

        $credentials->apply();
        $this->assertSame($ownerValue, config('mail.mailers.smtp.password'));
        $this->assertSame(['owner_managed' => true, 'environment_fallback' => true], $credentials->configured()['smtp_password']);
        $this->get(route('owner.integrations.index'))
            ->assertOk()
            ->assertSee(__('owner.integrations.owner_managed'))
            ->assertSee(__('owner.integrations.environment_fallback_will_remain'))
            ->assertDontSee($fallback)
            ->assertDontSee($ownerValue);

        $this->delete(route('owner.integrations.destroy', 'smtp_password'))
            ->assertRedirect(route('owner.integrations.index'))
            ->assertDontSee($fallback)
            ->assertDontSee($ownerValue);
        $this->assertDatabaseMissing('integration_credentials', ['id' => 'smtp_password']);

        // A new request starts from the environment-derived mailer configuration.
        config(['mail.mailers.smtp.password' => $fallback]);
        $this->assertSame($fallback, config('mail.mailers.smtp.password'));
        $this->assertSame(['owner_managed' => false, 'environment_fallback' => true], $credentials->configured()['smtp_password']);
        $this->get(route('owner.integrations.index'))
            ->assertOk()
            ->assertSee(__('owner.integrations.environment_managed'))
            ->assertSee(__('owner.integrations.environment_fallback_active'))
            ->assertDontSee($fallback)
            ->assertDontSee($ownerValue);
    }

    public function test_smtp_null_and_empty_environment_values_do_not_count_as_fallbacks(): void
    {
        $this->asOwner();
        $credentials = app(IntegrationCredentials::class);

        foreach ([null, ''] as $emptyValue) {
            config(['mail.mailers.smtp.password' => $emptyValue]);
            config(['owner_integration_credentials.environment_fallbacks' => []]);

            $this->assertSame(['owner_managed' => false, 'environment_fallback' => false], $credentials->configured()['smtp_password']);
        }

        $this->get(route('owner.integrations.index'))
            ->assertOk()
            ->assertSee(__('owner.integrations.not_configured'));
    }

    public function test_clearing_owner_value_keeps_environment_fallback_active_and_explicit(): void
    {
        $this->asOwner();
        $fallback = 'test-only-'.str_repeat('g', 48);
        $ownerValue = 'test-only-'.str_repeat('h', 48);
        config(['services.stripe.secret' => $fallback]);
        config(['owner_integration_credentials.environment_fallbacks.stripe_secret' => true]);

        $this->post(route('owner.integrations.store'), ['id' => 'stripe_secret', 'value' => $ownerValue])
            ->assertRedirect(route('owner.integrations.index'));
        app(IntegrationCredentials::class)->apply();

        $this->assertSame($ownerValue, config('services.stripe.secret'));
        $this->assertSame(['owner_managed' => true, 'environment_fallback' => true], app(IntegrationCredentials::class)->configured()['stripe_secret']);
        $this->get(route('owner.integrations.index'))
            ->assertOk()
            ->assertSee(__('owner.integrations.owner_managed'))
            ->assertSee(__('owner.integrations.environment_fallback_will_remain'))
            ->assertSee('fully revoke', false)
            ->assertDontSee($fallback)
            ->assertDontSee($ownerValue);

        $this->delete(route('owner.integrations.destroy', 'stripe_secret'))
            ->assertRedirect(route('owner.integrations.index'))
            ->assertDontSee($fallback)
            ->assertDontSee($ownerValue);
        $this->assertDatabaseMissing('integration_credentials', ['id' => 'stripe_secret']);

        // A new request starts from server configuration, where the environment default remains.
        config(['services.stripe.secret' => $fallback]);
        $this->assertSame($fallback, config('services.stripe.secret'));
        $this->assertSame(['owner_managed' => false, 'environment_fallback' => true], app(IntegrationCredentials::class)->configured()['stripe_secret']);
        $this->get(route('owner.integrations.index'))
            ->assertOk()
            ->assertSee(__('owner.integrations.environment_managed'))
            ->assertSee(__('owner.integrations.environment_fallback_active'))
            ->assertDontSee($fallback)
            ->assertDontSee($ownerValue);
    }

    public function test_postmark_and_resend_are_marked_as_not_operational(): void
    {
        $this->asOwner();

        $this->get(route('owner.integrations.index'))
            ->assertOk()
            ->assertSee(__('owner.integrations.not_configured'))
            ->assertSee(__('owner.integrations.transport_unavailable'));
    }

    public function test_szamlazz_agent_key_is_encrypted_masked_audited_and_does_not_enable_issuance(): void
    {
        $owner = $this->asOwner();
        $secret = 'test_szamlazz_agent_key_1234567890';
        Http::preventStrayRequests();
        Log::spy();

        $this->get(route('owner.integrations.index'))
            ->assertOk()
            ->assertSee('Számlázz.hu — Számla Agent')
            ->assertSee(__('owner.integrations.szamlazz_agent.credential_label'))
            ->assertSee(__('owner.integrations.szamlazz_agent.description'))
            ->assertDontSee('Test connection');

        $this->from(route('owner.integrations.index'))
            ->post(route('owner.integrations.store'), ['id' => 'szamlazz_agent', 'value' => $secret])
            ->assertRedirect(route('owner.integrations.index'))
            ->assertSessionMissing('_old_input')
            ->assertDontSee($secret);

        $row = DB::table('integration_credentials')->where('id', 'szamlazz_agent')->sole();
        $this->assertNotSame($secret, $row->value);
        $this->assertSame($secret, Crypt::decryptString($row->value));
        $audit = AdminAuditEvent::where('event', 'integration_credential.updated')->where('subject_key', 'szamlazz_agent')->sole();
        $this->assertSame($owner->id, $audit->actor_user_id);
        $this->assertAuditMetadataMatches(['provider_id' => 'szamlazz_hu', 'action' => 'created'], $audit->data);
        $this->assertStringNotContainsString($secret, json_encode($audit->toArray()));
        Log::shouldHaveReceived('info')->once()->withArgs(fn ($message, $context) => ! str_contains(json_encode([$message, $context]), $secret));

        $this->get(route('owner.integrations.index'))
            ->assertOk()
            ->assertSee(__('owner.integrations.owner_managed'))
            ->assertSee('••••••••')
            ->assertSee(__('owner.integrations.szamlazz_agent.masked'))
            ->assertSee(__('owner.integrations.szamlazz_agent.replace'))
            ->assertDontSee($secret);

        app(IntegrationCredentials::class)->apply();
        $this->assertSame($secret, config('szamlazz.agent_api_key'));
        $this->assertSame('szamlazz_hu', config('szamlazz.provider'));
        $this->assertFalse((bool) config('szamlazz.provider_enabled'));
        $this->assertTrue((bool) config('szamlazz.dry_run'));
        $this->assertFalse((bool) config('szamlazz.issuance_enabled'));

        $replacement = 'test_szamlazz_agent_replacement_9876543210';
        $this->post(route('owner.integrations.store'), ['id' => 'szamlazz_agent', 'value' => $replacement])
            ->assertRedirect(route('owner.integrations.index'))
            ->assertDontSee($replacement);
        $this->assertSame($replacement, Crypt::decryptString(DB::table('integration_credentials')->where('id', 'szamlazz_agent')->sole()->value));
        $replaceAudit = AdminAuditEvent::where('event', 'integration_credential.updated')->where('subject_key', 'szamlazz_agent')->latest('id')->firstOrFail();
        $this->assertAuditMetadataMatches(['provider_id' => 'szamlazz_hu', 'action' => 'replaced'], $replaceAudit->data);
        $this->assertStringNotContainsString($replacement, json_encode($replaceAudit->toArray()));

        $this->delete(route('owner.integrations.destroy', 'szamlazz_agent'))
            ->assertRedirect(route('owner.integrations.index'))
            ->assertDontSee($replacement);
        $this->assertDatabaseMissing('integration_credentials', ['id' => 'szamlazz_agent']);
        $deleteAudit = AdminAuditEvent::where('event', 'integration_credential.cleared')->where('subject_key', 'szamlazz_agent')->sole();
        $this->assertAuditMetadataMatches(['provider_id' => 'szamlazz_hu', 'action' => 'deleted'], $deleteAudit->data);
        $this->assertStringNotContainsString($replacement, json_encode($deleteAudit->toArray()));
    }

    public function test_szamlazz_agent_key_must_not_be_empty(): void
    {
        $this->asOwner();

        $this->from(route('owner.integrations.index'))
            ->post(route('owner.integrations.store'), ['id' => 'szamlazz_agent', 'value' => ''])
            ->assertRedirect(route('owner.integrations.index'))
            ->assertSessionHasErrors('value')
            ->assertSessionMissing('_old_input');

        $this->assertDatabaseMissing('integration_credentials', ['id' => 'szamlazz_agent']);
        $this->assertDatabaseMissing('admin_audit_events', ['subject_key' => 'szamlazz_agent']);
    }

    /** Compare JSON metadata as an object: preserve exact keys/values, ignore key order. */
    private function assertAuditMetadataMatches(array $expected, mixed $actual): void
    {
        $this->assertIsArray($actual);
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    public function test_all_registered_credentials_are_applied_to_their_runtime_config_paths(): void
    {
        $credentials = app(IntegrationCredentials::class);
        $expected = [];

        foreach (IntegrationCredentials::FIELDS as $id => $field) {
            $value = 'test-only-'.str_pad((string) count($expected), 48, 'x');
            $credentials->store($id, $value);
            if ($field['runtime_resolved'] ?? false) {
                continue;
            }
            foreach ($field['config'] as $path) {
                $expected[$path] = $value;
            }
        }

        $credentials->apply();

        foreach ($expected as $path => $value) {
            $this->assertSame($value, config($path), $path);
        }
    }

    public function test_customer_and_operator_cannot_read_or_change_integration_credentials(): void
    {
        $secret = 'test-only-'.str_repeat('d', 48);
        $customer = Customer::factory()->withUser()->create()->user;
        $operator = User::factory()->operator(Permission::cases())->create();

        foreach ([$customer, $operator] as $user) {
            $this->actingAs($user, 'owner');
            $this->get(route('owner.integrations.index'))->assertRedirect(route('owner.login'));
            $this->post(route('owner.integrations.store'), ['id' => 'stripe_secret', 'value' => $secret])->assertRedirect(route('owner.login'));
            $this->post(route('owner.integrations.store'), ['id' => 'platform_backup_s3_secret_key', 'value' => $secret])->assertRedirect(route('owner.login'));
            $this->delete(route('owner.integrations.destroy', 'stripe_secret'))->assertRedirect(route('owner.login'));
            $this->delete(route('owner.integrations.destroy', 'platform_backup_s3_secret_key'))->assertRedirect(route('owner.login'));
            $this->assertDatabaseMissing('integration_credentials', ['id' => 'stripe_secret']);
            $this->assertDatabaseMissing('integration_credentials', ['id' => 'platform_backup_s3_secret_key']);
            auth('owner')->logout();
        }
    }

    public function test_invalid_credentials_are_not_flashed_or_saved(): void
    {
        $this->asOwner();
        $secret = 'test-only-'.str_repeat('e', 48);

        $this->from(route('owner.integrations.index'))
            ->post(route('owner.integrations.store'), ['id' => 'unknown-integration', 'value' => $secret])
            ->assertRedirect(route('owner.integrations.index'))
            ->assertSessionHasErrors('id')
            ->assertSessionMissing('_old_input')
            ->assertDontSee($secret);

        $this->assertDatabaseMissing('integration_credentials', ['id' => 'unknown-integration']);
    }

    public function test_credential_changes_are_csrf_protected(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->asOwner();

        $this->post(route('owner.integrations.store'), ['id' => 'stripe_secret', 'value' => 'test-only-'.str_repeat('f', 48)])->assertStatus(419);
        $this->delete(route('owner.integrations.destroy', 'stripe_secret'))->assertStatus(419);
        $this->assertDatabaseMissing('integration_credentials', ['id' => 'stripe_secret']);
    }
}
