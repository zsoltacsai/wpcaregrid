<?php

namespace Tests\Feature\Operator;

use App\AdminAccess\AccessLevel;
use App\AdminAccess\ActorType;
use App\AdminAccess\AdminAccessService;
use App\AdminAccess\AdminAccessStatus;
use App\Models\BackupRestore;
use App\Models\Connector;
use App\Operator\Permission;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\OperatesConsole;
use Tests\TestCase;

/** IDOR, UUID tampering, CSRF, secret leakage and rate limits in the operator console. */
class OperatorSecurityTest extends TestCase
{
    use OperatesConsole, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups(['manual' => true, 'restore' => true, 'pre_update_backup' => false]);
        config(['backup.restore.enabled' => true]);
    }

    protected function tearDown(): void
    {
        $this->tearDownBackups();
        parent::tearDown();
    }

    public function test_a_backup_of_another_website_cannot_be_restored_by_tampering_with_the_url(): void
    {
        $mine = $this->consoleSite('https://company.example')['website'];
        $other = $this->consoleSite('https://other.example')['website'];
        $foreignBackup = $this->verifiedBackup($other);
        $this->asOperator();

        $this->get(route('operator.websites.backups.restore', [$mine, $foreignBackup]))->assertNotFound();
        $this->post(route('operator.websites.backups.restore.store', [$mine, $foreignBackup]), ['scope' => 'full', 'confirmation' => 'company.example', 'understood' => '1'])->assertNotFound();

        $this->assertSame(0, BackupRestore::count());
    }

    public function test_admin_access_of_another_website_cannot_be_revoked_through_this_one(): void
    {
        $mine = $this->consoleSite('https://company.example')['website'];
        $other = $this->consoleSite('https://other.example')['website'];
        [$foreign] = app(AdminAccessService::class)->request($other, $other->customer->user, ActorType::Customer, AccessLevel::Maintenance);
        $this->asOperator();

        $this->post(route('operator.websites.admin-access.revoke', [$mine, $foreign]))->assertNotFound();

        $this->assertSame(AdminAccessStatus::Pending, $foreign->refresh()->status);
    }

    public function test_unknown_and_malformed_uuids_are_not_found(): void
    {
        $website = $this->consoleSite()['website'];
        $this->asOperator();

        $this->get(route('operator.websites.show', (string) Str::uuid()))->assertNotFound();
        $this->get('/operator/websites/1')->assertNotFound();                      // numeric IDs are not route keys
        $this->get('/operator/websites/'.$website->id)->assertNotFound();
        $this->get("/operator/websites/{$website->uuid}' or '1'='1")->assertNotFound();
        $this->post(route('operator.websites.backups.restore.store', [$website, (string) Str::uuid()]), ['scope' => 'full'])->assertNotFound();
    }

    public function test_post_requests_without_a_csrf_token_are_rejected(): void
    {
        // Laravel skips CSRF checks in unit tests; enforce them here as in production.
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $website = $this->consoleSite()['website'];
        $this->asOperator();

        $this->post(route('operator.websites.backups.store', $website))->assertStatus(419);
        $this->post(route('operator.websites.disconnect', $website), ['confirm' => '1'])->assertStatus(419);
        $this->post(route('operator.login.store'), ['email' => 'a@b.test', 'password' => 'x'])->assertStatus(419);
        $this->assertSame(0, $website->backups()->count());
        $this->assertNotNull($website->refresh()->activeConnector);

        // With the session's token the same request goes through.
        $this->withSession(['_token' => 'known-token'])->post(route('operator.websites.backups.store', $website), ['_token' => 'known-token'])->assertRedirect();
        $this->assertSame(1, $website->backups()->count());
    }

    public function test_no_secret_is_shown_to_operators(): void
    {
        $site = $this->consoleSite();
        $website = $site['website'];
        $customer = $website->customer;
        $customer->update(['stripe_customer_id' => 'cus_SECRETSTRIPE01', 'tax_id' => 'HU99999999']);
        $website->subscription->update(['stripe_subscription_id' => 'sub_SECRETSTRIPE02', 'stripe_price_id' => 'price_SECRETSTRIPE03']);
        config(['services.stripe.secret' => 'sk_test_SECRETKEY', 'backup.providers.s3.secret' => 'S3-SECRET-ACCESS-KEY', 'backup.providers.sftp.password' => 'SFTP-PASSWORD']);
        $backup = $this->verifiedBackup($website);
        [, $token] = app(AdminAccessService::class)->request($website, $customer->user, ActorType::Customer, AccessLevel::Maintenance);
        $connector = Connector::where('website_id', $website->id)->where('status', 'active')->sole();
        $this->asOperator();

        $pages = [
            route('operator.dashboard'),
            route('operator.websites.index'),
            route('operator.websites.index', ['q' => 'company']),
            route('operator.websites.show', $website),
            route('operator.backups.index'),
            route('operator.activity.index'),
            route('operator.security.index'),
            route('operator.websites.backups.restore', [$website, $backup]),
        ];

        foreach ($pages as $page) {
            $html = $this->get($page)->assertOk()->getContent();
            foreach ([
                $site['credential'], $connector->credential_hash, $connector->credential_hint,
                $token, hash('sha256', $token),
                'cus_SECRETSTRIPE01', 'sub_SECRETSTRIPE02', 'price_SECRETSTRIPE03', 'sk_test_SECRETKEY', 'HU99999999',
                'S3-SECRET-ACCESS-KEY', 'SFTP-PASSWORD', $backup->storage_path, $this->storageRoot,
                $customer->user->password,
            ] as $secret) {
                $this->assertStringNotContainsString((string) $secret, $html, "Leaked on {$page}");
            }
        }
    }

    public function test_search_does_not_match_hidden_fields(): void
    {
        $website = $this->consoleSite()['website'];
        $website->customer->update(['stripe_customer_id' => 'cus_FINDME123', 'tax_id' => 'TAXFINDME']);
        $credentialHint = Connector::where('website_id', $website->id)->value('credential_hint');
        $this->asOperator();

        foreach (['cus_FINDME123', 'TAXFINDME', $credentialHint, $website->uuid] as $term) {
            $this->get(route('operator.websites.index', ['q' => $term]))->assertOk()->assertSee(__('operator.websites.empty'));
        }

        $this->get(route('operator.websites.index', ['q' => '%']))->assertOk()->assertSee(__('operator.websites.empty'));
        $this->get(route('operator.websites.index', ['q' => 'company.example']))->assertOk()->assertSee('company.example');
    }

    public function test_sensitive_actions_are_rate_limited_per_operator(): void
    {
        $website = $this->consoleSite()['website'];
        $this->asOperator();

        foreach (range(1, 6) as $i) {
            $this->post(route('operator.websites.backups.store', $website))->assertRedirect();
        }
        $this->post(route('operator.websites.backups.store', $website))->assertStatus(429);

        $backup = $this->verifiedBackup($website);
        foreach (range(1, 3) as $i) {
            $this->post(route('operator.websites.backups.restore.store', [$website, $backup]), ['scope' => 'full', 'confirmation' => 'wrong.example', 'understood' => '1'])->assertRedirect();
        }
        $this->post(route('operator.websites.backups.restore.store', [$website, $backup]), ['scope' => 'full', 'confirmation' => 'company.example', 'understood' => '1'])->assertStatus(429);
        $this->assertSame(0, BackupRestore::count());

        foreach (range(1, 5) as $i) {
            $this->post(route('operator.websites.pairing-code', $website))->assertRedirect();
        }
        $this->post(route('operator.websites.disconnect', $website), ['confirm' => '1'])->assertStatus(429);
        $this->assertNotNull($website->refresh()->activeConnector);
    }

    public function test_forbidden_actions_are_logged_without_details_in_the_response(): void
    {
        $website = $this->consoleSite()['website'];
        $this->asOperator([Permission::WebsitesView]);

        $this->post(route('operator.websites.backups.store', $website))
            ->assertForbidden()
            ->assertSee(__('operator.errors.forbidden_text'))
            ->assertDontSee('AccessDeniedHttpException')
            ->assertDontSee('This action is unauthorized');
    }

    public function test_console_responses_are_not_cached_or_indexed(): void
    {
        $this->asOperator();

        $response = $this->get(route('operator.dashboard'))->assertOk();

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('noindex', $response->headers->get('X-Robots-Tag'));
    }
}
