<?php

namespace Tests\Feature;

use App\Http\Middleware\MarketingOnlyReleaseGate;
use App\Release\StagingActiveProfile;
use App\Release\StagingDatabaseProbe;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The staging_active profile: the full application on the isolated staging-next
 * origin, nothing else. The gate must stay closed unless the whole environment is
 * proven, and the parts that reach outside the staging stack must stay closed
 * until their isolation is proven or the Owner opens them.
 */
class StagingActiveProfileTest extends TestCase
{
    private const ENV_KEYS = [
        'MARKETING_RELEASE_PROFILE', 'MARKETING_RELEASE_ENFORCEMENT_MODE', 'APP_ENV', 'CAREGRID_DEPLOYMENT_ENV',
        'APP_URL', 'APP_DEBUG', 'DB_CONNECTION', 'DB_DATABASE', 'STRIPE_ALLOW_LIVE_MODE', 'STAGING_MAIL_ALLOWLIST',
        'STAGING_ACTIVE_CONNECTOR_API', 'STAGING_ACTIVE_EXTERNAL_SERVICES', 'STAGING_ADMIN_QA_ENABLED',
    ];

    /** @var array<string, string|null> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->applyBaseline();
    }

    /** A fully proven staging-next environment; tests then break exactly one thing. */
    private function applyBaseline(): void
    {
        config([
            'marketing_release.profile' => 'staging_active',
            'marketing_release.requested_profile' => 'staging_active',
            'marketing_release.staging_active_enabled' => true,
            'marketing_release.enforcement_mode' => 'enforced',
            'marketing_release.test_app_environment' => 'staging',
            'marketing_release.test_deployment_environment' => 'staging_next',
            'marketing_release.staging_active_features' => ['connector_api' => false, 'external_services' => false],
            'marketing_release.staging_active_db_uuid_sha256' => null,
            'marketing_release.staging_mail_allowlist' => [],
            'app.env' => 'staging',
            'app.deployment_environment' => 'staging_next',
            'app.url' => 'https://staging-next.wpcaregrid.com',
            'app.debug' => false,
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'wpcg_staging_next',
            'database.connections.mysql.host' => '127.0.0.1',
            'services.stripe.allow_live_mode' => false,
            'services.stripe.secret' => 'sk_test_unit',
            'services.stripe.key' => 'pk_test_unit',
            'services.stripe.webhook_secret' => 'whsec_unit',
        ]);
        $this->probe('wpcg_staging_next');
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            $this->writeEnv($key, $value);
        }

