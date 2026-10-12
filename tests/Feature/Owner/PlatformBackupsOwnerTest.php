<?php

namespace Tests\Feature\Owner;

use App\Jobs\PlatformDatabaseBackupJob;
use App\Models\AdminAuditEvent;
use App\Models\Customer;
use App\Models\PlatformDatabaseBackup;
use App\Models\User;
use App\Operator\Permission;
use App\Owner\OwnerSettings;
use App\Platform\DisasterRecovery\PlatformBackupS3BucketProber;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

final class PlatformBackupsOwnerTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    public function test_owner_backup_overview_reports_configuration_without_exposing_secrets(): void
    {
        config([
            'platform_backup.storage_driver' => 's3',
            'filesystems.disks.platform_backups.driver' => 's3',
            'filesystems.disks.platform_backups.endpoint' => 'https://fsn1.your-objectstorage.com',
            'filesystems.disks.platform_backups.region' => 'fsn1',
            'filesystems.disks.platform_backups.bucket' => 'platform-private-bucket',
            'filesystems.disks.platform_backups.key' => 'PLATFORM_ACCESS_SECRET_MARKER',
            'filesystems.disks.platform_backups.secret' => 'PLATFORM_SECRET_SECRET_MARKER',
            'owner_integration_credentials.environment_fallbacks.platform_backup_s3_access_key' => true,
            'owner_integration_credentials.environment_fallbacks.platform_backup_s3_secret_key' => true,
            'platform_backup.encryption.current_key_id' => 'test-v1',
            'platform_backup.encryption.keys.test-v1' => base64_encode(str_repeat('x', 32)),
            'platform_backup.verification_connection' => 'm9_platform_verify',
            'platform_backup.verification_database_prefix' => 'wpcg_m9_dr_test_',
            'platform_backup.verification_isolated' => true,
            'database.connections.m9_platform_verify' => array_merge(config('database.connections.mysql'), [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'information_schema',
                'username' => 'm9_verify_test', 'password' => 'test-only-verifier-password',
            ]),
        ]);
        $this->asOwner();

        $response = $this->get(route('owner.platform-backups.index'))->assertOk()
            ->assertSee('Platform Backups')->assertSee('Stale')
            ->assertSee(__('owner.platform_backups.connection_not_tested'))
            ->assertSee(__('owner.platform_backups.connection_explainer'))
            ->assertDontSee(__('owner.platform_backups.connection_passed'));

        $response->assertDontSee('PLATFORM_ACCESS_SECRET_MARKER', false)
            ->assertDontSee('PLATFORM_SECRET_SECRET_MARKER', false)
            ->assertDontSee(base64_encode(str_repeat('x', 32)), false);
    }

    public function test_non_owner_cannot_open_platform_backup_management(): void
    {
        $this->customerWithWebsite();

        $this->get(route('owner.platform-backups.index'))->assertRedirect(route('owner.login'));
    }

    public function test_only_owner_can_run_platform_s3_connection_probe(): void
    {
        $this->mock(PlatformBackupS3BucketProber::class)->shouldNotReceive('headBucket');
        $this->post(route('owner.platform-backups.test-connection'))->assertRedirect(route('owner.login'));
        $customer = Customer::factory()->withUser()->create()->user;
        $operator = User::factory()->operator(Permission::cases())->create();

        foreach ([$customer, $operator] as $user) {
            $this->actingAs($user, 'owner')
                ->post(route('owner.platform-backups.test-connection'))
                ->assertRedirect(route('owner.login'));
            auth('owner')->logout();
        }

        $this->assertDatabaseMissing('admin_audit_events', ['event' => 'platform_backup.s3_connection_tested']);
    }

    public function test_platform_s3_connection_probe_remains_csrf_protected(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->asOwner();

        $this->post(route('owner.platform-backups.test-connection'))->assertStatus(419);
        $this->assertDatabaseMissing('admin_audit_events', ['event' => 'platform_backup.s3_connection_tested']);
    }

    public function test_owner_can_configure_dedicated_s3_destination_without_secrets_and_run_read_only_connection_probe(): void
    {
        $owner = $this->asOwner();
        config([
            'platform.deployment_environment' => 'staging',
            'platform_backup.storage_driver' => 's3',
            'filesystems.disks.platform_backups.driver' => 's3',
            'filesystems.disks.platform_backups.endpoint' => null,
            'filesystems.disks.platform_backups.region' => 'us-east-1',
            'filesystems.disks.platform_backups.bucket' => null,
            'filesystems.disks.platform_backups.key' => 'env-platform-access-secret',
            'filesystems.disks.platform_backups.secret' => 'env-platform-secret-value',
            'platform_backup.encryption.current_key_id' => 'test-v1',
            'platform_backup.encryption.keys.test-v1' => base64_encode(str_repeat('x', 32)),
            'platform_backup.verification_connection' => 'm9_platform_verify',
            'platform_backup.verification_database_prefix' => 'wpcg_m9_dr_test_',
            'platform_backup.verification_isolated' => true,
            'database.connections.m9_platform_verify' => array_merge(config('database.connections.mysql'), [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'information_schema',
                'username' => 'm9_verify_test', 'password' => 'test-only-verifier-password',
            ]),
            'owner_integration_credentials.environment_fallbacks.platform_backup_s3_access_key' => true,
            'owner_integration_credentials.environment_fallbacks.platform_backup_s3_secret_key' => true,
        ]);
        app(OwnerSettings::class)->updateGroup('backups', [
            'backups.primary.enabled' => false,
            'backups.primary.frequency' => '12h',
            'backups.primary.storage_driver' => 's3',
            'backups.primary.endpoint' => 'https://fsn1.your-objectstorage.com',
            'backups.primary.region' => 'fsn1',
            'backups.primary.bucket' => 'm9-platform-private-test',
            'backups.primary.path_style' => true,
            'backups.retention.daily' => 7,
            'backups.retention.weekly' => 4,
            'backups.retention.monthly' => 12,
            'backups.secondary.enabled' => false,
            'backups.secondary.frequency' => 'daily',
            'backups.app_key_escrow_attested' => false,
        ], $owner);

        $probe = $this->mock(PlatformBackupS3BucketProber::class);
        $probe->shouldReceive('headBucket')->once()->with(Mockery::on(function (array $configuration): bool {
            $this->assertSame('m9-platform-private-test', $configuration['bucket']);
            $this->assertSame('fsn1', $configuration['region']);
            $this->assertSame('https://fsn1.your-objectstorage.com', $configuration['endpoint']);
            $this->assertTrue($configuration['use_path_style_endpoint']);
            $this->assertSame('env-platform-access-secret', $configuration['key']);
            $this->assertSame('env-platform-secret-value', $configuration['secret']);

            return true;
        }))->andReturnNull();

        $this->post(route('owner.platform-backups.test-connection'))
            ->assertRedirect(route('owner.platform-backups.index'))
            ->assertSessionHas('status', __('owner.platform_backups.connection_passed'));
        $audit = AdminAuditEvent::query()->where('event', 'platform_backup.s3_connection_tested')->sole();
        $this->assertSame($owner->id, $audit->actor_user_id);
        $this->assertSame('passed', $audit->data['result']);
        $this->assertSame('m9-platform-private-test', $audit->data['bucket']);
        $this->assertStringNotContainsString('env-platform-access-secret', json_encode($audit->toArray()));
        $this->assertStringNotContainsString('env-platform-secret-value', json_encode($audit->toArray()));

        $this->get(route('owner.platform-backups.index'))
            ->assertOk()
            ->assertSee('m9-platform-private-test')
            ->assertDontSee('env-platform-access-secret')
            ->assertDontSee('env-platform-secret-value')
            ->assertSee(__('owner.platform_backups.connection_result_passed'));

        app(OwnerSettings::class)->updateGroup('backups', [
            'backups.primary.enabled' => false,
            'backups.primary.frequency' => '12h',
            'backups.primary.storage_driver' => 's3',
            'backups.primary.endpoint' => 'https://fsn1.your-objectstorage.com',
            'backups.primary.region' => 'fsn1',
            'backups.primary.bucket' => 'm9-platform-another-test-bucket',
            'backups.primary.path_style' => true,
            'backups.retention.daily' => 7,
            'backups.retention.weekly' => 4,
            'backups.retention.monthly' => 12,
            'backups.secondary.enabled' => false,
            'backups.secondary.frequency' => 'daily',
            'backups.app_key_escrow_attested' => false,
        ], $owner);
        $this->get(route('owner.platform-backups.index'))
            ->assertOk()
            ->assertSee(__('owner.platform_backups.connection_outdated'));
    }

    public function test_provider_probe_is_available_without_artifact_encryption_key_while_backup_remains_blocked(): void
    {
        Queue::fake();
        $owner = $this->asOwner();
        config([
            'platform.deployment_environment' => 'staging',
            'filesystems.disks.platform_backups.driver' => 's3',
            'filesystems.disks.platform_backups.key' => 'platform-access-marker',
            'filesystems.disks.platform_backups.secret' => 'platform-secret-marker',
            'owner_integration_credentials.environment_fallbacks.platform_backup_s3_access_key' => true,
            'owner_integration_credentials.environment_fallbacks.platform_backup_s3_secret_key' => true,
            'backup.providers.s3.key' => null,
            'backup.providers.s3.secret' => null,
            'platform_backup.encryption.current_key_id' => '',
            'platform_backup.encryption.keys' => [],
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.queue' => 'default',
        ]);
        $this->saveS3Destination($owner, enabled: true);

        $response = $this->get(route('owner.platform-backups.index'))
            ->assertOk()
            ->assertSee('id="platform-backup-connection-test"', false)
            ->assertSee(__('owner.platform_backups.encryption_key_missing'))
            ->assertSee(__('owner.platform_backups.states.encryption_key_missing'))
            ->assertSee(__('owner.platform_backups.states.configured'))
            ->assertSee('id="platform-backup-request"', false);
        $html = $response->getContent();
        $this->assertDoesNotMatchRegularExpression('/<button\b[^>]*id="platform-backup-connection-test"[^>]*\sdisabled(?:\s|>)/', $html);
        $this->assertMatchesRegularExpression('/<button\b[^>]*id="platform-backup-request"[^>]*\sdisabled(?:\s|>)/', $html);

        $probe = $this->mock(PlatformBackupS3BucketProber::class);
        $probe->shouldReceive('headBucket')->once()->with(Mockery::on(function (array $configuration): bool {
            $this->assertSame('m9-platform-provider-test', $configuration['bucket']);
            $this->assertSame('platform-access-marker', $configuration['key']);
            $this->assertSame('platform-secret-marker', $configuration['secret']);

            return true;
        }));
        $this->post(route('owner.platform-backups.test-connection'))
            ->assertRedirect(route('owner.platform-backups.index'))
            ->assertSessionHas('status', __('owner.platform_backups.connection_passed'));
        $this->assertDatabaseHas('admin_audit_events', ['event' => 'platform_backup.s3_connection_tested']);

        $this->post(route('owner.platform-backups.run'), ['confirmation' => 'RUN PLATFORM BACKUP'])->assertStatus(409);
        Queue::assertNothingPushed();
    }

    #[DataProvider('missingProviderPrerequisites')]
    public function test_provider_probe_button_is_disabled_when_its_own_configuration_is_incomplete(string $missing): void
    {
        $owner = $this->asOwner();
        config([
            'filesystems.disks.platform_backups.driver' => 's3',
            'filesystems.disks.platform_backups.key' => 'platform-access-marker',
            'filesystems.disks.platform_backups.secret' => 'platform-secret-marker',
            'owner_integration_credentials.environment_fallbacks.platform_backup_s3_access_key' => true,
            'owner_integration_credentials.environment_fallbacks.platform_backup_s3_secret_key' => true,
        ]);
        $settings = [
            'backups.primary.enabled' => false,
            'backups.primary.frequency' => '12h',
            'backups.primary.storage_driver' => 's3',
            'backups.primary.endpoint' => 'https://fsn1.your-objectstorage.com',
            'backups.primary.region' => 'fsn1',
            'backups.primary.bucket' => 'm9-platform-provider-test',
            'backups.primary.path_style' => false,
            'backups.retention.daily' => 7,
            'backups.retention.weekly' => 4,
            'backups.retention.monthly' => 12,
            'backups.secondary.enabled' => false,
            'backups.secondary.frequency' => 'daily',
            'backups.app_key_escrow_attested' => false,
        ];
        if ($missing === 'endpoint') {
            $settings['backups.primary.endpoint'] = null;
        } elseif ($missing === 'bucket') {
            $settings['backups.primary.bucket'] = null;
        } elseif ($missing === 'driver') {
            $settings['backups.primary.storage_driver'] = 'local';
        } elseif ($missing === 'access_key') {
            config(['filesystems.disks.platform_backups.key' => null]);
        } elseif ($missing === 'secret_key') {
            config(['filesystems.disks.platform_backups.secret' => null]);
        }
        app(OwnerSettings::class)->updateGroup('backups', $settings, $owner);
        if ($missing === 'region') {
            DB::table('owner_settings')->where('id', 'backups.primary.region')->update(['value' => json_encode('')]);
            app()->forgetInstance(OwnerSettings::class);
        }

        $response = $this->get(route('owner.platform-backups.index'))
            ->assertOk()
            ->assertSee(__('owner.platform_backups.connection_not_configured'));
        $this->assertMatchesRegularExpression('/<button\b[^>]*id="platform-backup-connection-test"[^>]*\sdisabled(?:\s|>)/', $response->getContent());
    }

    public static function missingProviderPrerequisites(): array
    {
        return [
            'endpoint' => ['endpoint'],
            'region' => ['region'],
            'bucket' => ['bucket'],
            'driver' => ['driver'],
            'access key' => ['access_key'],
            'secret key' => ['secret_key'],
        ];
    }

    private function saveS3Destination(User $owner, bool $enabled): void
    {
        app(OwnerSettings::class)->updateGroup('backups', [
            'backups.primary.enabled' => $enabled,
            'backups.primary.frequency' => '12h',
            'backups.primary.storage_driver' => 's3',
            'backups.primary.endpoint' => 'https://fsn1.your-objectstorage.com',
            'backups.primary.region' => 'fsn1',
            'backups.primary.bucket' => 'm9-platform-provider-test',
            'backups.primary.path_style' => false,
            'backups.retention.daily' => 7,
            'backups.retention.weekly' => 4,
            'backups.retention.monthly' => 12,
            'backups.secondary.enabled' => false,
            'backups.secondary.frequency' => 'daily',
            'backups.app_key_escrow_attested' => true,
        ], $owner);
    }

    public function test_failed_s3_probe_is_audited_without_provider_message_or_credentials(): void
    {
        $owner = $this->asOwner();
        config([
            'platform.deployment_environment' => 'staging',
            'filesystems.disks.platform_backups.key' => 'probe-access-secret',
            'filesystems.disks.platform_backups.secret' => 'probe-secret-value',
            'owner_integration_credentials.environment_fallbacks.platform_backup_s3_access_key' => true,
            'owner_integration_credentials.environment_fallbacks.platform_backup_s3_secret_key' => true,
        ]);
        app(OwnerSettings::class)->updateGroup('backups', [
            'backups.primary.enabled' => false,
            'backups.primary.frequency' => '12h',
            'backups.primary.storage_driver' => 's3',
            'backups.primary.endpoint' => 'https://fsn1.your-objectstorage.com',
            'backups.primary.region' => 'fsn1',
            'backups.primary.bucket' => 'm9-platform-private-test',
            'backups.primary.path_style' => true,
            'backups.retention.daily' => 7,
            'backups.retention.weekly' => 4,
            'backups.retention.monthly' => 12,
            'backups.secondary.enabled' => false,
            'backups.secondary.frequency' => 'daily',
            'backups.app_key_escrow_attested' => false,
        ], $owner);
        $providerMessage = 'probe-access-secret probe-secret-value provider-detail-marker';
        Log::spy();

        $probe = $this->mock(PlatformBackupS3BucketProber::class);
        $probe->shouldReceive('headBucket')->once()->andThrow(new \RuntimeException($providerMessage));

        $this->post(route('owner.platform-backups.test-connection'))
            ->assertRedirect(route('owner.platform-backups.index'))
            ->assertSessionHas('error', __('owner.platform_backups.connection_failed'));
        $audit = AdminAuditEvent::query()->where('event', 'platform_backup.s3_connection_tested')->sole();
        $this->assertSame($owner->id, $audit->actor_user_id);
        $this->assertSame('failed', $audit->data['result']);
        $this->assertSame('RuntimeException', $audit->data['error_code']);
        $this->assertStringNotContainsString($providerMessage, json_encode($audit->toArray()));
        Log::shouldHaveReceived('info')->once()->withArgs(fn ($message, $context) => ! str_contains(json_encode([$message, $context]), $providerMessage));
    }

    public function test_staging_rejects_private_or_non_hetzner_s3_endpoint(): void
    {
        config(['platform.deployment_environment' => 'staging']);
        $settings = app(OwnerSettings::class);
        $input = [
            'backups.primary.enabled' => false,
            'backups.primary.frequency' => '12h',
            'backups.primary.storage_driver' => 's3',
            'backups.primary.endpoint' => 'https://127.0.0.1',
            'backups.primary.region' => 'fsn1',
            'backups.primary.bucket' => 'm9-platform-private-test',
            'backups.primary.path_style' => true,
            'backups.retention.daily' => 7,
            'backups.retention.weekly' => 4,
            'backups.retention.monthly' => 12,
            'backups.secondary.enabled' => false,
            'backups.secondary.frequency' => 'daily',
            'backups.app_key_escrow_attested' => false,
        ];

        $this->expectException(ValidationException::class);
        $settings->validateGroup('backups', $input);
    }

    public function test_destination_cannot_change_while_platform_backup_schedule_is_enabled(): void
    {
        config(['platform.deployment_environment' => 'staging']);
        $owner = $this->asOwner();
        $settings = app(OwnerSettings::class);
        $current = [
            'backups.primary.enabled' => true,
            'backups.primary.frequency' => '12h',
            'backups.primary.storage_driver' => 's3',
            'backups.primary.endpoint' => 'https://fsn1.your-objectstorage.com',
            'backups.primary.region' => 'fsn1',
            'backups.primary.bucket' => 'm9-platform-current-bucket',
            'backups.primary.path_style' => true,
            'backups.retention.daily' => 7,
            'backups.retention.weekly' => 4,
            'backups.retention.monthly' => 12,
            'backups.secondary.enabled' => false,
            'backups.secondary.frequency' => 'daily',
            'backups.app_key_escrow_attested' => false,
        ];
        $settings->updateGroup('backups', $current, $owner);
        $changed = $current;
        $changed['backups.primary.bucket'] = 'm9-platform-new-bucket';

        $this->from(route('owner.platform-backups.index'))
            ->put(route('owner.platform-backups.settings.update'), ['settings' => $changed])
            ->assertRedirect(route('owner.platform-backups.index'))
            ->assertSessionHasErrors('settings');
        $this->assertSame('m9-platform-current-bucket', $settings->get('backups.primary.bucket'));
    }

    public function test_manual_backup_requires_configuration_confirmation_and_uses_unique_queued_job(): void
    {
        Queue::fake();
        config([
            // Simulate a worker/web process whose cached config is stale.
            'platform_backup.enabled' => false,
            'platform_backup.storage_driver' => 's3',
            'filesystems.disks.platform_backups.driver' => 's3',
            'filesystems.disks.platform_backups.endpoint' => 'https://fsn1.your-objectstorage.com',
            'filesystems.disks.platform_backups.region' => 'fsn1',
            'filesystems.disks.platform_backups.bucket' => 'platform-private-bucket',
            'filesystems.disks.platform_backups.key' => 'test-key',
            'filesystems.disks.platform_backups.secret' => 'test-secret',
            'platform_backup.encryption.current_key_id' => 'test-v1',
            'platform_backup.encryption.keys.test-v1' => base64_encode(str_repeat('x', 32)),
            'platform_backup.verification_connection' => 'm9_platform_verify',
            'platform_backup.verification_database_prefix' => 'wpcg_m9_dr_test_',
            'platform_backup.verification_isolated' => true,
            'database.connections.m9_platform_verify' => array_merge(config('database.connections.mysql'), [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'information_schema',
                'username' => 'm9_verify_test', 'password' => 'test-only-verifier-password',
            ]),
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.queue' => 'default',
        ]);
        $this->asOwner();
        app(OwnerSettings::class)->updateGroup('backups', [
            'backups.primary.enabled' => true,
            'backups.primary.frequency' => '12h',
            'backups.retention.daily' => 7,
            'backups.retention.weekly' => 4,
            'backups.retention.monthly' => 12,
            'backups.secondary.enabled' => false,
            'backups.secondary.frequency' => 'daily',
            'backups.app_key_escrow_attested' => false,
        ], auth('owner')->user());

        $this->post(route('owner.platform-backups.run'), ['confirmation' => 'wrong'])->assertStatus(422);
        $this->post(route('owner.platform-backups.run'), ['confirmation' => 'RUN PLATFORM BACKUP'])->assertRedirect(route('owner.platform-backups.index'));

        Queue::assertPushed(PlatformDatabaseBackupJob::class, 1);
        $this->assertDatabaseHas('admin_audit_events', ['event' => 'platform_backup.manual_requested']);
    }

    public function test_manual_backup_is_allowed_without_enabling_recurring_schedule(): void
    {
        Queue::fake();
        config([
            'platform_backup.enabled' => true,
            'platform_backup.storage_driver' => 's3',
            'filesystems.disks.platform_backups.driver' => 's3',
            'filesystems.disks.platform_backups.endpoint' => 'https://fsn1.your-objectstorage.com',
            'filesystems.disks.platform_backups.region' => 'fsn1',
            'filesystems.disks.platform_backups.bucket' => 'platform-private-bucket',
            'filesystems.disks.platform_backups.key' => 'test-key',
            'filesystems.disks.platform_backups.secret' => 'test-secret',
            'platform_backup.encryption.current_key_id' => 'test-v1',
            'platform_backup.encryption.keys.test-v1' => base64_encode(str_repeat('x', 32)),
            'platform_backup.verification_connection' => 'm9_platform_verify',
            'platform_backup.verification_database_prefix' => 'wpcg_m9_dr_test_',
            'platform_backup.verification_isolated' => true,
            'database.connections.m9_platform_verify' => array_merge(config('database.connections.mysql'), [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'information_schema',
                'username' => 'm9_verify_test', 'password' => 'test-only-verifier-password',
            ]),
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.queue' => 'default',
        ]);
        $this->asOwner();
        app(OwnerSettings::class)->updateGroup('backups', [
            'backups.primary.enabled' => false,
            'backups.primary.frequency' => '12h',
            'backups.retention.daily' => 7,
            'backups.retention.weekly' => 4,
            'backups.retention.monthly' => 12,
            'backups.secondary.enabled' => false,
            'backups.secondary.frequency' => 'daily',
            'backups.app_key_escrow_attested' => false,
        ], auth('owner')->user());
        $this->assertFalse(app(OwnerSettings::class)->get('backups.primary.enabled'));

        $this->post(route('owner.platform-backups.run'), ['confirmation' => 'RUN PLATFORM BACKUP'])
            ->assertRedirect(route('owner.platform-backups.index'));
        Queue::assertPushed(PlatformDatabaseBackupJob::class, 1);
        $this->assertFalse(app(OwnerSettings::class)->get('backups.primary.enabled'));
        $this->assertDatabaseHas('admin_audit_events', ['event' => 'platform_backup.manual_requested']);
    }

    public function test_manual_backup_is_blocked_when_disposable_mysql_verifier_is_not_configured(): void
    {
        Queue::fake();
        config([
            'platform_backup.storage_driver' => 's3',
            'filesystems.disks.platform_backups.driver' => 's3',
            'filesystems.disks.platform_backups.endpoint' => 'https://fsn1.your-objectstorage.com',
            'filesystems.disks.platform_backups.region' => 'fsn1',
            'filesystems.disks.platform_backups.bucket' => 'platform-private-bucket',
            'filesystems.disks.platform_backups.key' => 'test-access',
            'filesystems.disks.platform_backups.secret' => 'test-secret',
            'platform_backup.encryption.current_key_id' => 'test-v1',
            'platform_backup.encryption.keys.test-v1' => base64_encode(str_repeat('x', 32)),
            'platform_backup.verification_connection' => null,
            'platform_backup.verification_database_prefix' => '',
            'platform_backup.verification_isolated' => false,
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.queue' => 'default',
        ]);
        $this->asOwner();

        $this->get(route('owner.platform-backups.index'))->assertOk()->assertSee(__('owner.platform_backups.states.verification_not_configured'));
        $this->post(route('owner.platform-backups.run'), ['confirmation' => 'RUN PLATFORM BACKUP'])->assertStatus(409);
        Queue::assertNothingPushed();
    }

    public function test_manual_backup_is_blocked_when_verifier_reuses_the_application_database_identity(): void
    {
        Queue::fake();
        config([
            'platform_backup.storage_driver' => 's3',
            'platform_backup.source_connection' => 'mysql',
            'database.connections.mysql.username' => 'shared-user',
            'filesystems.disks.platform_backups.driver' => 's3',
            'filesystems.disks.platform_backups.endpoint' => 'https://fsn1.your-objectstorage.com',
            'filesystems.disks.platform_backups.region' => 'fsn1',
            'filesystems.disks.platform_backups.bucket' => 'platform-private-bucket',
            'filesystems.disks.platform_backups.key' => 'test-access',
            'filesystems.disks.platform_backups.secret' => 'test-secret',
            'platform_backup.encryption.current_key_id' => 'test-v1',
            'platform_backup.encryption.keys.test-v1' => base64_encode(str_repeat('x', 32)),
            'platform_backup.verification_connection' => 'm9_platform_verify',
            'platform_backup.verification_database_prefix' => 'wpcg_m9_dr_test_',
            'platform_backup.verification_isolated' => true,
            'database.connections.m9_platform_verify' => array_merge(config('database.connections.mysql'), [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'information_schema',
                'username' => 'shared-user', 'password' => 'test-only-verifier-password',
            ]),
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.queue' => 'default',
        ]);
        $this->asOwner();

        $this->get(route('owner.platform-backups.index'))
            ->assertOk()
            ->assertSee(__('owner.platform_backups.states.verification_not_configured'));
        $this->post(route('owner.platform-backups.run'), ['confirmation' => 'RUN PLATFORM BACKUP'])->assertStatus(409);
        Queue::assertNothingPushed();
    }

    public function test_disabling_primary_schedule_requires_confirmation_and_keeps_recovery_points(): void
    {
        $this->asOwner();
        app(OwnerSettings::class)->updateGroup('backups', [
            'backups.primary.enabled' => true,
            'backups.primary.frequency' => '12h',
            'backups.retention.daily' => 7,
            'backups.retention.weekly' => 4,
            'backups.retention.monthly' => 12,
            'backups.secondary.enabled' => false,
            'backups.secondary.frequency' => 'daily',
            'backups.app_key_escrow_attested' => false,
        ], auth('owner')->user());
        $backup = PlatformDatabaseBackup::query()->create([
            'uuid' => (string) str()->uuid(), 'source_environment' => 'testing', 'database_identity_sha256' => str_repeat('a', 64),
            'database_driver' => 'sqlite', 'retention_class' => 'daily', 'status' => 'verified', 'verification_status' => 'verified',
            'storage_disk' => 'platform_backups', 'object_key' => 'platform-database/test.sqlite.gz.enc',
            'manifest_object_key' => 'platform-database/test.sqlite.gz.enc.manifest.json', 'size_bytes' => 100, 'sha256' => str_repeat('b', 64),
            'created_for_backup_at' => now(), 'verified_at' => now(),
        ]);
        $data = ['settings' => [
            'backups.primary.enabled' => '0', 'backups.primary.frequency' => '12h',
            'backups.retention.daily' => '7', 'backups.retention.weekly' => '4', 'backups.retention.monthly' => '12',
            'backups.secondary.enabled' => '0', 'backups.secondary.frequency' => 'daily', 'backups.app_key_escrow_attested' => '0',
        ]];

        $this->put(route('owner.platform-backups.settings.update'), $data)->assertStatus(422);
        $data['confirmation'] = 'DISABLE PLATFORM BACKUPS';
        $data['warning_ack'] = '1';
        $this->put(route('owner.platform-backups.settings.update'), $data)->assertRedirect(route('owner.platform-backups.index'));

        $this->assertSame('verified', $backup->fresh()->status);
        $this->assertDatabaseHas('admin_audit_events', ['event' => 'platform_backup.disabled']);
    }

    public function test_app_key_escrow_requires_existing_key_and_records_owner_and_timestamp(): void
    {
        $owner = $this->asOwner();
        $settings = $this->validBackupSettings(false);
        // Resolve the test session encrypter before simulating a missing
        // runtime config value; the escrow policy must still refuse attestation.
        app('encrypter');
        config(['app.key' => null]);

        $this->put(route('owner.platform-backups.settings.update'), [
            'settings' => [...$settings, 'backups.app_key_escrow_attested' => true],
            'confirmation' => 'APP KEY ESCROW CONFIRMED',
        ])->assertStatus(422);
        $this->assertDatabaseMissing('admin_audit_events', ['event' => 'platform_backup.escrow_attested']);
        $this->assertFalse((bool) app(OwnerSettings::class)->get('backups.app_key_escrow_attested'));

        $key = str_repeat('K', 32);
        config(['app.key' => $key]);
        $this->put(route('owner.platform-backups.settings.update'), [
            'settings' => [...$settings, 'backups.app_key_escrow_attested' => true],
            'confirmation' => 'APP KEY ESCROW CONFIRMED',
        ])->assertRedirect(route('owner.platform-backups.index'));

        $event = AdminAuditEvent::query()->where('event', 'platform_backup.escrow_attested')->sole();
        $this->assertSame($key, config('app.key'));
        $this->assertSame($owner->id, $event->actor_user_id);
        $this->assertNotNull($event->created_at);
        $this->assertSame(['attested' => true], $event->data);
        $this->assertStringNotContainsString($key, json_encode($event->toArray()));
        $this->get(route('owner.platform-backups.index'))
            ->assertOk()
            ->assertSee(__('owner.platform_backups.escrow_verified_by', ['owner' => $owner->id, 'at' => $event->created_at->toIso8601String()]))
            ->assertDontSee($key);

        $this->put(route('owner.platform-backups.settings.update'), [
            'settings' => [...$settings, 'backups.app_key_escrow_attested' => false],
        ])->assertRedirect(route('owner.platform-backups.index'));
        $revoked = AdminAuditEvent::query()->where('event', 'platform_backup.escrow_revoked')->sole();
        $this->assertSame($owner->id, $revoked->actor_user_id);
        $this->assertFalse((bool) app(OwnerSettings::class)->get('backups.app_key_escrow_attested'));
    }

    public function test_customer_and_operator_cannot_attest_app_key_escrow(): void
    {
        config(['app.key' => str_repeat('N', 32)]);
        $settings = $this->validBackupSettings(false);
        $this->put(route('owner.platform-backups.settings.update'), [
            'settings' => [...$settings, 'backups.app_key_escrow_attested' => true],
            'confirmation' => 'APP KEY ESCROW CONFIRMED',
        ])->assertRedirect(route('owner.login'));
        $customer = $this->customerWithWebsite()['customer']->user;
        $operator = User::factory()->operator(Permission::cases())->create();

        foreach ([$customer, $operator] as $user) {
            $this->actingAs($user, 'owner')
                ->put(route('owner.platform-backups.settings.update'), [
                    'settings' => [...$settings, 'backups.app_key_escrow_attested' => true],
                    'confirmation' => 'APP KEY ESCROW CONFIRMED',
                ])->assertRedirect(route('owner.login'));
            auth('owner')->logout();
        }

        $this->assertDatabaseMissing('admin_audit_events', ['event' => 'platform_backup.escrow_attested']);
    }

    private function validBackupSettings(bool $attested): array
    {
        return [
            'backups.primary.enabled' => false,
            'backups.primary.frequency' => '12h',
            'backups.primary.storage_driver' => 'local',
            'backups.primary.endpoint' => null,
            'backups.primary.region' => 'us-east-1',
            'backups.primary.bucket' => null,
            'backups.primary.path_style' => false,
            'backups.retention.daily' => 7,
            'backups.retention.weekly' => 4,
            'backups.retention.monthly' => 12,
            'backups.secondary.enabled' => false,
            'backups.secondary.frequency' => 'daily',
            'backups.app_key_escrow_attested' => $attested,
        ];
    }
}
