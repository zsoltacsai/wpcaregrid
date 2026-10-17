<?php

namespace Tests\Feature\Owner;

use App\AiProviders\ProviderCredentialState;
use App\AiProviders\ProviderHealthStatus;
use App\AiProviders\ProviderRegistry;
use App\Models\AdminAuditEvent;
use App\Owner\IntegrationCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

class AiProviderCredentialTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_registry_is_fixed_and_has_no_default_provider(): void
    {
        $this->asOwner();
        $registry = app(ProviderRegistry::class);

        $this->assertSame(['openai', 'anthropic', 'google_gemini'], array_keys($registry->all()));
        $this->assertNull($registry->defaultProvider());
        $this->assertSame('https://api.openai.com/v1/models', $registry->get('openai')['endpoint']);
        $this->assertSame('https://api.anthropic.com/v1/models', $registry->get('anthropic')['endpoint']);
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta/models', $registry->get('google_gemini')['endpoint']);
        $this->get(route('owner.integrations.index', ['provider' => 'https://attacker.example']))->assertOk();
        $this->assertNull($registry->defaultProvider());
    }

    public function test_ai_credential_is_encrypted_owner_only_runtime_resolved_and_has_no_fallback(): void
    {
        $owner = $this->asOwner();
        $secret = 'fake-openai-key-sentinel';
        config([
            'services.ai.openai.api_key' => 'environment-secret-must-not-activate',
            'owner_integration_credentials.environment_fallbacks.ai_openai_api_key' => true,
        ]);
        Http::preventStrayRequests();

        $this->get(route('owner.integrations.index'))->assertOk()
            ->assertSee('OpenAI')
            ->assertSee('Not configured')
            ->assertDontSee($secret)
            ->assertDontSee('environment-secret-must-not-activate');

        $this->from(route('owner.integrations.index'))
            ->post(route('owner.integrations.store'), [
                'id' => 'ai_openai_api_key',
                'value' => $secret,
                'current_password' => 'password',
            ])
            ->assertRedirect(route('owner.integrations.index'))
            ->assertSessionMissing('_old_input')
            ->assertDontSee($secret);

        $stored = DB::table('integration_credentials')->where('id', 'ai_openai_api_key')->sole();
        $this->assertNotSame($secret, $stored->value);
        $this->assertSame($secret, Crypt::decryptString($stored->value));
        $this->assertSame($secret, app(IntegrationCredentials::class)->resolve('ai_openai_api_key'));
        $this->assertFalse(app(IntegrationCredentials::class)->configured()['ai_openai_api_key']['environment_fallback']);

        app(IntegrationCredentials::class)->apply();
        $this->assertNotSame($secret, config('services.ai.openai.api_key'));

        $status = DB::table('ai_provider_statuses')->where('provider_id', 'openai')->sole();
        $this->assertSame(ProviderHealthStatus::Unknown->value, $status->health_status);
        $this->assertNull($status->health_generation);
        $this->assertStringNotContainsString($secret, json_encode($status));

        $audit = AdminAuditEvent::where('subject_key', 'ai_openai_api_key')->sole();
        $this->assertSame($owner->id, $audit->actor_user_id);
        $this->assertStringNotContainsString($secret, json_encode($audit->toArray()));

        $this->delete(route('owner.integrations.destroy', 'ai_openai_api_key'), ['current_password' => 'password'])
            ->assertRedirect(route('owner.integrations.index'));
        $this->assertDatabaseMissing('integration_credentials', ['id' => 'ai_openai_api_key']);
        $this->assertNull(app(IntegrationCredentials::class)->resolve('ai_openai_api_key'));
        $this->assertSame(ProviderHealthStatus::NotConfigured->value, app(ProviderCredentialState::class)->state('openai')['health_status']);
        $this->assertFalse(app(ProviderCredentialState::class)->state('openai')['configured']);
        $this->assertSame('environment-secret-must-not-activate', config('services.ai.openai.api_key'));
    }

    public function test_bad_password_does_not_store_or_flash_secret_and_save_never_probes(): void
    {
        $this->asOwner();
        Http::preventStrayRequests();
        $secret = 'fake-validation-key-sentinel';

        $this->from(route('owner.integrations.index'))
            ->post(route('owner.integrations.store'), [
                'id' => 'ai_anthropic_api_key',
                'value' => $secret,
                'current_password' => 'wrong-password',
            ])
            ->assertRedirect(route('owner.integrations.index'))
            ->assertSessionHasErrors('current_password')
            ->assertSessionMissing('_old_input')
            ->assertDontSee($secret);

        $this->assertDatabaseMissing('integration_credentials', ['id' => 'ai_anthropic_api_key']);
        $this->assertDatabaseMissing('ai_provider_statuses', ['provider_id' => 'anthropic']);
        $this->assertDatabaseMissing('admin_audit_events', ['subject_key' => 'ai_anthropic_api_key']);
        $this->assertStringNotContainsString($secret, json_encode(session()->all()));
    }

    public function test_missing_password_and_invalid_provider_credential_are_rejected_without_side_effects(): void
    {
        $this->asOwner();
        Http::preventStrayRequests();
        $secret = 'fake-invalid-input-key-sentinel';

        $this->from(route('owner.integrations.index'))
            ->post(route('owner.integrations.store'), ['id' => 'ai_openai_api_key', 'value' => $secret])
            ->assertSessionHasErrors('current_password')
            ->assertSessionMissing('_old_input');

        $this->post(route('owner.integrations.store'), [
            'id' => 'ai_custom_api_key',
            'value' => $secret,
            'current_password' => 'password',
        ])->assertSessionHasErrors('id')->assertSessionMissing('_old_input');

        $this->assertDatabaseMissing('integration_credentials', ['value' => $secret]);
        $this->assertStringNotContainsString($secret, json_encode(session()->all()));
    }

    public function test_control_characters_are_rejected_without_flashing_ai_secret(): void
    {
        $this->asOwner();
        $secret = "fake-control-sentinel\nkey-marker";

        $this->from(route('owner.integrations.index'))
            ->post(route('owner.integrations.store'), [
                'id' => 'ai_openai_api_key', 'value' => $secret, 'current_password' => 'password',
            ])
            ->assertRedirect(route('owner.integrations.index'))
            ->assertSessionHasErrors('value')
            ->assertSessionMissing('_old_input');

        $this->assertDatabaseMissing('integration_credentials', ['id' => 'ai_openai_api_key']);
        $this->assertStringNotContainsString('m145-control-sentinel', json_encode(session()->all()));
    }

    public function test_generation_changes_on_replace_and_remove_and_stale_health_is_discarded(): void
    {
        $this->asOwner();
        $state = app(ProviderCredentialState::class);
        $this->post(route('owner.integrations.store'), [
            'id' => 'ai_google_gemini_api_key', 'value' => 'gemini-first-sentinel', 'current_password' => 'password',
        ])->assertRedirect();
        $firstGeneration = $state->generation('google_gemini');
        $this->assertNotNull($firstGeneration);

        $this->post(route('owner.integrations.store'), [
            'id' => 'ai_google_gemini_api_key', 'value' => 'gemini-second-sentinel', 'current_password' => 'password',
        ])->assertRedirect();
        $secondGeneration = $state->generation('google_gemini');
        $this->assertNotSame($firstGeneration, $secondGeneration);
        $this->assertFalse($state->recordHealth('google_gemini', $firstGeneration, ProviderHealthStatus::Healthy, null, 45));
        $this->assertSame(ProviderHealthStatus::Unknown->value, $state->state('google_gemini')['health_status']);

        $this->delete(route('owner.integrations.destroy', 'ai_google_gemini_api_key'), ['current_password' => 'password'])
            ->assertRedirect();
        $this->assertNotSame($secondGeneration, $state->generation('google_gemini'));
        $this->assertFalse($state->recordHealth('google_gemini', $secondGeneration, ProviderHealthStatus::Healthy, null, 20));
        $this->assertSame(ProviderHealthStatus::NotConfigured->value, $state->state('google_gemini')['health_status']);
    }
}
