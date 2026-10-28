<?php

namespace Tests\Feature;

use App\Http\Middleware\MarketingOnlyReleaseGate;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class MarketingOnlyReleaseGateTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://wpcaregrid.com',
            'marketing_release.profile' => 'marketing_only',
            'marketing_release.enforcement_mode' => 'enforced',
            'marketing_release.staging_exempt' => false,
            'app.env' => 'production',
            'app.deployment_environment' => 'production',
        ]);
    }

    public function test_reviewed_marketing_legal_and_public_asset_gets_are_allowed(): void
    {
        $paths = [
            '/',
            '/pricing',
            '/en',
            '/en/',
            '/hu',
            '/hu/',
            '/en/pricing',
            '/hu/pricing',
            '/robots.txt',
            '/sitemap.xml',
            '/terms',
            '/privacy',
            '/cookie-policy',
            '/dpa',
            '/legal/history/550e8400-e29b-41d4-a716-446655440000',
            '/favicon.ico',
            '/favicon.svg',
            '/favicon-32x32.png',
            '/apple-touch-icon.png',
            '/build/assets/app-123abc.css',
            '/build/assets/app-123abc.js',
            '/build/assets/font-123abc.woff2',
        ];

        foreach ($paths as $path) {
            [$response, $dispatched] = $this->runGate($path);
            $this->assertTrue($dispatched, $path.' should continue to the public route/static handler');
            $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), $path);
        }
    }

    public function test_head_is_allowed_only_for_reviewed_public_get_paths(): void
    {
        [$publicResponse, $publicDispatched] = $this->runGate('/en/pricing', 'HEAD');
        $this->assertTrue($publicDispatched);
        $this->assertSame(Response::HTTP_NO_CONTENT, $publicResponse->getStatusCode());

        [$backendResponse, $backendDispatched] = $this->runGate('/checkout', 'HEAD');
        $this->assertFalse($backendDispatched);
        $this->assertSame(Response::HTTP_NOT_FOUND, $backendResponse->getStatusCode());
    }

    public function test_only_presentation_locale_and_currency_posts_continue_to_existing_web_guards(): void
    {
        foreach (['/locale' => 'locale.update', '/currency' => 'currency.update'] as $path => $routeName) {
            [$response, $dispatched] = $this->runGate($path, 'POST');
            $this->assertTrue($dispatched, $path);
            $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());

            $route = app('router')->getRoutes()->getByName($routeName);
            $this->assertInstanceOf(Route::class, $route);
            $this->assertContains('web', $route->gatherMiddleware(), $path.' must retain the web/CSRF middleware group');
            $this->assertContains('throttle:60,1', $route->gatherMiddleware(), $path.' must retain its rate limit');
        }

        foreach (['GET', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            [$response, $dispatched] = $this->runGate('/locale', $method);
            $this->assertFalse($dispatched, $method.' /locale must be denied');
            $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        }

        foreach (['/locale/', '/currency/'] as $path) {
            [$response, $dispatched] = $this->runGate($path, 'POST');
            $this->assertFalse($dispatched, $path.' must not be normalized into an allowed preference endpoint');
            $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        }
    }

    public function test_checkout_billing_authentication_owner_operator_connector_and_api_are_denied_before_dispatch(): void
    {
        $requests = [
            ['GET', '/checkout'],
            ['POST', '/checkout'],
            ['POST', '/checkout/promotion-preview'],
            ['GET', '/checkout/success'],
            ['POST', '/stripe/webhook'],
            ['GET', '/login'],
            ['GET', '/operator/login'],
            ['GET', '/owner/login'],
            ['POST', '/login'],
            ['GET', '/forgot-password'],
            ['POST', '/forgot-password'],
            ['GET', '/reset-password/token'],
            ['POST', '/reset-password'],
            ['POST', '/logout'],
            ['GET', '/account'],
            ['GET', '/subscription'],
            ['GET', '/billing'],
            ['GET', '/owner'],
            ['POST', '/owner/login'],
            ['GET', '/operator'],
            ['POST', '/operator/login'],
            ['POST', '/api/v1/connector/pair'],
            ['POST', '/api/v1/connector/heartbeat'],
            ['GET', '/api/v1/connector/jobs'],
            ['POST', '/api/v1/connector/inventory'],
            ['GET', '/up'],
        ];

        foreach ($requests as [$method, $path]) {
            [$response, $dispatched] = $this->runGate($path, $method);
            $this->assertFalse($dispatched, $method.' '.$path.' must be stopped before route/controller dispatch');
            $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $method.' '.$path);
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }
    }

    public function test_missing_and_invalid_profiles_fail_closed_to_marketing_only(): void
    {
        app('config')->set('marketing_release', []);
        [$missingResponse, $missingDispatched] = $this->runGate('/checkout', 'GET', profile: null, setProfile: false);
        $this->assertFalse($missingDispatched);
        $this->assertSame(Response::HTTP_NOT_FOUND, $missingResponse->getStatusCode());

        foreach (['', 'beta', 'INVITATION_BETA', true, null] as $invalidProfile) {
            [$response, $dispatched] = $this->runGate('/owner', 'GET', profile: $invalidProfile);
            $this->assertFalse($dispatched, 'Invalid profile must fail closed: '.var_export($invalidProfile, true));
            $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        }
    }

    public function test_invitation_beta_profile_alone_does_not_authorize_backend_access(): void
    {
        config(['marketing_release.profile' => 'invitation_beta']);

        [$publicResponse, $publicDispatched] = $this->runGate('/en/', 'GET', profile: 'invitation_beta');
        $this->assertTrue($publicDispatched);
        $this->assertSame(Response::HTTP_NO_CONTENT, $publicResponse->getStatusCode());

        foreach ([
            ['GET', '/checkout'],
            ['POST', '/checkout'],
            ['GET', '/account'],
            ['GET', '/owner'],
            ['GET', '/operator'],
            ['POST', '/stripe/webhook'],
        ] as [$method, $path]) {
            [$response, $dispatched] = $this->runGate($path, $method, profile: 'invitation_beta');
            $this->assertFalse($dispatched, 'Profile selection alone must not open '.$method.' '.$path);
            $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        }
    }

    public function test_only_exact_application_hosts_are_allowed_and_forwarded_host_cannot_widen_them(): void
    {
        foreach (['wpcaregrid.com', 'WWW.WPCAREGRID.COM'] as $host) {
            [$response, $dispatched] = $this->runGate('/en/', host: $host);
            $this->assertTrue($dispatched, $host);
            $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        }

        foreach ([
            'attacker.example',
            'wpcaregrid.com.evil.example',
            'wpcaregrid.com.',
            'wpcaregrid.com:443',
            '127.0.0.1',
            'localhost',
            'wpcaregrid.com,attacker.example',
        ] as $host) {
            [$response, $dispatched] = $this->runGate('/en/', host: $host);
            $this->assertFalse($dispatched, 'Unexpected Host must be denied: '.$host);
            $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        }

        [$spoofedHostResponse, $spoofedHostDispatched] = $this->runGate(
            '/en/',
            host: 'attacker.example',
            headers: ['X-Forwarded-Host' => 'wpcaregrid.com'],
        );
        $this->assertFalse($spoofedHostDispatched);
        $this->assertSame(Response::HTTP_NOT_FOUND, $spoofedHostResponse->getStatusCode());

        [$mismatchedForwardedResponse, $mismatchedForwardedDispatched] = $this->runGate(
            '/en/',
            host: 'wpcaregrid.com',
            headers: ['X-Forwarded-Host' => 'attacker.example'],
        );
        $this->assertFalse($mismatchedForwardedDispatched);
        $this->assertSame(Response::HTTP_NOT_FOUND, $mismatchedForwardedResponse->getStatusCode());

        [$standardForwardedResponse, $standardForwardedDispatched] = $this->runGate(
            '/en/',
            host: 'wpcaregrid.com',
            headers: ['Forwarded' => 'host=attacker.example;proto=https'],
        );
        $this->assertFalse($standardForwardedDispatched);
        $this->assertSame(Response::HTTP_NOT_FOUND, $standardForwardedResponse->getStatusCode());
    }

    public function test_forwarded_method_and_rewrite_headers_cannot_change_the_allowed_target(): void
    {
        foreach ([
            'X-HTTP-Method-Override' => 'GET',
            'X-Method-Override' => 'GET',
            'X-Original-URL' => '/en/',
            'X-Rewrite-URL' => '/en/',
            'X-Forwarded-Prefix' => '/en',
        ] as $header => $value) {
            [$response, $dispatched] = $this->runGate('/en/', 'GET', headers: [$header => $value]);
            $this->assertFalse($dispatched, $header.' must not alter route matching');
            $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        }

        [$response, $dispatched] = $this->runGate('/locale', 'POST', headers: ['X-HTTP-Method-Override' => 'GET']);
        $this->assertFalse($dispatched);
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function test_encoded_normalized_and_ambiguous_paths_are_rejected(): void
    {
        foreach ([
            '//en',
            '/en//pricing',
            '/en/../checkout',
            '/en/./pricing',
            '/en/%2e%2e/checkout',
            '/en%2fcheckout',
            '/%2fcheckout',
            '/en\\checkout',
            '/en;param=1',
            '/EN/',
            '/en/pricing/',
            '/build/manifest.json',
            '/build/assets/app.php',
            '/build/assets/app.js.map',
            '/owner/../en',
        ] as $path) {
            [$response, $dispatched] = $this->runGate($path);
            $this->assertFalse($dispatched, 'Ambiguous/unreviewed path must be denied: '.$path);
            $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $path);
        }
    }

    public function test_test_exemption_requires_all_three_exact_server_side_values(): void
    {
        $cases = [
            ['testing', 'testing', 'testing', true],
            ['testing', 'production', 'testing', false],
            ['production', 'testing', 'testing', false],
            ['testing', 'testing', 'enforced', false],
            ['testing', 'testing', 'unknown', false],
            ['testing', 'testing', null, false],
            ['unknown', 'testing', 'testing', false],
            ['testing', 'unknown', 'testing', false],
        ];

        foreach ($cases as [$appEnv, $deployment, $mode, $exempt]) {
            config([
                'app.env' => $appEnv,
                'app.deployment_environment' => $deployment,
                'marketing_release.test_app_environment' => $appEnv,
                'marketing_release.test_deployment_environment' => $deployment,
                'marketing_release.enforcement_mode' => $mode,
            ]);

            [$response, $dispatched] = $this->runGate('/checkout');
            $this->assertSame($exempt, $dispatched, implode('/', [$appEnv, $deployment, (string) $mode]));
            if (! $exempt) {
                $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
            }
        }
    }

    public function test_staging_exemption_requires_explicit_flag_and_exact_staging_identity(): void
    {
        $cases = [
            ['staging', 'staging', true, true],
            ['staging', 'production', true, false],
            ['production', 'staging', true, false],
            ['staging', 'staging', false, false],
            ['staging', 'unknown', true, false],
        ];

        foreach ($cases as [$appEnv, $deployment, $flag, $exempt]) {
            config([
                'app.env' => $appEnv,
                'app.deployment_environment' => $deployment,
                'marketing_release.enforcement_mode' => 'enforced',
                'marketing_release.staging_exempt' => $flag,
            ]);

            [$response, $dispatched] = $this->runGate('/checkout');
            $this->assertSame($exempt, $dispatched, implode('/', [$appEnv, $deployment, (string) $flag]));
            if (! $exempt) {
                $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
            }
        }
    }

    public function test_request_controlled_values_cannot_activate_the_test_exemption(): void
    {
        config([
            'app.env' => 'production',
            'app.deployment_environment' => 'production',
            'marketing_release.enforcement_mode' => 'enforced',
            'marketing_release.staging_exempt' => false,
            'marketing_release.profile' => 'marketing_only',
            'marketing_release.test_app_environment' => 'production',
            'marketing_release.test_deployment_environment' => 'production',
        ]);

        foreach ([
            ['X-Marketing-Release-Mode' => 'testing'],
            ['X-Deployment-Environment' => 'testing'],
            ['X-App-Environment' => 'testing'],
        ] as $headers) {
            [$response, $dispatched] = $this->runGate(
                '/checkout?profile=invitation_beta&mode=testing',
                headers: $headers,
            );
            $this->assertFalse($dispatched);
            $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        }
    }

    public function test_real_http_kernel_has_global_gate_after_security_headers_and_denies_registered_backend_routes(): void
    {
        config([
            'app.env' => 'production',
            'app.deployment_environment' => 'production',
            'marketing_release.enforcement_mode' => 'enforced',
            'marketing_release.staging_exempt' => false,
            'marketing_release.profile' => 'marketing_only',
            'marketing_release.test_app_environment' => 'production',
            'marketing_release.test_deployment_environment' => 'production',
            'app.url' => 'https://wpcaregrid.com',
        ]);

        $kernelMiddleware = app(\Illuminate\Contracts\Http\Kernel::class)->getGlobalMiddleware();
        $securityHeadersIndex = array_search(\App\Http\Middleware\SecurityHeaders::class, $kernelMiddleware, true);
        $gateIndex = array_search(MarketingOnlyReleaseGate::class, $kernelMiddleware, true);
        $this->assertNotFalse($securityHeadersIndex, 'SecurityHeaders must be globally registered');
        $this->assertNotFalse($gateIndex, 'MarketingOnlyReleaseGate must be globally registered');
        $this->assertGreaterThan($securityHeadersIndex, $gateIndex, 'Gate must run after SecurityHeaders');

        $requests = [
            ['GET', '/checkout'],
            ['POST', '/checkout'],
            ['POST', '/checkout/promotion-preview'],
            ['POST', '/stripe/webhook'],
            ['GET', '/login'],
            ['GET', '/operator/login'],
            ['GET', '/owner/login'],
            ['GET', '/forgot-password'],
            ['GET', '/account'],
            ['GET', '/account/billing'],
            ['GET', '/account/websites/1/backups'],
            ['POST', '/account/websites/1/backups/1/restore'],
            ['GET', '/operator'],
            ['GET', '/owner'],
            ['POST', '/api/v1/connector/pair'],
            ['POST', '/api/v1/connector/heartbeat'],
            ['GET', '/up'],
        ];

        foreach ($requests as [$method, $uri]) {
            $route = app('router')->getRoutes()->match(Request::create($uri, $method));
            $this->assertNotNull($route, $method.' '.$uri.' must match a registered route');
            $this->call($method, $uri, [], [], [], [
                'HTTP_HOST' => 'wpcaregrid.com',
                'HTTPS' => 'on',
            ])->assertNotFound();
        }
    }

    public function test_real_http_kernel_allows_public_requests_and_keeps_presentation_guards(): void
    {
        config([
            'app.env' => 'production',
            'app.deployment_environment' => 'production',
            'marketing_release.enforcement_mode' => 'enforced',
            'marketing_release.staging_exempt' => false,
            'marketing_release.profile' => 'marketing_only',
            'marketing_release.test_app_environment' => 'production',
            'marketing_release.test_deployment_environment' => 'production',
            'app.url' => 'https://wpcaregrid.com',
            'seo.indexing_enabled' => true,
            'seo.production_host' => 'wpcaregrid.com',
        ]);

        $this->call('GET', '/en/pricing', [], [], [], [
            'HTTP_HOST' => 'wpcaregrid.com',
            'HTTPS' => 'on',
        ])->assertOk();

        $locale = app('router')->getRoutes()->getByName('locale.update');
        $this->assertInstanceOf(Route::class, $locale);
        $this->assertContains('web', $locale->gatherMiddleware());
        $this->assertContains('throttle:60,1', $locale->gatherMiddleware());
        $webMiddleware = app('router')->getMiddlewareGroups()['web'];
        $this->assertContains(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, $webMiddleware);

        // Laravel's default test shortcut skips CSRF. Force the real middleware path here.
        $csrf = new class(app(), app(\Illuminate\Contracts\Encryption\Encrypter::class)) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };
        $this->app->instance(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, $csrf);
        $token = str_repeat('a', 40);
        $this->withSession(['_token' => $token]);

        $this->call('POST', '/locale', ['locale' => 'hu'], [], [], [
            'HTTP_HOST' => 'wpcaregrid.com',
            'HTTPS' => 'on',
            'HTTP_REFERER' => 'https://wpcaregrid.com/en/pricing',
        ])->assertStatus(419);

        $csrfServer = ['HTTP_HOST' => 'wpcaregrid.com', 'HTTPS' => 'on', 'HTTP_REFERER' => 'https://wpcaregrid.com/en/pricing', 'HTTP_X_CSRF_TOKEN' => $token];
        $this->call('POST', '/locale', ['locale' => 'hu'], [], [], $csrfServer)->assertRedirect();

        $this->call('POST', '/locale', ['locale' => 'invalid'], [], [], $csrfServer)->assertSessionHasErrors('locale');

        for ($attempt = 1; $attempt <= 60; $attempt++) {
            $this->call('POST', '/locale', ['locale' => 'en'], [], [], [
                'HTTP_HOST' => 'wpcaregrid.com',
                'HTTPS' => 'on',
                'REMOTE_ADDR' => '198.51.100.42',
                'HTTP_REFERER' => 'https://wpcaregrid.com/en/pricing',
                'HTTP_X_CSRF_TOKEN' => $token,
            ])->assertRedirect();
        }
        $this->call('POST', '/locale', ['locale' => 'en'], [], [], [
            'HTTP_HOST' => 'wpcaregrid.com',
            'HTTPS' => 'on',
            'REMOTE_ADDR' => '198.51.100.42',
            'HTTP_REFERER' => 'https://wpcaregrid.com/en/pricing',
            'HTTP_X_CSRF_TOKEN' => $token,
        ])->assertTooManyRequests();

        $this->call('GET', '/pricing', [], [], [], [
            'HTTP_HOST' => 'attacker.example',
            'HTTPS' => 'on',
        ])->assertNotFound();
        $this->call('GET', '/pricing', [], [], [], [
            'HTTP_HOST' => 'wpcaregrid.com',
            'HTTP_X_FORWARDED_HOST' => 'attacker.example',
            'HTTPS' => 'on',
        ])->assertNotFound();
    }

    public function test_invalid_profile_and_invitation_beta_remain_fail_closed_in_http_kernel(): void
    {
        foreach ([null, 'unexpected'] as $profile) {
            config([
                'app.env' => 'production',
                'app.deployment_environment' => 'production',
                'marketing_release.enforcement_mode' => 'enforced',
                'marketing_release.staging_exempt' => false,
                'marketing_release.profile' => $profile,
            ]);
            $this->call('GET', '/checkout', [], [], [], [
                'HTTP_HOST' => 'wpcaregrid.com',
                'HTTPS' => 'on',
            ])->assertNotFound();
        }

        config([
            'app.env' => 'production',
            'app.deployment_environment' => 'production',
            'marketing_release.enforcement_mode' => 'enforced',
            'marketing_release.staging_exempt' => false,
            'marketing_release.profile' => 'invitation_beta',
        ]);
        $this->call('GET', '/checkout', [], [], [], [
            'HTTP_HOST' => 'wpcaregrid.com',
            'HTTPS' => 'on',
        ])->assertNotFound();
    }
    private function runGate(
        string $uri,
        string $method = 'GET',
        string $host = 'wpcaregrid.com',
        array $headers = [],
        mixed $profile = 'marketing_only',
        bool $setProfile = true,
    ): array {
        if ($setProfile) {
            config(['marketing_release.profile' => $profile]);
        }

        $requestUriForFactory = str_contains($uri, '\\') ? '/en/checkout' : $uri;
        $request = Request::create($requestUriForFactory, $method, [], [], [], [
            'HTTP_HOST' => $host,
            'HTTPS' => 'on',
        ]);
        if ($requestUriForFactory !== $uri) {
            // Symfony rejects malformed backslash URIs at request construction;
            // set the raw server target afterwards to exercise the gate's guard.
            $request->server->set('REQUEST_URI', $uri);
        }
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        $dispatched = false;
        $response = (new MarketingOnlyReleaseGate)->handle($request, function () use (&$dispatched): Response {
            $dispatched = true;

            return response('', Response::HTTP_NO_CONTENT);
        });

        return [$response, $dispatched];
    }
}

