<?php

namespace Tests\Feature\Owner;

use App\Models\AdminAuditEvent;
use App\Models\Customer;
use App\Models\User;
use App\Operator\Permission;
use App\Owner\IntegrationCredentials;
use App\Owner\OwnerAbility;
use App\Owner\OwnerSettings;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

final class OwnerSettingsTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.name' => 'WP CareGrid',
            'app.locale' => 'en',
            'app.timezone' => 'UTC',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.local_domain' => 'mail.staging.example.test',
            'mail.mailers.smtp.port' => 25,
            'mail.mailers.smtp.scheme' => null,
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
            'mail.from.address' => 'noreply@example.test',
            'mail.from.name' => 'WP CareGrid',
        ]);
    }

    public function test_defaults_are_typed_and_hungary_and_locale_currency_rules_are_explicit(): void
    {
        $settings = app(OwnerSettings::class);

        $this->assertSame('WP CareGrid', $settings->get('general.business_name'));
        $this->assertNull($settings->get('general.support_email'));
        $this->assertSame('UTC', $settings->get('general.timezone'));
        $this->assertSame('en', $settings->get('localization.default_language'));
        $this->assertSame(['HUF'], $settings->get('pricing.enabled_currencies'));
        $this->assertSame(25, $settings->get('mail.port'));
        $this->assertSame('HUF', $settings->suggestedCurrency('hu-HU'));
        $this->assertSame('HUF', $settings->suggestedCurrency('hu'));
        $this->assertSame('USD', $settings->suggestedCurrency('en-US'));
        $this->assertSame('GBP', $settings->suggestedCurrency('en_GB'));
        $this->assertSame('EUR', $settings->suggestedCurrency('de-DE'));
        $this->assertSame('EUR', $settings->suggestedCurrency('en'));
        $this->assertSame(['market' => 'hungary', 'currency' => 'HUF'], $settings->billingMarketCurrency('hu'));
        $this->assertNull($settings->billingMarketCurrency('US'));
    }

    public function test_general_settings_persist_typed_values_audit_and_invalidate_cache(): void
    {
        $owner = $this->asOwner();
        $settings = app(OwnerSettings::class);
        $settings->group('general'); // Cache defaults before the write.

        $this->put(route('owner.settings.update', 'general'), ['settings' => [
            'general.business_name' => 'CareGrid Test Business',
            'general.support_email' => 'support@example.test',
            'general.timezone' => 'Europe/Budapest',
            'general.country' => 'HU',
        ]])->assertRedirect(route('owner.settings.index', ['section' => 'general']));

        $this->assertSame('CareGrid Test Business', $settings->get('general.business_name'));
        $this->assertSame('Europe/Budapest', $settings->get('general.timezone'));
        $this->assertSame('HU', $settings->get('general.country'));
        $this->assertSame('CareGrid Test Business', config('app.name'));
        $this->assertSame('Europe/Budapest', config('app.timezone'));
        $this->assertDatabaseHas('owner_settings', ['id' => 'general.business_name', 'value' => '"CareGrid Test Business"']);
        $this->assertSame(4, AdminAuditEvent::where('event', 'owner_setting.updated')->count());
        $this->assertSame($owner->id, AdminAuditEvent::where('event', 'owner_setting.updated')->first()->actor_user_id);
        $this->assertNotNull(AdminAuditEvent::where('event', 'owner_setting.updated')->first()->ip_address);
        $this->assertNotNull(AdminAuditEvent::where('event', 'owner_setting.updated')->first()->created_at);
        $this->assertSame('CareGrid Test Business', AdminAuditEvent::where('subject_key', 'general.business_name')->first()->data['new']);
    }

    public function test_only_allowlisted_settings_are_writable_and_invalid_general_values_are_rejected(): void
    {
        $this->asOwner();

        $this->put(route('owner.settings.update', 'general'), ['settings' => [
            'general.business_name' => 'Valid name',
            'system.raw_env' => 'APP_KEY=not-allowed',
        ]])->assertSessionHasErrors('settings')->assertSessionMissing('_old_input.settings');
        $this->put(route('owner.settings.update', 'general'), ['settings' => [
            'general.business_name' => "Bad\nName",
            'general.timezone' => 'not/a-zone',
            'general.country' => 'HUN',
        ]])->assertSessionHasErrors();

        $this->assertDatabaseCount('owner_settings', 0);
        $this->assertDatabaseCount('admin_audit_events', 0);
    }

    public function test_secret_keys_nested_payloads_and_cross_group_keys_are_rejected_without_side_effects(): void
    {
        $this->asOwner();
        $configBefore = config('mail.mailers.smtp.password');

        foreach ([
            ['APP_KEY' => 'synthetic-secret-marker'],
            ['DB_PASSWORD' => 'synthetic-secret-marker'],
            ['STRIPE_SECRET' => 'synthetic-secret-marker'],
            ['cloudflare_api_token' => 'synthetic-secret-marker'],
            ['smtp_password' => 'synthetic-secret-marker'],
            ['mail.mailers.smtp.password' => 'synthetic-secret-marker'],
            ['unknown.group.key' => 'value'],
            ['__proto__' => ['polluted' => true]],
            ['mail' => ['password' => 'synthetic-secret-marker']],
            ['pricing.default_international_currency' => 'USD'],
        ] as $payload) {
            $this->put(route('owner.settings.update', 'general'), ['settings' => $payload])
                ->assertSessionHasErrors('settings')
                ->assertSessionMissing('_old_input.settings');
        }

        $this->put(route('owner.settings.update', 'mail'), ['settings' => [
            'mail.mode' => 'local_smtp', 'mail.host' => '127.0.0.1', 'mail.port' => 25,
            'mail.username' => null, 'mail.scheme' => null, 'mail.from_address' => 'noreply@example.test',
            'mail.from_name' => 'WP CareGrid', 'localization.default_language' => 'hu',
        ]])->assertSessionHasErrors('settings');

        $this->assertDatabaseCount('owner_settings', 0);
        $this->assertDatabaseCount('admin_audit_events', 0);
        $this->assertSame($configBefore, config('mail.mailers.smtp.password'));
    }

    public function test_type_confusion_duplicate_currency_and_mail_header_or_scheme_payloads_fail(): void
    {
        $settings = app(OwnerSettings::class);
        $invalidInputs = [
            ['general', ['general.business_name' => ['nested'], 'general.timezone' => 'UTC', 'general.country' => 'HU']],
            ['general', ['general.business_name' => null, 'general.timezone' => 'UTC', 'general.country' => 'HU']],
            ['general', ['general.business_name' => 'Valid', 'general.support_email' => ['array'], 'general.timezone' => 'UTC', 'general.country' => 'HU']],
            ['pricing', ['pricing.enabled_currencies' => ['HUF', 'HUF'], 'pricing.default_international_currency' => 'EUR']],
            ['pricing', ['pricing.enabled_currencies' => ['HUF', 'CAD'], 'pricing.default_international_currency' => 'EUR']],
            ['mail', $this->mailValues('external_smtp', 'smtp.provider.test.com', 2526, 'user@example.test', 'smtp')],
            ['mail', $this->mailValues('external_smtp', 'smtp.provider.test.com', 587, 'user@example.test', 'file')],
            ['mail', $this->mailValues('external_smtp', 'smtp.provider.test.com', 587, 'user@example.test', null)],
            ['mail', $this->mailValues('external_smtp', 'http://127.0.0.1', 587, 'user@example.test', 'smtp')],
            ['mail', $this->mailValues('external_smtp', "smtp.provider.test.com\r\nBcc:bad", 587, null, 'smtp')],
            ['mail', $this->mailValues('external_smtp', str_repeat('a', 254), 587, null, 'smtp')],
            ['mail', $this->mailValues('external_smtp', 'smtp.provider.test.com', 587, "user@example.test\r\nBcc:bad@example.test", 'smtp')],
            ['mail', $this->mailValues('external_smtp', 'smtp.provider.test.com', 587, null, "smtp\r\nBcc")],
            ['mail', array_merge($this->mailValues('external_smtp', 'smtp.provider.test.com', 587, null, null), ['mail.from_name' => "WP CareGrid\r\nBcc:bad@example.test"])],
            ['mail', array_merge($this->mailValues('external_smtp', 'smtp.provider.test.com', 587, null, null), ['mail.from_address' => 'not-an-email'])],
            ['mail', $this->mailValues('external_smtp', 'smtp.provider.test.com', '999999999999999999999', null, 'smtp')],
        ];

        foreach ($invalidInputs as [$group, $input]) {
            try {
                $settings->validateGroup($group, $input);
                $this->fail('Malformed or unsafe settings input must be rejected.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_external_smtp_rejects_internal_targets_and_non_smtp_ports_but_accepts_public_hostnames(): void
    {
        $settings = app(OwnerSettings::class);
        foreach (['127.0.0.1', '10.0.0.5', '169.254.169.254', 'fd00::1', 'metadata.google.internal', 'mail.local', 'smtp.example.test', 'smtp'] as $host) {
            try {
                $settings->validateGroup('mail', $this->mailValues('external_smtp', $host, 587, null, 'smtp'));
                $this->fail('External SMTP must not target internal, local, literal-IP or invalid hosts.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $valid = $settings->validateGroup('mail', $this->mailValues('external_smtp', 'smtp.provider.test.com', 587, null, 'smtp'));
        $this->assertSame('smtp.provider.test.com', $valid['mail.host']);
    }

    public function test_html_like_business_name_is_escaped_in_owner_settings_page(): void
    {
        $this->asOwner();
        $payload = $this->generalValues('<script>alert("x")</script>');
        $this->put(route('owner.settings.update', 'general'), ['settings' => $payload])->assertRedirect();

        $this->get(route('owner.settings.index', ['section' => 'general']))
            ->assertOk()
            ->assertDontSee('<script>alert("x")</script>', false)
            ->assertSee('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', false);
    }

    public function test_malformed_stored_json_falls_back_safely_and_logs_only_setting_identifier(): void
    {
        DB::table('owner_settings')->insert([
            ['id' => 'general.business_name', 'value' => '{malformed', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 'pricing.enabled_currencies', 'value' => '["HUF",["nested"]]', 'created_at' => now(), 'updated_at' => now()],
        ]);
        Log::spy();

        $this->assertSame('WP CareGrid', app(OwnerSettings::class)->get('general.business_name'));
        $this->assertSame(['HUF'], app(OwnerSettings::class)->get('pricing.enabled_currencies'));
        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_semantically_invalid_stored_group_is_ignored_as_a_unit(): void
    {
        DB::table('owner_settings')->insert([
            'id' => 'mail.mode',
            'value' => json_encode('unrestricted_transport'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Log::spy();

        $settings = app(OwnerSettings::class);
        $this->assertFalse($settings->hasStored('mail.mode'));
        $this->assertSame(
            $this->loopback(config('mail.mailers.smtp.host')) || config('mail.mailers.smtp.host') === config('mail.mailers.smtp.local_domain')
                ? 'local_smtp' : 'external_smtp',
            $settings->get('mail.mode'),
        );
        Log::shouldHaveReceived('warning')->once()->with('Invalid stored Owner settings group was ignored.', ['group' => 'mail']);
    }

    public function test_audit_failure_rolls_back_the_settings_write(): void
    {
        $owner = $this->asOwner();
        $settings = app(OwnerSettings::class);
        $this->assertSame('WP CareGrid', $settings->get('general.business_name'));
        AdminAuditEvent::creating(fn () => throw new \RuntimeException('synthetic audit storage failure'));

        try {
            $settings->updateGroup('general', $this->generalValues('Changed'), $owner);
            $this->fail('Audit storage failure should abort the settings transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('synthetic audit storage failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('owner_settings', 0);
        $this->assertDatabaseCount('admin_audit_events', 0);
        $this->assertSame('WP CareGrid', $settings->get('general.business_name'));
    }

    public function test_placeholder_sections_are_read_only_and_have_no_side_effects(): void
    {
        $this->asOwner();

        foreach (['backups', 'billing_tax', 'system'] as $section) {
            $this->put(route('owner.settings.update', $section), ['settings' => []])
                ->assertSessionHasErrors('settings');
        }

        $this->assertDatabaseCount('owner_settings', 0);
        $this->assertDatabaseCount('admin_audit_events', 0);
    }

    public function test_supported_currencies_validate_but_huf_is_required_and_unsupported_codes_fail(): void
    {
        $settings = app(OwnerSettings::class);
        $valid = $settings->validateGroup('pricing', [
            'pricing.enabled_currencies' => ['HUF', 'EUR', 'USD', 'GBP'],
            'pricing.default_international_currency' => 'EUR',
        ]);
        $this->assertSame(['HUF', 'EUR', 'USD', 'GBP'], $valid['pricing.enabled_currencies']);
        $this->assertSame('EUR', $valid['pricing.default_international_currency']);

        try {
            $settings->validateGroup('pricing', ['pricing.enabled_currencies' => ['HUF', 'CAD'], 'pricing.default_international_currency' => 'EUR']);
            $this->fail('Unsupported currencies must be rejected.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        try {
            $settings->validateGroup('pricing', ['pricing.enabled_currencies' => ['EUR'], 'pricing.default_international_currency' => 'HUF']);
            $this->fail('HUF must remain enabled and the international default must be non-HUF.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_currency_foundation_does_not_change_active_pricing_configuration(): void
    {
        $settings = app(OwnerSettings::class);
        $activePricing = config('pricing');
        $settings->updateGroup('pricing', [
            'pricing.enabled_currencies' => ['HUF', 'EUR', 'USD', 'GBP'],
            'pricing.default_international_currency' => 'EUR',
        ], null);

        $this->assertSame($activePricing, config('pricing'));
        $this->assertSame(['HUF', 'EUR', 'USD', 'GBP'], $settings->get('pricing.enabled_currencies'));
    }

    public function test_local_smtp_settings_are_applied_without_a_password(): void
    {
        $this->asOwner();
        config(['mail.mailers.smtp.password' => 'synthetic-smtp-password']);
        $settings = app(OwnerSettings::class);
        $settings->updateGroup('mail', $this->mailValues('local_smtp', '127.0.0.1', 25, null, null), auth('owner')->user());
        $settings->applyRuntimeConfiguration();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('127.0.0.1', config('mail.mailers.smtp.host'));
        $this->assertSame(25, config('mail.mailers.smtp.port'));
        $this->assertNull(config('mail.mailers.smtp.username'));
        $this->assertNull(config('mail.mailers.smtp.password'));
        $this->assertSame('noreply@example.test', config('mail.from.address'));
        $this->assertSame('WP CareGrid', config('mail.from.name'));
        $this->assertStringNotContainsString('synthetic-smtp-password', json_encode(AdminAuditEvent::all()->toArray()));
    }

    public function test_external_smtp_uses_service_credential_but_settings_and_audit_do_not_store_password(): void
    {
        $this->asOwner();
        $credentials = app(IntegrationCredentials::class);
        $credentials->store('smtp_password', 'synthetic-external-smtp-password');
        $credentials->apply();
        $settings = app(OwnerSettings::class);
        $settings->updateGroup('mail', $this->mailValues('external_smtp', 'smtp.provider.test.com', 587, 'mailer@example.test', 'smtp'), auth('owner')->user());
        $settings->applyRuntimeConfiguration();

        $this->assertSame('smtp.provider.test.com', config('mail.mailers.smtp.host'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
        $this->assertSame('mailer@example.test', config('mail.mailers.smtp.username'));
        $this->assertSame('synthetic-external-smtp-password', config('mail.mailers.smtp.password'));
        $this->assertDatabaseMissing('owner_settings', ['id' => 'mail.password']);
        $this->assertStringNotContainsString('synthetic-external-smtp-password', json_encode(AdminAuditEvent::all()->toArray()));
        $this->assertStringNotContainsString('mailer@example.test', json_encode(AdminAuditEvent::all()->toArray()));
    }

    public function test_local_smtp_rejects_non_loopback_hosts_and_external_smtp_requires_tls_scheme(): void
    {
        $settings = app(OwnerSettings::class);

        foreach ([
            $this->mailValues('local_smtp', 'smtp.attacker.test', 25, null, null),
            $this->mailValues('external_smtp', '127.0.0.1', 587, null, 'smtp'),
        ] as $values) {
            try {
                $settings->validateGroup('mail', $values);
                $this->fail('Unsafe SMTP mode/host combinations must be rejected.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_smtp_password_is_shown_as_status_only_and_is_not_a_settings_field(): void
    {
        $this->asOwner();
        app(IntegrationCredentials::class)->store('smtp_password', 'synthetic-secret-marker');

        $this->get(route('owner.settings.index', ['section' => 'mail']))
            ->assertOk()
            ->assertSee(__('owner.settings.mail.configured'))
            ->assertDontSee('synthetic-secret-marker')
            ->assertDontSee('APP_KEY')
            ->assertDontSee('DB_PASSWORD');

        $this->put(route('owner.settings.update', 'mail'), ['settings' => $this->mailValues('local_smtp', '127.0.0.1', 25, null, null) + ['mail.password' => 'synthetic-secret-marker']])
            ->assertSessionHasErrors('settings');
        $this->assertDatabaseMissing('owner_settings', ['id' => 'mail.password']);
        $this->assertDatabaseHas('integration_credentials', ['id' => 'smtp_password']);
    }

    public function test_settings_ui_distinguishes_persisted_values_from_config_defaults(): void
    {
        $owner = $this->asOwner();

        $this->get(route('owner.settings.index', ['section' => 'mail']))
            ->assertOk()
            ->assertSee(__('owner.settings.sources.config'));

        app(OwnerSettings::class)->updateGroup('mail', $this->mailValues('local_smtp', '127.0.0.1', 25, null, null), $owner);
        $this->get(route('owner.settings.index', ['section' => 'mail']))
            ->assertOk()
            ->assertSee(__('owner.settings.sources.owner'));
    }

    public function test_owner_settings_routes_enforce_owner_ability_auth_and_csrf(): void
    {
        $this->get(route('owner.settings.index'))->assertRedirect(route('owner.login'));
        $customer = Customer::factory()->withUser()->create()->user;
        $this->actingAs($customer, 'web')->get(route('owner.settings.index'))->assertRedirect(route('owner.login'));
        $operator = User::factory()->operator(Permission::cases())->create();
        $this->actingAs($operator, 'owner')->get(route('owner.settings.index'))->assertRedirect(route('owner.login'));

        $owner = $this->asOwner();
        Gate::define(OwnerAbility::SettingsManage->gate(), fn () => false);
        $this->get(route('owner.settings.index'))->assertForbidden();
        Gate::define(OwnerAbility::SettingsManage->gate(), fn (User $user) => $user->isOwner());
        $this->get(route('owner.settings.index'))->assertOk()->assertSee(__('owner.nav.settings'));

        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->put(route('owner.settings.update', 'general'), ['settings' => ['general.business_name' => 'Denied']])->assertStatus(419);
        $this->post(route('owner.settings.mail.test'), ['recipient' => 'owner@example.test'])->assertStatus(419);

        $middleware = Route::getRoutes()->getByName('owner.settings.mail.test')->gatherMiddleware();
        $this->assertContains('throttle:owner-settings-mail', $middleware);
        $updateMiddleware = Route::getRoutes()->getByName('owner.settings.update')->gatherMiddleware();
        $this->assertContains('can:owner.settings.manage', $updateMiddleware);
        $this->assertContains('throttle:owner-sensitive', $updateMiddleware);
        $this->assertNotNull($owner);
    }

    public function test_mail_test_is_fixed_audited_and_does_not_record_recipient_or_transport_error(): void
    {
        $this->asOwner();
        Mail::shouldReceive('raw')->once()->with(__('owner.settings.mail.test_body'), \Mockery::type('Closure'))->andReturnNull();

        $this->post(route('owner.settings.mail.test'), ['recipient' => 'test-recipient@example.test'])
            ->assertRedirect()
            ->assertSessionHas('status', __('owner.settings.mail.test_sent'));

        $event = AdminAuditEvent::sole();
        $this->assertSame('owner_settings.mail_test_sent', $event->event);
        $this->assertStringNotContainsString('test-recipient@example.test', json_encode($event->data));
        $this->assertSame(['group' => 'mail', 'result' => 'sent'], $event->data);
    }

    public function test_invalid_test_mail_input_does_not_flash_extra_secret_fields(): void
    {
        $this->asOwner();

        $this->post(route('owner.settings.mail.test'), [
            'recipient' => 'not-an-email',
            'smtp_password' => 'synthetic-secret-marker',
        ])->assertSessionHasErrors('recipient')->assertSessionHas('_old_input', []);

        $this->assertDatabaseCount('admin_audit_events', 0);
    }

    public function test_log_mailer_cannot_report_a_test_email_as_delivered(): void
    {
        $this->asOwner();
        config(['mail.default' => 'log']);
        Mail::shouldReceive('raw')->never();

        $this->get(route('owner.settings.index', ['section' => 'mail']))
            ->assertOk()
            ->assertSee(__('owner.settings.mail.test_unavailable'));
        $this->post(route('owner.settings.mail.test'), ['recipient' => 'test-recipient@example.test'])
            ->assertRedirect()
            ->assertSessionHas('error', __('owner.settings.mail.test_unavailable'));

        $event = AdminAuditEvent::sole();
        $this->assertSame('owner_settings.mail_test_failed', $event->event);
        $this->assertSame(['group' => 'mail', 'result' => 'failed'], $event->data);
    }

    public function test_mail_test_failure_is_sanitized_and_audited_without_exception_text(): void
    {
        $this->asOwner();
        Log::spy();
        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('synthetic-secret-error-marker'));

        $this->post(route('owner.settings.mail.test'), ['recipient' => 'test-recipient@example.test'])
            ->assertRedirect()
            ->assertSessionHas('error', __('owner.settings.mail.test_failed'))
            ->assertDontSee('synthetic-secret-error-marker')
            ->assertDontSee('test-recipient@example.test');

        $event = AdminAuditEvent::sole();
        $this->assertSame('owner_settings.mail_test_failed', $event->event);
        $this->assertSame(['group' => 'mail', 'result' => 'failed'], $event->data);
        $this->assertStringNotContainsString('synthetic-secret-error-marker', json_encode($event->data));
        Log::shouldHaveReceived('warning')->once()->with('Owner mail test failed.', ['exception_class' => \RuntimeException::class]);
    }

    public function test_settings_migration_is_specific_and_contains_no_secret_column(): void
    {
        $this->assertTrue(Schema::hasTable('owner_settings'));
        $this->assertTrue(Schema::hasColumn('owner_settings', 'value'));
        $this->assertFalse(Schema::hasColumn('owner_settings', 'password'));
        $this->assertFalse(Schema::hasColumn('owner_settings', 'secret'));
    }

    private function mailValues(string $mode, string $host, mixed $port, ?string $username, ?string $scheme): array
    {
        return [
            'mail.mode' => $mode,
            'mail.host' => $host,
            'mail.port' => $port,
            'mail.username' => $username,
            'mail.scheme' => $scheme,
            'mail.from_address' => 'noreply@example.test',
            'mail.from_name' => 'WP CareGrid',
        ];
    }

    private function generalValues(string $businessName): array
    {
        return [
            'general.business_name' => $businessName,
            'general.support_email' => 'support@example.test',
            'general.timezone' => 'Europe/Budapest',
            'general.country' => 'HU',
        ];
    }

    private function loopback(mixed $host): bool
    {
        return in_array(strtolower((string) $host), ['127.0.0.1', '::1', 'localhost'], true);
    }
}
