<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Operator\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * Role isolation across every route of the three signed-in areas:
 * customers (/account), operators (/operator) and owners (/owner).
 * Each area only ever serves its own role; every other role, and anonymous
 * visitors, are sent to that area's sign-in page before anything is loaded
 * or changed. Generated from the route list, so a new route is covered
 * automatically.
 */
class AuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** Routes reachable without signing in (sign-in, activation, logout). */
    private const PUBLIC = ['login', 'operator.login', 'operator.login.store', 'owner.login', 'owner.login.store', 'account.activate', 'account.activate.store', 'logout', 'operator.logout', 'owner.logout'];

    /** @return array<string, array{prefix: string, login: string}> */
    private static function areas(): array
    {
        return [
            'customer' => ['prefix' => 'account', 'login' => 'login'],
            'operator' => ['prefix' => 'operator', 'login' => 'operator.login'],
            'owner' => ['prefix' => 'owner', 'login' => 'owner.login'],
        ];
    }

    /** @return array<string, list<Route>> Routes of each area. */
    private function routesByArea(): array
    {
        $byArea = [];
        foreach (Router::getRoutes()->getRoutes() as $route) {
            if (in_array($route->getName(), self::PUBLIC, true)) {
                continue;
            }
            foreach (self::areas() as $area => $info) {
                if ($route->uri() === $info['prefix'] || str_starts_with($route->uri(), $info['prefix'].'/')) {
                    $byArea[$area][] = $route;
                }
            }
        }

        return $byArea;
    }

    /**
     * Signed in on one guard only, as a real session would be. actingAs()
     * also makes that guard the default for the rest of the test, which a
     * real request never does; the default goes back to "web".
     */
    private function signIn(User $user, string $guard): void
    {
        $this->actingAs($user, $guard);
        $this->app['auth']->shouldUse('web');
    }

    /** A URL for the route with placeholder parameters (authentication runs before binding). */
    private function url(Route $route): string
    {
        return '/'.preg_replace('/\{[^}]+\}/', '00000000-0000-4000-8000-000000000000', $route->uri());
    }

    /** @return array<string, array{0: User, 1: string}> Signed-in identities and their guards. */
    private function identities(): array
    {
        $customer = Customer::factory()->withUser()->create();
        $subscription = Subscription::factory()->for($customer)->create();
        Website::factory()->for($customer)->create(['subscription_id' => $subscription->id]);

        return [
            'customer' => [$customer->user, 'web'],
            // Every permission: the role, not the permissions, decides the area.
            'operator' => [User::factory()->operator(Permission::cases())->create(), 'operator'],
            'owner' => [User::factory()->owner()->create(), 'owner'],
        ];
    }

    public function test_every_area_turns_away_other_roles_and_anonymous_visitors(): void
    {
        $routes = $this->routesByArea();
        $this->assertGreaterThan(15, count($routes['customer']));
        $this->assertGreaterThan(25, count($routes['operator']));
        $this->assertGreaterThan(25, count($routes['owner']));

        $identities = $this->identities();
        $checked = 0;

        foreach ($routes as $area => $areaRoutes) {
            $login = route(self::areas()[$area]['login']);

            foreach (['anonymous', ...array_keys($identities)] as $who) {
                if ($who === $area) {
                    continue;
                }

                foreach ($areaRoutes as $route) {
                    $this->app['auth']->forgetGuards();
                    $this->flushSession();
                    if ($who !== 'anonymous') {
                        [$user, $guard] = $identities[$who];
                        $this->signIn($user, $guard);
                    }

                    $method = in_array('GET', $route->methods(), true) ? 'GET' : $route->methods()[0];
                    $response = $this->call($method, $this->url($route));

                    $this->assertTrue($response->isRedirect($login), "{$who} → {$method} /{$route->uri()} must redirect to {$login}, got {$response->getStatusCode()} ".($response->headers->get('Location') ?? ''));
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(150, $checked);
    }

    public function test_a_login_signed_in_on_one_area_does_not_carry_over_to_another(): void
    {
        $identities = $this->identities();

        // Signed in on all three guards at once (one shared session): each area still checks its own guard and role.
        foreach ($identities as [$user, $guard]) {
            $this->signIn($user, $guard);
        }
        $this->get(route('account.dashboard'))->assertOk();
        $this->get(route('operator.dashboard'))->assertOk();
        $this->get(route('owner.dashboard'))->assertOk();

        // A customer login put on the operator or owner guard is refused there.
        [$customerUser] = $identities['customer'];
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->signIn($customerUser, 'operator');
        $this->get(route('operator.dashboard'))->assertRedirect(route('operator.login'));
        $this->signIn($customerUser, 'owner');
        $this->get(route('owner.dashboard'))->assertRedirect(route('owner.login'));

        // An operator or owner login on the customer guard has no customer: refused.
        foreach (['operator', 'owner'] as $staff) {
            $this->app['auth']->forgetGuards();
            $this->flushSession();
            $this->signIn($identities[$staff][0], 'web');
            $this->get(route('account.dashboard'))->assertRedirect(route('login'));
        }
    }
}
