<?php

namespace Tests\Feature\Owner;

use App\Models\AdminAuditEvent;
use App\Models\Customer;
use App\Models\User;
use App\Operator\Permission;
use App\Owner\OwnerAbility;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route as Router;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

/** Owner sign-in, route isolation and the owner.* abilities. */
class OwnerAccessTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    public function test_every_owner_route_requires_the_owner_role_names_an_ability_and_is_protected(): void
    {
        $routes = collect(Router::getRoutes()->getRoutes())->filter(fn (Route $route) => $route->uri() === 'owner' || str_starts_with($route->uri(), 'owner/'));
        $this->assertGreaterThanOrEqual(30, $routes->count());

        $this->assertContains(PreventRequestForgery::class, app(Kernel::class)->getMiddlewareGroups()['web'], 'Owner forms are CSRF protected through the web group.');

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('web', $middleware, $route->uri());

            if (in_array($route->getName(), ['owner.login', 'owner.login.store', 'owner.logout'], true)) {
                continue;
            }

            $this->assertContains('auth:owner', $middleware, $route->getName());
            $this->assertContains('owner', $middleware, $route->getName());
            $abilities = array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'can:owner.'));
            $this->assertCount(1, $abilities, $route->getName().' must name exactly one ability');
            $this->assertContains(substr(reset($abilities), strlen('can:owner.')), array_column(OwnerAbility::cases(), 'value'), $route->getName());

            if (array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods())) {
                $this->assertNotEmpty(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')), $route->getName().' must be rate limited');
            }
        }
    }

    public function test_an_owner_signs_in_and_out_on_the_owner_login(): void
    {
        config(['session.driver' => 'database']);
        $owner = $this->owner(['email' => 'owner@wpcaregrid.example', 'password' => 'owner-password-123']);

        $this->get(route('owner.login'))->assertOk()->assertSee('Owner sign-in')->assertSee('noindex, nofollow', false);
        $this->post(route('owner.login.store'), ['email' => 'OWNER@wpcaregrid.example', 'password' => 'owner-password-123'])->assertRedirect(route('owner.dashboard'));
        $this->assertAuthenticatedAs($owner, 'owner');
        $this->assertGuest('web');
        $this->assertGuest('operator');
        $this->assertSame('owner.signed_in', AdminAuditEvent::sole()->event);

        $this->get(route('owner.dashboard'))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Cache-Control', 'no-store, private');

        $this->post(route('owner.logout'))->assertRedirect(route('owner.login'));
        $this->assertGuest('owner');
    }

    /** @return array<string, array{0: string}> */
    public static function nonOwners(): array
    {
        return ['customer' => ['customer'], 'operator' => ['operator'], 'unverified owner' => ['unverified'], 'owner with a customer account' => ['owner_customer']];
    }

    #[DataProvider('nonOwners')]
    public function test_customers_operators_and_incomplete_owners_cannot_sign_in(string $kind): void
    {
        $user = match ($kind) {
            'customer' => Customer::factory()->withUser()->create(['email' => 'x@company.example'])->user,
            'operator' => User::factory()->operator(Permission::cases())->create(['email' => 'x@company.example', 'password' => 'password-123456']),
            'unverified' => User::factory()->owner()->unverified()->create(['email' => 'x@company.example', 'password' => 'password-123456']),
            'owner_customer' => tap(User::factory()->owner()->create(['email' => 'x@company.example', 'password' => 'password-123456']), fn (User $u) => Customer::factory()->create(['user_id' => $u->id])),
        };

        $this->post(route('owner.login.store'), ['email' => 'x@company.example', 'password' => 'password-123456'])->assertSessionHasErrors(['email' => __('owner.auth.failed')]);
        $this->assertGuest('owner');
        $this->assertNotNull($user);
    }

    public function test_guests_are_sent_to_the_owner_login(): void
    {
        $site = $this->customerWithWebsite();

        foreach (['owner.dashboard', 'owner.customers.index', 'owner.websites.index', 'owner.operators.index', 'owner.pricing.index', 'owner.content.index', 'owner.seo.index', 'owner.system'] as $name) {
            $this->get(route($name))->assertRedirect(route('owner.login'));
        }
        $this->get(route('owner.customers.show', $site['customer']))->assertRedirect(route('owner.login'));
        $this->put(route('owner.customers.update', $site['customer']), ['first_name' => 'X'])->assertRedirect(route('owner.login'));
        $this->post(route('owner.pricing.prices.publish', 'wordpress.basic.monthly.hu'))->assertRedirect(route('owner.login'));
    }

    public function test_a_signed_in_customer_cannot_reach_owner_routes(): void
    {
        $site = $this->customerWithWebsite();
        $this->actingAs($site['customer']->user, 'web');

        $this->get(route('owner.dashboard'))->assertRedirect(route('owner.login'));
        $this->put(route('owner.customers.update', $site['customer']), ['first_name' => 'Mallory'])->assertRedirect(route('owner.login'));
        $this->assertNotSame('Mallory', $site['customer']->refresh()->first_name);

        // Even a customer login authenticated on the owner guard is refused and signed out.
        $this->actingAs($site['customer']->user, 'owner');
        $this->get(route('owner.dashboard'))->assertRedirect(route('owner.login'));
        $this->assertGuest('owner');
    }

    public function test_an_operator_cannot_reach_owner_routes_whatever_its_permissions(): void
    {
        $operator = User::factory()->operator(Permission::cases())->create();
        $this->actingAs($operator, 'operator');
        $this->get(route('owner.operators.index'))->assertRedirect(route('owner.login'));

        $this->actingAs($operator, 'owner');
        $this->get(route('owner.operators.index'))->assertRedirect(route('owner.login'));
        $this->assertGuest('owner');

        foreach (OwnerAbility::cases() as $ability) {
            $this->assertFalse(Gate::forUser($operator)->allows($ability->gate()), $ability->value);
        }
    }

    public function test_an_owner_is_not_an_operator_or_a_customer(): void
    {
        $owner = $this->asOwner();

        foreach (OwnerAbility::cases() as $ability) {
            $this->assertTrue(Gate::forUser($owner)->allows($ability->gate()), $ability->value);
        }
        foreach (Permission::cases() as $permission) {
            $this->assertFalse(Gate::forUser($owner)->allows($permission->gate()), $permission->value);
        }

        // The owner session does not open the operator console or the customer area.
        $this->get(route('operator.dashboard'))->assertRedirect(route('operator.login'));
        $this->get(route('account.dashboard'))->assertRedirect(route('login'));
    }

    public function test_owner_abilities_require_the_owner_role_even_when_checked_directly(): void
    {
        $customer = Customer::factory()->withUser()->create()->user;
        $unverified = User::factory()->owner()->unverified()->create();

        foreach (OwnerAbility::cases() as $ability) {
            $this->assertFalse(Gate::forUser($customer)->allows($ability->gate()));
            $this->assertFalse(Gate::forUser($unverified)->allows($ability->gate()));
        }
    }

    public function test_tampered_identifiers_are_not_found(): void
    {
        $this->asOwner();
        $site = $this->customerWithWebsite();

        $this->get(route('owner.customers.show', 'not-a-uuid'))->assertNotFound();
        $this->get('/owner/customers/'.$site['customer']->id)->assertNotFound();
        $this->get('/owner/websites/'.$site['website']->id)->assertNotFound();
        $this->get(route('owner.websites.show', $site['customer']->uuid))->assertNotFound();
        // An operator route parameter only binds operator logins.
        $this->get('/owner/operators/'.$site['customer']->user->id)->assertNotFound();
        $this->get('/owner/operators/'.auth('owner')->id())->assertNotFound();
        $this->get('/owner/operators/1abc')->assertNotFound();
        $this->get(route('owner.pricing.prices.edit', 'wordpress.basic.monthly.mars'))->assertNotFound();
        $this->get(route('owner.pricing.prices.edit', 'wordpress.platinum.monthly.hu'))->assertNotFound();
        $this->get(route('owner.content.edit', ['home.unknown', 'en']))->assertNotFound();
        $this->get(route('owner.content.edit', ['home.hero', 'xx']))->assertNotFound();
        // SEO sections only under /owner/seo, content sections only under /owner/content.
        $this->get(route('owner.content.edit', ['seo.home', 'en']))->assertNotFound();
        $this->get(route('owner.seo.edit', ['home.hero', 'en']))->assertNotFound();
    }

    public function test_owner_changes_without_a_csrf_token_are_rejected(): void
    {
        // Laravel skips CSRF checks in unit tests; enforce them here as in production.
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->asOwner();
        $site = $this->customerWithWebsite();
        $operator = User::factory()->operator([Permission::WebsitesView])->create();

        $this->put(route('owner.customers.update', $site['customer']), ['first_name' => 'Mallory', 'last_name' => 'X', 'customer_type' => 'individual', 'country_code' => 'HU'])->assertStatus(419);
        $this->put(route('owner.operators.permissions', $operator), ['permissions' => Permission::values(), 'confirm_sensitive' => 1])->assertStatus(419);
        $this->put(route('owner.content.update', ['home.hero', 'en']), ['action' => 'publish', 'fields' => ['title' => 'Hacked']])->assertStatus(419);
        $this->post(route('owner.pricing.prices.publish', 'wordpress.basic.monthly.hu'), ['confirm' => 1])->assertStatus(419);
        $this->post(route('owner.login.store'), ['email' => 'a@b.test', 'password' => 'x'])->assertStatus(419);

        $this->assertNotSame('Mallory', $site['customer']->refresh()->first_name);
        $this->assertSame(['websites.view'], $operator->refresh()->operator_permissions);
        $this->assertSame(0, AdminAuditEvent::count());
    }

    public function test_sensitive_owner_actions_are_rate_limited(): void
    {
        $this->asOwner();
        $site = $this->customerWithWebsite();
        $payload = ['first_name' => 'Anna', 'last_name' => 'Kovacs', 'customer_type' => 'individual', 'country_code' => 'HU'];

        for ($i = 0; $i < 20; $i++) {
            $this->put(route('owner.customers.update', $site['customer']), $payload)->assertRedirect();
        }
        $this->put(route('owner.customers.update', $site['customer']), $payload)->assertStatus(429);
    }

    public function test_the_forbidden_page_does_not_leak_details(): void
    {
        $owner = $this->asOwner();
        Gate::define('owner.system.view', fn () => false);

        $this->get(route('owner.system'))->assertForbidden()->assertSee(__('owner.errors.forbidden_title'))->assertDontSee('Stack trace');
        $this->assertNotNull($owner);
    }
}