        parent::tearDown();
    }

    // ---- configuration eligibility (production can never enable it) ----

    public function test_profile_is_eligible_only_with_the_exact_staging_next_identity(): void
    {
        $valid = $this->stagingEnv();
        $this->assertSame('staging_active', $this->loadConfig($valid)['profile']);
        $this->assertTrue($this->loadConfig($valid)['staging_active_enabled']);

        $deviations = [
            'production app env' => ['APP_ENV' => 'production'],
            'local app env' => ['APP_ENV' => 'local'],
            'production deployment' => ['CAREGRID_DEPLOYMENT_ENV' => 'production'],
            'legacy staging deployment' => ['CAREGRID_DEPLOYMENT_ENV' => 'staging'],
            'production url' => ['APP_URL' => 'https://wpcaregrid.com'],
            'www production url' => ['APP_URL' => 'https://www.wpcaregrid.com'],
            'legacy staging url' => ['APP_URL' => 'https://staging.wpcaregrid.com'],
            'http url' => ['APP_URL' => 'http://staging-next.wpcaregrid.com'],
            'url with path' => ['APP_URL' => 'https://staging-next.wpcaregrid.com/'],
            'debug on' => ['APP_DEBUG' => 'true'],
            'enforcement testing' => ['MARKETING_RELEASE_ENFORCEMENT_MODE' => 'testing'],
            'sqlite database' => ['DB_CONNECTION' => 'sqlite'],
            'legacy database' => ['DB_DATABASE' => 'wpcaregrid'],
            'production database' => ['DB_DATABASE' => 'wpcg_prod_main'],
            'missing database' => ['DB_DATABASE' => ''],
            'live stripe allowed' => ['STRIPE_ALLOW_LIVE_MODE' => 'true'],
            'misspelled profile' => ['MARKETING_RELEASE_PROFILE' => 'Staging_Active'],
            'other profile' => ['MARKETING_RELEASE_PROFILE' => 'marketing_only'],
        ];
        foreach ($deviations as $label => $override) {
            $config = $this->loadConfig($override + $valid);
            $this->assertFalse($config['staging_active_enabled'], $label);
            $this->assertSame('marketing_only', $config['profile'], $label.' must fall back to marketing_only');
        }
    }

    public function test_requested_profile_is_recorded_even_when_refused(): void
    {
        $config = $this->loadConfig(['APP_ENV' => 'production'] + $this->stagingEnv());

        $this->assertSame('marketing_only', $config['profile']);
        $this->assertSame('staging_active', $config['requested_profile']);
    }

    public function test_feature_switches_default_to_closed_and_mail_allowlist_is_normalised(): void
    {
        $config = $this->loadConfig($this->stagingEnv());
        $this->assertFalse($config['staging_active_features']['connector_api']);
        $this->assertFalse($config['staging_active_features']['external_services']);
        $this->assertSame([], $config['staging_mail_allowlist']);

        $config = $this->loadConfig([
            'STAGING_ACTIVE_CONNECTOR_API' => 'true',
            'STAGING_MAIL_ALLOWLIST' => ' Owner@Example.test , @sink.test,, ',
        ] + $this->stagingEnv());
        $this->assertTrue($config['staging_active_features']['connector_api']);
        $this->assertFalse($config['staging_active_features']['external_services']);
        $this->assertSame(['owner@example.test', '@sink.test'], $config['staging_mail_allowlist']);
    }

    // ---- the gate: full application on the proven staging-next origin ----

    public function test_full_application_is_reachable_when_the_environment_is_proven(): void
    {
        $requests = [
            ['GET', '/staff/activate/'.str_repeat('a', 64)],
            ['POST', '/staff/activate'],
            ['GET', '/owner/login'], ['POST', '/owner/login'], ['POST', '/owner/logout'],
            ['GET', '/owner'], ['GET', '/owner/settings'], ['GET', '/owner/settings/mail'], ['PUT', '/owner/settings/mail'],
            ['GET', '/owner/customers'], ['GET', '/owner/customers/1'], ['PUT', '/owner/customers/1'],
            ['GET', '/owner/operators'], ['POST', '/owner/operators'], ['PUT', '/owner/operators/1/permissions'],
            ['GET', '/owner/pricing'], ['PUT', '/owner/pricing/prices/1'], ['POST', '/owner/pricing/prices/1/publish'],
            ['GET', '/owner/content'], ['PUT', '/owner/content/hero/en'], ['GET', '/owner/seo'], ['GET', '/owner/integrations'],
            ['POST', '/owner/integrations'], ['GET', '/owner/system'], ['GET', '/owner/websites'],
            ['GET', '/login'], ['POST', '/login'], ['GET', '/forgot-password'], ['POST', '/forgot-password'],
            ['GET', '/account'], ['GET', '/account/websites'], ['GET', '/account/websites/new'], ['POST', '/account/websites'],
            ['POST', '/account/websites/1/pairing-code'], ['POST', '/account/websites/1/compatibility-check'],
            ['GET', '/operator/login'], ['POST', '/operator/login'], ['GET', '/operator'], ['GET', '/operator/websites'],
            ['GET', '/'], ['GET', '/en'], ['GET', '/hu/pricing'], ['GET', '/up'], ['POST', '/locale'],
            ['GET', '/owner/coupons'], ['GET', '/owner/offers'],
        ];

        foreach ($requests as [$method, $path]) {
            [$response, $dispatched] = $this->runGate($path, $method);
            $this->assertTrue($dispatched, "{$method} {$path} must reach the application");
            $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), "{$method} {$path}");
        }
    }

    public function test_every_identity_deviation_closes_the_whole_site(): void
    {
        $deviations = [
            'production app env' => ['app.env' => 'production'],
            'legacy deployment id' => ['app.deployment_environment' => 'staging'],
            'production deployment id' => ['app.deployment_environment' => 'production'],
            'production url' => ['app.url' => 'https://wpcaregrid.com'],
            'legacy staging url' => ['app.url' => 'https://staging.wpcaregrid.com'],
            'debug' => ['app.debug' => true],
            'not eligible at config load' => ['marketing_release.staging_active_enabled' => false],
            'enforcement testing' => ['marketing_release.enforcement_mode' => 'testing'],
            'sqlite default' => ['database.default' => 'sqlite'],
            'legacy database' => ['database.connections.mysql.database' => 'wpcaregrid'],
            'production database' => ['database.connections.mysql.database' => 'wpcg_prod_main'],
            'remote database host' => ['database.connections.mysql.host' => '10.0.0.5'],
            'production database host' => ['database.connections.mysql.host' => 'db.wpcaregrid.com'],
            'live stripe allowed' => ['services.stripe.allow_live_mode' => true],
        ];

        foreach ($deviations as $label => $override) {
            config($override);
            foreach (['/', '/up', '/owner', '/owner/login', '/staff/activate/'.str_repeat('b', 64)] as $path) {
                [$response, $dispatched] = $this->runGate($path);
                $this->assertFalse($dispatched, "{$label}: {$path} must stay closed");
                $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), "{$label}: {$path}");
                $this->assertSame('', $response->getContent());
            }
            $this->applyBaseline();
        }
    }

    public function test_production_identity_with_the_profile_forced_on_stays_closed(): void
    {
        config([
            'app.env' => 'production',
            'app.deployment_environment' => 'production',
            'app.url' => 'https://wpcaregrid.com',
            'marketing_release.staging_active_enabled' => false,
            'database.connections.mysql.database' => 'wpcg_prod_main',
        ]);

        foreach (['/owner/login', '/checkout', '/api/v1/connector/pair', '/staff/activate/'.str_repeat('c', 64)] as $path) {
            [$response, $dispatched] = $this->runGate($path, 'GET', 'wpcaregrid.com');
            $this->assertFalse($dispatched, $path);
            $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $path);
        }
    }

    public function test_the_live_database_must_be_the_staging_database(): void
    {
        $this->probe(null);
        [$response, $dispatched] = $this->runGate('/owner/login');
        $this->assertFalse($dispatched, 'an unreadable connection is not an identity');
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());

        foreach (['wpcaregrid', 'wpcg_prod_main', 'wpcg_staging_next_restore', 'WPCG_STAGING_NEXT', ''] as $other) {
            $this->probe($other);
            [, $dispatched] = $this->runGate('/owner/login');
            $this->assertFalse($dispatched, 'database '.var_export($other, true));
        }

        $this->probe('wpcg_staging_next');
        [, $dispatched] = $this->runGate('/owner/login');
        $this->assertTrue($dispatched);
    }

    public function test_an_optional_server_identity_pin_must_match_the_live_server(): void
    {
        $pin = hash('sha256', 'expected-server-uuid');
        config(['marketing_release.staging_active_db_uuid_sha256' => $pin]);

        $this->probe('wpcg_staging_next', hash('sha256', 'another-server'));
        [, $dispatched] = $this->runGate('/owner/login');
        $this->assertFalse($dispatched);

        $this->probe('wpcg_staging_next', null);
        [, $dispatched] = $this->runGate('/owner/login');
        $this->assertFalse($dispatched);

        config(['marketing_release.staging_active_db_uuid_sha256' => strtoupper($pin)]);
        $this->probe('wpcg_staging_next', $pin);
        [, $dispatched] = $this->runGate('/owner/login');
        $this->assertTrue($dispatched, 'the configured pin is case-insensitive');
    }

    public function test_only_the_exact_https_staging_next_origin_is_served(): void
    {
        $closed = [
            'plain http' => ['/owner/login', 'GET', 'staging-next.wpcaregrid.com', [], false],
            'production host' => ['/owner/login', 'GET', 'wpcaregrid.com', [], true],
            'www host' => ['/owner/login', 'GET', 'www.wpcaregrid.com', [], true],
            'legacy staging host' => ['/owner/login', 'GET', 'staging.wpcaregrid.com', [], true],
            'ip host' => ['/owner/login', 'GET', '203.0.113.10', [], true],
            'host with port' => ['/owner/login', 'GET', 'staging-next.wpcaregrid.com:8443', [], true],
            'subdomain' => ['/owner/login', 'GET', 'evil.staging-next.wpcaregrid.com', [], true],
            'forwarded host' => ['/owner/login', 'GET', 'staging-next.wpcaregrid.com', ['X-Forwarded-Host' => 'wpcaregrid.com'], true],
            'forwarded header' => ['/owner/login', 'GET', 'staging-next.wpcaregrid.com', ['Forwarded' => 'host=wpcaregrid.com'], true],
            'method override header' => ['/owner/login', 'POST', 'staging-next.wpcaregrid.com', ['X-HTTP-Method-Override' => 'DELETE'], true],
            'original url header' => ['/owner/login', 'GET', 'staging-next.wpcaregrid.com', ['X-Original-URL' => '/owner'], true],
            'double slash' => ['//owner/login', 'GET', 'staging-next.wpcaregrid.com', [], true],
            'dot segment' => ['/owner/../owner/login', 'GET', 'staging-next.wpcaregrid.com', [], true],
            'encoded path' => ['/owner%2flogin', 'GET', 'staging-next.wpcaregrid.com', [], true],
            'path parameter' => ['/owner;x/login', 'GET', 'staging-next.wpcaregrid.com', [], true],
        ];

        foreach ($closed as $label => [$path, $method, $host, $headers, $https]) {
            [$response, $dispatched] = $this->runGate($path, $method, $host, $headers, $https);
            $this->assertFalse($dispatched, $label);
            $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $label);
        }

        [, $dispatched] = $this->runGate('/owner/login', 'GET', 'STAGING-NEXT.wpcaregrid.com');
        $this->assertTrue($dispatched, 'host names are case-insensitive');
    }

    public function test_the_profile_is_not_an_authorisation_and_leaves_route_guards_in_place(): void
    {
        $router = app('router');
        foreach ([
            'owner.dashboard' => 'owner',
            'operator.dashboard' => 'operator',
            'account.dashboard' => 'account',
        ] as $name => $guard) {
            $route = $router->getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $middleware = implode(',', $route->gatherMiddleware());
            $this->assertMatchesRegularExpression('/auth|owner|operator|customer|account/i', $middleware, $name.' keeps its own guard');
        }
        foreach (['owner.login.store', 'operator.login.store', 'staff.activate.store', 'checkout.store'] as $name) {
            $this->assertContains('web', app('router')->getRoutes()->getByName($name)->gatherMiddleware(), $name.' keeps CSRF/web group');
        }
    }

    // ---- Stripe: test mode only ----

    public function test_stripe_routes_open_only_with_proven_test_mode_keys(): void
    {
        $stripe = [
            ['GET', '/checkout'], ['POST', '/checkout'], ['POST', '/checkout/promotion-preview'], ['GET', '/checkout/success'],
            ['GET', '/checkout/cancel/abc'], ['POST', '/stripe/webhook'], ['GET', '/account/billing'],
            ['POST', '/account/billing/payment-method'], ['POST', '/account/subscriptions/1/cancel'],
            ['POST', '/account/subscriptions/1/change'], ['POST', '/account/offers/1/pay'], ['POST', '/account/offers/1/accept'], ['POST', '/account/websites/purchase'],
            ['POST', '/owner/pricing/verify-stripe'], ['PUT', '/owner/pricing/prices/1'], ['POST', '/owner/pricing/prices/1/publish'], ['POST', '/owner/coupons'], ['PUT', '/owner/coupons/1'],
            ['DELETE', '/owner/coupons/1'], ['POST', '/owner/offers'], ['POST', '/owner/offers/1/send'],
        ];
        foreach ($stripe as [$method, $path]) {
            [, $dispatched] = $this->runGate($path, $method);
            $this->assertTrue($dispatched, "test mode proven: {$method} {$path}");
        }

        $closedStates = [
            'live secret' => ['services.stripe.secret' => 'sk_live_unit'],
            'restricted live secret' => ['services.stripe.secret' => 'rk_live_unit'],
            'unknown secret' => ['services.stripe.secret' => 'not-a-key'],
            'missing secret' => ['services.stripe.secret' => null],
            'empty secret' => ['services.stripe.secret' => ''],
            'live publishable key' => ['services.stripe.key' => 'pk_live_unit'],
        ];
        foreach ($closedStates as $label => $override) {
            $this->applyBaseline();
            config($override);
            foreach ($stripe as [$method, $path]) {
                [$response, $dispatched] = $this->runGate($path, $method);
                $this->assertFalse($dispatched, "{$label}: {$method} {$path}");
                $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
            }
            // Everything that is not Stripe keeps working.
            [, $dispatched] = $this->runGate('/owner/login');
            $this->assertTrue($dispatched, $label.': owner login is not a Stripe route');
            [, $dispatched] = $this->runGate('/owner/pricing');
            $this->assertTrue($dispatched, $label.': pricing management is not a Stripe route');
            foreach ([['GET', '/owner/pricing/prices/1'], ['POST', '/owner/pricing/prices/1/discard']] as [$method, $path]) {
                [, $dispatched] = $this->runGate($path, $method);
                $this->assertTrue($dispatched, $label.': '.$method.' '.$path.' is local only');
            }
        }
    }

    public function test_owner_coupon_and_offer_pages_stay_readable_when_stripe_is_closed(): void
    {
        config(['services.stripe.secret' => null]);

        foreach (['/owner/coupons', '/owner/coupons/create', '/owner/coupons/1', '/owner/offers', '/owner/offers/1'] as $path) {
            [, $dispatched] = $this->runGate($path);
            $this->assertTrue($dispatched, 'GET '.$path);
        }
        foreach (['POST' => '/owner/coupons', 'PUT' => '/owner/coupons/1', 'POST ' => '/owner/offers/1/send'] as $method => $path) {
            [, $dispatched] = $this->runGate($path, trim($method));
            $this->assertFalse($dispatched, trim($method).' '.$path);
        }
    }

    public function test_webhook_additionally_needs_its_signing_secret(): void
    {
        config(['services.stripe.webhook_secret' => null]);

        [, $dispatched] = $this->runGate('/stripe/webhook', 'POST');
        $this->assertFalse($dispatched);
        [, $dispatched] = $this->runGate('/checkout');
        $this->assertTrue($dispatched, 'checkout does not need the webhook secret');
    }

    // ---- Connector API and external services: closed until opened ----

    public function test_connector_api_is_closed_until_the_owner_opens_it(): void
    {
        $paths = [
            ['POST', '/api/v1/connector/pair'], ['POST', '/api/v1/connector/heartbeat'], ['GET', '/api/v1/connector/jobs'],
            ['PUT', '/api/v1/connector/backups/1/objects/files.zip'], ['GET', '/API/V1/Connector/jobs'],
            ['GET', '/api/v1/connector/'], ['GET', '/api/v1/connector'],
        ];
        foreach ($paths as [$method, $path]) {
            [$response, $dispatched] = $this->runGate($path, $method);
            $this->assertFalse($dispatched, "{$method} {$path}");
            $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        }

        config(['marketing_release.staging_active_features.connector_api' => true]);
        foreach ($paths as [$method, $path]) {
            [, $dispatched] = $this->runGate($path, $method);
            $this->assertTrue($dispatched, "opened: {$method} {$path}");
        }

        // Opening one feature never opens another.
        [, $dispatched] = $this->runGate('/owner/dns/cloudflare');
        $this->assertFalse($dispatched);
    }

    public function test_external_service_operations_are_closed_until_the_owner_opens_them(): void
    {
        $external = [
            ['GET', '/owner/dns/cloudflare'], ['POST', '/owner/dns/cloudflare/records/apply'],
            ['POST', '/owner/dns/cloudflare/infrastructure/apply'], ['GET', '/owner/dns/cloudflare/zones/abc'],
            ['GET', '/owner/infrastructure/platform-backups'], ['POST', '/owner/infrastructure/platform-backups/run'],
            ['POST', '/owner/infrastructure/platform-backups/test-connection'],
            ['POST', '/owner/billing/synthetic-szamlazz-test'], ['POST', '/owner/billing/synthetic-szamlazz-test/1/submit'],
            ['POST', '/owner/billing/m11-lifecycle-validation'], ['POST', '/owner/billing/invoice-intents/1/reconcile'],
            ['POST', '/owner/integrations/ai/openai/health'],
            ['POST', '/account/profile/vat-verification'], ['POST', '/owner/customers/1/vat-verification/recheck'],
        ];
        foreach ($external as [$method, $path]) {
            [$response, $dispatched] = $this->runGate($path, $method);
            $this->assertFalse($dispatched, "{$method} {$path}");
            $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        }

        // Local-only pages around them stay usable.
        foreach ([['GET', '/owner/billing/invoice-intents'], ['POST', '/owner/billing/invoice-intents/1/local-dry-run'], ['GET', '/owner/integrations']] as [$method, $path]) {
            [, $dispatched] = $this->runGate($path, $method);
            $this->assertTrue($dispatched, "{$method} {$path}");
        }

        config(['marketing_release.staging_active_features.external_services' => true]);
        foreach ($external as [$method, $path]) {
            [, $dispatched] = $this->runGate($path, $method);
            $this->assertTrue($dispatched, "opened: {$method} {$path}");
        }
    }

    public function test_other_profiles_are_unchanged(): void
    {
        foreach (['marketing_only', 'invitation_beta'] as $profile) {
            config(['marketing_release.profile' => $profile, 'app.url' => 'https://wpcaregrid.com', 'app.env' => 'production', 'app.deployment_environment' => 'production']);
            foreach (['/owner/login', '/checkout', '/api/v1/connector/pair', '/staff/activate/'.str_repeat('d', 64), '/account'] as $path) {
                [, $dispatched] = $this->runGate($path, 'GET', 'wpcaregrid.com');
                $this->assertFalse($dispatched, "{$profile}: {$path}");
            }
            [, $dispatched] = $this->runGate('/pricing', 'GET', 'wpcaregrid.com');
            $this->assertTrue($dispatched, "{$profile}: /pricing");
        }
    }

    public function test_gate_reports_reason_codes_only(): void
    {
        config(['services.stripe.secret' => 'sk_live_super_secret_value', 'services.stripe.key' => 'pk_live_other_value']);
        $profile = app(StagingActiveProfile::class);

        $problems = $profile->stripeProblems(true);
        $this->assertContains('stripe_secret_not_test', $problems);
        $this->assertContains('stripe_publishable_not_test', $problems);
        $this->assertSame([], array_filter($problems, fn (string $code): bool => preg_match('/^[a-z_]+$/', $code) !== 1));
        $this->assertStringNotContainsString('super_secret', json_encode($problems));
    }

    /**
     * Every real route is pushed through the classifier. A new route that lands in (or
     * out of) a closed group changes this list, so the change has to be reviewed: the
     * Owner, Operator, Customer, login and activation areas must stay in no group.
     */
    public function test_closed_groups_match_the_real_route_inventory(): void
    {
        $profile = app(StagingActiveProfile::class);
        $closed = [];
        foreach (app('router')->getRoutes() as $route) {
            $path = '/'.ltrim((string) preg_replace('/\{[^}]+\??\}/', '1', $route->uri()), '/');
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $group = $profile->groupFor($path, $method);
                if ($group !== null) {
                    $closed[] = $group.' '.$method.' '.$route->uri();
                }
            }
        }
        sort($closed);

        $this->assertSame(self::CLOSED_ROUTES, $closed);
        foreach ($closed as $entry) {
            $this->assertDoesNotMatchRegularExpression('~ (?:GET|POST) (?:owner|operator)/login$|staff/activate|^\S+ \S+ (?:login|logout)$~', $entry);
        }
    }

    private const CLOSED_ROUTES = [
        'connector GET api/v1/connector/backups/{backup}/objects/{name}',
        'connector GET api/v1/connector/backups/{backup}/objects/{name}/content',
        'connector GET api/v1/connector/jobs',
        'connector POST api/v1/connector/admin-access/claim',
        'connector POST api/v1/connector/admin-access/{uuid}/ended',
        'connector POST api/v1/connector/backups/{backup}/finalize',
        'connector POST api/v1/connector/backups/{backup}/objects/{name}/complete',
        'connector POST api/v1/connector/disconnect',
        'connector POST api/v1/connector/heartbeat',
        'connector POST api/v1/connector/inventory',
        'connector POST api/v1/connector/jobs/{job}/result',
        'connector POST api/v1/connector/pair',
        'connector PUT api/v1/connector/backups/{backup}/objects/{name}',
        'external GET owner/billing/m11-lifecycle-validation',
        'external GET owner/billing/synthetic-szamlazz-test',
        'external GET owner/billing/synthetic-szamlazz-test/{run}/pdf',
        'external GET owner/dns/cloudflare',
        'external GET owner/dns/cloudflare/infrastructure',
        'external GET owner/dns/cloudflare/zones/{zone}',
        'external GET owner/infrastructure/platform-backups',
        'external POST account/profile/vat-verification',
        'external POST owner/billing/invoice-intents/{invoiceIntent}/reconcile',
        'external POST owner/billing/m11-lifecycle-validation',
        'external POST owner/billing/synthetic-szamlazz-test',
        'external POST owner/billing/synthetic-szamlazz-test/{run}/cancel',
        'external POST owner/billing/synthetic-szamlazz-test/{run}/reconcile',
        'external POST owner/billing/synthetic-szamlazz-test/{run}/submit',
        'external POST owner/customers/{customer}/vat-verification/recheck',
        'external POST owner/dns/cloudflare/infrastructure/apply',
        'external POST owner/dns/cloudflare/infrastructure/plan',
        'external POST owner/dns/cloudflare/records/apply',
        'external POST owner/dns/cloudflare/records/delete/apply',
        'external POST owner/dns/cloudflare/records/delete/plan',
        'external POST owner/dns/cloudflare/records/plan',
        'external POST owner/infrastructure/platform-backups/run',
        'external POST owner/infrastructure/platform-backups/test-connection',
        'external POST owner/integrations/ai/{provider}/health',
        'external PUT owner/infrastructure/platform-backups/settings',
        'stripe DELETE owner/coupons/{coupon}',
        'stripe GET account/billing',
        'stripe GET account/subscriptions/{subscription}',
        'stripe GET checkout',
        'stripe GET checkout/cancel/{intent}',
        'stripe GET checkout/success',
        'stripe POST account/billing/payment-method',
        'stripe POST account/offers/{offer}/accept',
        'stripe POST account/offers/{offer}/pay',
        'stripe POST account/subscriptions/{subscription}/cancel',
        'stripe POST account/subscriptions/{subscription}/change',
        'stripe POST account/subscriptions/{subscription}/reactivate',
        'stripe POST account/websites/purchase',
        'stripe POST checkout',
        'stripe POST checkout/promotion-preview',
        'stripe POST owner/coupons',
        'stripe POST owner/coupons/{coupon}/activate',
        'stripe POST owner/coupons/{coupon}/deactivate',
        'stripe POST owner/offers',
        'stripe POST owner/offers/{offer}/cancel',
        'stripe POST owner/offers/{offer}/complete',
        'stripe POST owner/offers/{offer}/send',
        'stripe POST owner/pricing/prices/{price}/publish',
        'stripe POST owner/pricing/verify-stripe',
        'stripe POST stripe/webhook',
        'stripe PUT owner/coupons/{coupon}',
        'stripe PUT owner/offers/{offer}',
        'stripe PUT owner/pricing/prices/{price}',
    ];

    // ---- helpers ----

    /** @return array<string, string> */
    private function stagingEnv(): array
    {
        return [
            'MARKETING_RELEASE_PROFILE' => 'staging_active',
            'MARKETING_RELEASE_ENFORCEMENT_MODE' => 'enforced',
            'APP_ENV' => 'staging',
            'CAREGRID_DEPLOYMENT_ENV' => 'staging_next',
            'APP_URL' => 'https://staging-next.wpcaregrid.com',
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'wpcg_staging_next',
            'STRIPE_ALLOW_LIVE_MODE' => 'false',
            'STAGING_MAIL_ALLOWLIST' => '',
            'STAGING_ACTIVE_CONNECTOR_API' => 'false',
            'STAGING_ACTIVE_EXTERNAL_SERVICES' => 'false',
            'STAGING_ADMIN_QA_ENABLED' => 'false',
        ];
    }

    /**
     * Evaluates config/marketing_release.php under the given environment.
     *
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    private function loadConfig(array $env): array
    {
        foreach (self::ENV_KEYS as $key) {
            if (! array_key_exists($key, $this->savedEnv)) {
                $current = getenv($key);
                $this->savedEnv[$key] = $current === false ? null : $current;
            }
            $this->writeEnv($key, $env[$key] ?? null);
        }

        return require base_path('config/marketing_release.php');
    }

    private function writeEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function probe(?string $database, ?string $uuidHash = null): void
    {
        $this->app->instance(StagingDatabaseProbe::class, new class($database, $uuidHash) implements StagingDatabaseProbe
        {
            public function __construct(private readonly ?string $database, private readonly ?string $uuid) {}

            public function currentDatabase(): ?string
            {
                return $this->database;
            }

            public function serverUuidSha256(): ?string
            {
                return $this->uuid;
            }
        });
        $this->app->forgetInstance(StagingActiveProfile::class);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{0: Response, 1: bool}
     */
    private function runGate(
        string $uri,
        string $method = 'GET',
        string $host = 'staging-next.wpcaregrid.com',
        array $headers = [],
        bool $https = true,
    ): array {
        $request = Request::create(str_contains($uri, '\\') ? '/x' : $uri, $method, [], [], [], [
            'HTTP_HOST' => $host,
            'HTTPS' => $https ? 'on' : 'off',
        ]);
        if (str_contains($uri, '//') || str_contains($uri, '..') || str_contains($uri, '%') || str_contains($uri, ';')) {
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
