<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * Keep the transport boundary of every state-changing route explicit.
 * Object authorization and handler side effects remain covered by domain tests.
 */
class RuntimeRouteBoundaryTest extends TestCase
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function test_every_state_changing_route_has_a_csrf_or_explicit_non_browser_boundary_and_limiter(): void
    {
        $routes = collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => array_intersect(self::MUTATING_METHODS, $route->methods()) !== []);

        $this->assertGreaterThan(100, $routes->count(), 'The contract should cover the complete runtime route table.');
        $webMiddleware = app(Kernel::class)->getMiddlewareGroups()['web'];
        $apiMiddleware = app(Kernel::class)->getMiddlewareGroups()['api'];

        $this->assertContains(PreventRequestForgery::class, $webMiddleware);
        $this->assertNotEmpty($apiMiddleware, 'The API transport boundary must remain configured.');

        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));
        $this->assertIsString($bootstrap);
        $this->assertSame(1, substr_count($bootstrap, 'validateCsrfTokens('), 'Any additional CSRF exemption requires an explicit security review.');
        $this->assertStringContainsString("validateCsrfTokens(except: ['stripe/webhook'])", $bootstrap);

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $name = (string) $route->getName();
            $uri = $route->uri();

            if ($name === 'stripe.webhook') {
                $this->assertSame('stripe/webhook', $uri);
                $this->assertContains('web', $middleware);
                $this->assertSame(\App\Http\Controllers\StripeWebhookController::class, $route->getActionName());
                continue;
            }

            if (str_starts_with($uri, 'api/')) {
                $this->assertContains('api', $middleware, $name.' must use the API middleware group');
                $this->assertNotEmpty(array_filter($middleware, fn ($item) => is_string($item) && str_starts_with($item, 'throttle:')), $name.' must be throttled');

                if ($name === 'api.v1.connector.pair') {
                    $this->assertContains('throttle:connector-pair', $middleware);
                    $this->assertNotContains('connector.auth', $middleware, 'Pairing establishes rather than uses a connector credential.');
                } else {
                    $this->assertContains('connector.auth', $middleware, $name.' must authenticate the connector before action');
                }

                continue;
            }

            $this->assertContains('web', $middleware, $name.' must use browser session and CSRF middleware');
            if (! in_array($name, ['logout', 'operator.logout', 'owner.logout'], true)) {
                $this->assertNotEmpty(array_filter($middleware, fn ($item) => is_string($item) && str_starts_with($item, 'throttle:')), $name.' must be throttled');
            }

            if (str_starts_with($uri, 'owner/') && ! in_array($name, ['owner.login.store', 'owner.logout'], true)) {
                $this->assertContains('auth:owner', $middleware, $name);
                $this->assertContains('owner', $middleware, $name);
                $this->assertCount(1, array_filter($middleware, fn ($item) => is_string($item) && str_starts_with($item, 'can:owner.')), $name);
            }

            if (str_starts_with($uri, 'operator/') && ! in_array($name, ['operator.login.store', 'operator.logout'], true)) {
                $this->assertContains('auth:operator', $middleware, $name);
                $this->assertContains('operator', $middleware, $name);
                $this->assertCount(1, array_filter($middleware, fn ($item) => is_string($item) && str_starts_with($item, 'can:operator.')), $name);
            }

            if (str_starts_with($uri, 'account/') && ! in_array($name, ['account.activate.store'], true)) {
                $this->assertContains('auth', $middleware, $name);
                $this->assertContains('customer', $middleware, $name);
            }
        }
    }
}
