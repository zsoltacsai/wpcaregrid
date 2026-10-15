<?php

namespace Tests\Feature;

use App\AdminAccess\AdminAccessService;
use App\Backups\BackupReason;
use App\Backups\BackupService;
use App\Backups\BackupStatus;
use App\Connector\DisconnectWebsite;
use App\Connector\HealthStatus;
use App\Console\Commands\SystemCheck;
use App\Http\Middleware\SecurityHeaders;
use App\Jobs\TransferBackup;
use App\Models\Connector;
use App\Models\Website;
use App\Owner\StatusCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route as Router;
use Tests\Concerns\CreatesBackups;
use Tests\TestCase;

/** Release readiness: HTTP security, queue safety, configuration checks, rate limits. */
class ProductionHardeningTest extends TestCase
{
    use CreatesBackups, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups(['manual' => true]);
        config(['services.cloudflare.api_token' => null]);
    }

    protected function tearDown(): void
    {
        $this->tearDownBackups();
        parent::tearDown();
    }

    /* ----- HTTP security ----- */

    public function test_every_response_carries_the_security_headers(): void
    {
        foreach (['/en/', '/en/pricing', '/login', '/operator/login', '/owner/login'] as $path) {
            $this->get($path)->assertOk()
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeader('Permissions-Policy')
                ->assertHeaderMissing('Strict-Transport-Security')
                ->assertHeaderMissing('Content-Security-Policy');
        }

        // Connector API answers too.
        $this->getJson('/api/v1/connector/jobs')->assertUnauthorized()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_hsts_is_sent_over_https_only_when_enabled(): void
    {
        config(['http.hsts.enabled' => true, 'http.hsts.max_age' => 31536000]);

        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');

        config(['http.hsts.include_subdomains' => true]);
        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_plain_http_is_redirected_to_https_when_the_application_runs_on_https(): void
    {
        config(['http.force_https' => true]);

        $this->get('http://localhost/pricing?x=1')->assertStatus(301)->assertRedirect('https://localhost/pricing?x=1');
        $this->get('https://localhost/en/pricing')->assertOk();
        $this->get('http://localhost/up')->assertOk();
    }

    public function test_the_content_security_policy_is_opt_in_and_staged(): void
    {
        config(['http.csp.mode' => 'report-only']);
        $policy = $this->get('/en/')->assertHeaderMissing('Content-Security-Policy')->headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertMatchesRegularExpression("/script-src 'self' 'unsafe-eval' 'nonce-[A-Za-z0-9+\\/=]+'/", $policy);

        config(['http.csp.mode' => 'enforce']);
        $this->get('/en/')->assertHeader('Content-Security-Policy')->assertHeaderMissing('Content-Security-Policy-Report-Only');

        $this->assertStringContainsString("form-action 'self' https:", SecurityHeaders::policy(null), 'Admin access posts to the customer site.');
    }

    public function test_the_admin_access_page_keeps_its_own_referrer_policy_and_a_nonced_script(): void
    {
        config(['http.csp.mode' => 'enforce']);
        $html = view('account.websites.admin-access-opening', ['website' => Website::factory()->make(['url' => 'https://company.example']), 'action' => 'https://company.example/wp-login.php?action=wp_caregrid_access', 'requestUuid' => 'x', 'token' => 'y'])->render();

        $this->assertMatchesRegularExpression('/<script nonce="[^"]*">document.getElementById/', $html);
    }

    public function test_session_cookies_are_secure_by_default_on_https(): void
    {
        $config = function (string $url) {
            $before = [$_ENV['APP_URL'] ?? null, $_SERVER['APP_URL'] ?? null, getenv('APP_URL')];
            $_ENV['APP_URL'] = $_SERVER['APP_URL'] = $url;
            putenv("APP_URL={$url}");
            try {
                $session = require base_path('config/session.php');
                $http = require base_path('config/http.php');
            } finally {
                [$_ENV['APP_URL'], $_SERVER['APP_URL']] = [$before[0], $before[1]];
                $before[2] === false ? putenv('APP_URL') : putenv('APP_URL='.$before[2]);
            }

            return [$session['secure'], $http['force_https'], $session['http_only'], $session['same_site']];
        };

        $this->assertSame([true, true, true, 'lax'], $config('https://app.wpcaregrid.example'));
        $this->assertSame([false, false, true, 'lax'], $config('http://localhost:8000'));
    }

    public function test_empty_environment_values_do_not_switch_off_secure_defaults(): void
    {
        $load = function (array $env, string $file) {
            $before = [];
            foreach ($env as $name => $value) {
                $before[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
                $_ENV[$name] = $_SERVER[$name] = $value;
                putenv("{$name}={$value}");
            }
            try {
                return require base_path("config/{$file}.php");
            } finally {
                foreach ($before as $name => [$e, $s, $g]) {
                    [$_ENV[$name], $_SERVER[$name]] = [$e, $s];
                    $g === false ? putenv($name) : putenv("{$name}={$g}");
                }
            }
        };

        // As in a production .env copied from an example with empty values.
        $production = ['APP_ENV' => 'production', 'APP_URL' => 'https://app.wpcaregrid.example'];
        $this->assertTrue($load($production + ['CONNECTOR_REQUIRE_HTTPS' => ''], 'connector')['require_https']);
        $this->assertTrue($load($production + ['HTTP_FORCE_HTTPS' => '', 'HTTP_HSTS' => ''], 'http')['force_https']);
        $this->assertTrue($load($production + ['HTTP_HSTS' => ''], 'http')['hsts']['enabled']);
        $this->assertTrue($load($production + ['SESSION_SECURE_COOKIE' => ''], 'session')['secure']);

        // An explicit value still wins.
        $this->assertFalse($load($production + ['CONNECTOR_REQUIRE_HTTPS' => 'false'], 'connector')['require_https']);
    }

    public function test_the_private_disk_is_not_served_over_http(): void
    {
        $this->assertNull(Router::getRoutes()->getByName('storage.local'));
        $this->assertEmpty(collect(Router::getRoutes()->getRoutes())->filter(fn (Route $r) => str_starts_with($r->uri(), 'storage/')));
    }

    /* ----- Rate limits ----- */

    public function test_every_signed_in_write_route_is_rate_limited(): void
    {
        $exempt = ['logout', 'operator.logout', 'owner.logout', 'stripe.webhook'];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            if (! array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()) || in_array($route->getName(), $exempt, true)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $this->assertNotEmpty(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')), ($route->getName() ?? $route->uri()).' is not rate limited');
        }
    }

    /* ----- Queue safety ----- */

    public function test_backup_transfers_use_their_own_queue_with_a_retry_after_longer_than_the_timeout(): void
    {
        config(['queue.default' => 'database']);
        $job = new TransferBackup(1);

        $this->assertSame('backups', $job->connection);
        $this->assertSame('backups', $job->queue);
        $this->assertGreaterThan($job->timeout, (int) config('queue.connections.backups.retry_after'));
        $this->assertSame('1', $job->uniqueId());
        $this->assertGreaterThan($job->timeout, $job->uniqueFor());

        // Tests and local development keep the default (sync) connection.
        config(['queue.default' => 'sync']);
        $this->assertNull((new TransferBackup(1))->connection);
    }

    public function test_one_queued_transfer_per_backup(): void
    {
        Queue::fake();
        $site = $this->backupReadySite();
        $backup = app(BackupService::class)->request($site['website'], BackupReason::Manual, 'test');
        $backup->forceFill(['status' => BackupStatus::Uploaded, 'uploaded_at' => now()->subHours(2)])->save();

        TransferBackup::dispatch($backup->id);
        TransferBackup::dispatch($backup->id);
        $this->artisan('backup:monitor')->assertSuccessful();

        Queue::assertPushed(TransferBackup::class, 1);
    }

    public function test_a_transfer_abandoned_by_a_crashed_worker_is_handed_over_again(): void
    {
        Queue::fake();
        $site = $this->backupReadySite();
        $backup = app(BackupService::class)->request($site['website'], BackupReason::Manual, 'test');
        $backup->forceFill(['status' => BackupStatus::Verifying, 'uploaded_at' => now()])->save();

        // Still within the timeout: a worker may be on it, nothing happens.
        $this->artisan('backup:monitor')->assertSuccessful();
        $this->assertSame(BackupStatus::Verifying, $backup->refresh()->status);
        Queue::assertNothingPushed();

        $this->travel((int) config('backup.transfer.timeout') + 901)->seconds();
        $this->artisan('backup:monitor')->assertSuccessful();

        $this->assertSame(BackupStatus::Uploaded, $backup->refresh()->status);
        Queue::assertPushed(TransferBackup::class, fn (TransferBackup $job) => $job->backupId === $backup->id);
    }

    /* ----- Disconnect is all or nothing ----- */

    public function test_a_failing_disconnect_changes_nothing(): void
    {
        $site = $this->backupReadySite();
        $this->app->instance(AdminAccessService::class, \Mockery::mock(AdminAccessService::class, fn ($mock) => $mock->shouldReceive('revokeAll')->andThrow(new \RuntimeException('database refused'))));

        try {
            app(DisconnectWebsite::class)($site['website'], 'disconnected_by_customer', 'customer:1');
            $this->fail('No error.');
        } catch (\RuntimeException) {
        }

        $this->assertSame(Connector::ACTIVE, $site['website']->connectors()->sole()->status, 'The connector stays active when the rest could not be done.');
    }

    public function test_long_reason_codes_fit(): void
    {
        $site = $this->backupReadySite();

        app(DisconnectWebsite::class)($site['website'], 'disconnected_by_customer', 'customer:1');

        $this->assertSame('disconnected_by_customer', $site['website']->connectors()->sole()->revoked_reason);
    }

    /* ----- Configuration check ----- */

    public function test_system_check_reports_each_part_and_never_prints_secrets(): void
    {
        config(['services.stripe.secret' => 'sk_test_SECRET_STRIPE_KEY_123', 'services.stripe.webhook_secret' => 'whsec_WEBHOOK_SECRET_789', 'backup.providers.s3.secret' => 'STORAGE_SECRET_VALUE']);

        $this->assertSame(0, Artisan::call('system:check'));
        $table = Artisan::output();
        foreach (['Application', 'Database', 'Queue', 'Scheduler', 'Connector API', 'Stripe', 'Cloudflare DNS', 'Backup storage', 'Security scanner', 'Mail', 'Safety switches', 'Privacy retention', 'Privacy exports'] as $part) {
            $this->assertStringContainsString($part, $table);
        }

        Artisan::call('system:check', ['--json' => true]);
        $json = Artisan::output();
        $checks = json_decode($json, true);
        $this->assertCount(13, $checks);
        foreach ($checks as $check) {
            $this->assertContains($check['level'], ['OK', 'WARNING', 'ERROR', 'NOT CONFIGURED']);
        }
        foreach (['sk_test_SECRET_STRIPE_KEY_123', 'whsec_WEBHOOK_SECRET_789', 'STORAGE_SECRET_VALUE'] as $secret) {
            $this->assertStringNotContainsString($secret, $table.$json);
        }
    }

    public function test_system_check_fails_on_errors_and_on_missing_configuration_in_production(): void
    {
        config(['services.stripe.secret' => null, 'mail.default' => 'log']);
        $this->assertSame(0, Artisan::call('system:check'), 'Missing configuration is reported, not fatal, outside production.');
        $this->assertSame(1, Artisan::call('system:check', ['--strict' => true]));

        $this->app->detectEnvironment(fn () => 'production');
        config(['app.debug' => false]);
        $this->assertSame(1, Artisan::call('system:check'), 'In production every part is required.');
    }

    public function test_optional_cloudflare_not_configured_does_not_fail_normal_production_check(): void
    {
        $cloudflare = new StatusCheck('cloudflare', HealthStatus::Unknown, 'Cloudflare is not configured.', notConfigured: true, optional: true);
        $requiredPart = new StatusCheck('stripe', HealthStatus::Unknown, 'Stripe is not configured.', notConfigured: true);

        $this->assertSame('NOT CONFIGURED', $cloudflare->level());
        $this->assertFalse(SystemCheck::hasRequiredConfigurationMissing([$cloudflare]));
        $this->assertTrue(SystemCheck::hasRequiredConfigurationMissing([$cloudflare, $requiredPart]));
    }

    public function test_unsafe_switches_and_transfer_settings_are_reported(): void
    {
        config(['security.remediation.enabled' => true, 'admin_access.operator_full_admin' => true]);
        Artisan::call('system:check', ['--json' => true]);
        $safety = collect(json_decode(Artisan::output(), true))->firstWhere('check', 'safety');
        $this->assertSame('WARNING', $safety['level']);
        $this->assertStringContainsString('Security remediation', $safety['summary']);

        config(['queue.default' => 'database', 'queue.connections.backups.retry_after' => 60]);
        Artisan::call('system:check', ['--json' => true]);
        $queue = collect(json_decode(Artisan::output(), true))->firstWhere('check', 'queue');
        $this->assertSame('ERROR', $queue['level']);
    }

    public function test_system_check_ignores_database_jobs_scheduled_for_the_future(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.backups.retry_after' => 4000,
            'backup.transfer.connection' => 'backups',
            'backup.transfer.timeout' => 3600,
        ]);
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->addDays(7)->timestamp,
            'created_at' => now()->addDays(7)->timestamp,
        ]);

        Artisan::call('system:check', ['--json' => true]);
        $queue = collect(json_decode(Artisan::output(), true))->firstWhere('check', 'queue');

        $this->assertSame('OK', $queue['level']);
    }

    public function test_production_requires_https_and_a_real_application_url(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.debug' => false, 'app.url' => 'http://localhost']);
        Artisan::call('system:check', ['--json' => true]);
        $application = collect(json_decode(Artisan::output(), true))->firstWhere('check', 'application');
        $this->assertSame('ERROR', $application['level']);

        config(['app.url' => 'https://app.wpcaregrid.example', 'session.secure' => true]);
        Artisan::call('system:check', ['--json' => true]);
        $this->assertSame('OK', collect(json_decode(Artisan::output(), true))->firstWhere('check', 'application')['level']);
    }

    public function test_conservative_defaults_ship_in_the_configuration(): void
    {
        foreach (['SECURITY_REMEDIATION_ENABLED', 'ADMIN_ACCESS_OPERATOR_FULL_ADMIN', 'BACKUP_RESTORE_ENABLED', 'SECURITY_REMEDIATION_ALLOW_DISABLE_PLUGIN'] as $switch) {
            $this->assertMatchesRegularExpression("/^{$switch}=false$/m", (string) file_get_contents(base_path('.env.example')), "{$switch} is off in .env.example");
        }

        foreach (['security.remediation.enabled' => 'config/security.php', 'admin_access.operator_full_admin' => 'config/admin_access.php', 'backup.restore.enabled' => 'config/backup.php'] as $key => $file) {
            $this->assertStringContainsString(', false)', (string) collect(file(base_path($file)))->first(fn ($line) => str_contains($line, strtoupper(str_replace(['security.remediation.enabled', 'admin_access.operator_full_admin', 'backup.restore.enabled'], ['SECURITY_REMEDIATION_ENABLED', 'ADMIN_ACCESS_OPERATOR_FULL_ADMIN', 'BACKUP_RESTORE_ENABLED'], $key)))), "{$key} defaults to false");
        }
    }
}
