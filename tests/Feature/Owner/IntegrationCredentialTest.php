<?php

namespace Tests\Feature\Owner;

use App\Models\AdminAuditEvent;
use App\Models\Customer;
use App\Models\User;
use App\Operator\Permission;
use App\Owner\IntegrationCredentials;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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

    public function test_all_registered_credentials_are_applied_to_their_runtime_config_paths(): void
    {
        $credentials = app(IntegrationCredentials::class);
        $expected = [];

        foreach (IntegrationCredentials::FIELDS as $id => $field) {
            $value = 'test-only-'.str_pad((string) count($expected), 48, 'x');
            $credentials->store($id, $value);
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
            $this->delete(route('owner.integrations.destroy', 'stripe_secret'))->assertRedirect(route('owner.login'));
            $this->assertDatabaseMissing('integration_credentials', ['id' => 'stripe_secret']);
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
