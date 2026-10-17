<?php

namespace Tests\Feature\Operator;

use App\AdminAccess\AccessLevel;
use App\AdminAccess\ActorType;
use App\AdminAccess\AdminAccessRefused;
use App\AdminAccess\AdminAccessService;
use App\Backups\BackupReason;
use App\Backups\BackupRefused;
use App\Backups\BackupService;
use App\Models\AdminAccessRequest;
use App\Models\Backup;
use App\Models\BackupRestore;
use App\Models\User;
use App\Models\WebsiteJob;
use App\Operator\Permission;
use App\Pricing\PricingCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route as Router;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\OperatesConsole;
use Tests\TestCase;

class OperatorAuthorizationTest extends TestCase
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

    public function test_every_operator_route_requires_the_operator_role_and_names_a_permission(): void
    {
        $routes = collect(Router::getRoutes()->getRoutes())->filter(fn (Route $route) => str_starts_with($route->uri(), 'operator'));
        $this->assertGreaterThanOrEqual(16, $routes->count());

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('web', $middleware, $route->uri().' needs sessions and CSRF protection');

            if (in_array($route->getName(), ['operator.login', 'operator.login.store', 'operator.logout'], true)) {
                continue;
            }

            $this->assertContains('auth:operator', $middleware, $route->getName());
            $this->assertContains('operator', $middleware, $route->getName());
            $permissions = array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'can:operator.'));
            $this->assertCount(1, $permissions, $route->getName().' must name exactly one permission');
            $this->assertContains(substr(reset($permissions), strlen('can:operator.')), Permission::values(), $route->getName());

            if (in_array('POST', $route->methods(), true)) {
                $this->assertNotEmpty(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')), $route->getName().' must be rate limited');
            }
        }

        // No operator route is registered under the customer area, and vice versa.
        $this->assertEmpty(collect(Router::getRoutes()->getRoutes())->filter(fn (Route $r) => str_starts_with($r->uri(), 'account') && str_starts_with((string) $r->getName(), 'operator.')));
    }

    /** @return array<string, array{0: string, 1: Permission}> */
    public static function pages(): array
    {
        return [
            'dashboard' => ['operator.dashboard', Permission::WebsitesView],
            'websites' => ['operator.websites.index', Permission::WebsitesView],
            'website' => ['operator.websites.show', Permission::WebsitesView],
            'backups' => ['operator.backups.index', Permission::BackupsView],
            'security' => ['operator.security.index', Permission::SecurityView],
            'activity' => ['operator.activity.index', Permission::AuditView],
        ];
    }

    #[DataProvider('pages')]
    public function test_pages_need_their_permission(string $route, Permission $permission): void
    {
        $website = $this->consoleSite()['website'];
        $parameters = $route === 'operator.websites.show' ? [$website] : [];

        $this->actingAs($this->operator([$permission]), 'operator')->get(route($route, $parameters))->assertOk();

        $others = array_values(array_filter(Permission::cases(), fn (Permission $p) => $p !== $permission));
        $this->actingAs($this->operator($others), 'operator')->get(route($route, $parameters))
            ->assertForbidden()
            ->assertSee(__('operator.errors.forbidden_title'))
            ->assertDontSee('Stack trace')
            ->assertDontSee('vendor/laravel');
    }

    public function test_an_operator_without_permissions_can_sign_in_but_sees_nothing(): void
    {
        $this->asOperator([]);

        $this->get(route('operator.dashboard'))->assertForbidden();
        $this->get(route('operator.websites.index'))->assertForbidden();
        $this->assertFalse(Gate::allows('operator.websites.view'));
    }

    public function test_navigation_shows_only_permitted_sections(): void
    {
        $this->asOperator([Permission::WebsitesView]);

        $this->get(route('operator.dashboard'))->assertOk()
            ->assertSee(route('operator.websites.index'))
            ->assertDontSee(route('operator.backups.index'))
            ->assertDontSee(route('operator.activity.index'))
            ->assertDontSee(route('operator.security.index'))
            ->assertDontSee(__('operator.dashboard.activity_title'));
    }

    public function test_without_backups_restore_nothing_can_be_restored(): void
    {
        $website = $this->consoleSite()['website'];
        $backup = $this->verifiedBackup($website);
        $this->asOperator(array_values(array_filter(Permission::cases(), fn ($p) => $p !== Permission::BackupsRestore)));

        $this->get(route('operator.websites.show', $website))->assertOk()->assertDontSee(route('operator.websites.backups.restore', [$website, $backup]));
        $this->get(route('operator.websites.backups.restore', [$website, $backup]))->assertForbidden();
        $this->post(route('operator.websites.backups.restore.store', [$website, $backup]), ['scope' => 'full', 'confirmation' => 'company.example', 'understood' => '1'])->assertForbidden();

        $this->assertSame(0, BackupRestore::count());
        $this->assertSame(1, Backup::count(), 'Not even a safety backup was started.');
    }

    public function test_without_updates_run_no_update_job_is_created(): void
    {
        $website = $this->consoleSite()['website'];
        $this->asOperator([Permission::WebsitesView, Permission::UpdatesView]);

        $this->get(route('operator.websites.show', $website))->assertOk()->assertDontSee(route('operator.websites.updates.store', $website));
        $this->post(route('operator.websites.updates.store', $website), ['kind' => 'plugin', 'target' => 'akismet/akismet.php'])->assertForbidden();
        $this->post(route('operator.websites.updates.store', $website), ['kind' => 'core'])->assertForbidden();

        $this->assertSame(0, WebsiteJob::count());
    }

    public function test_without_backups_run_no_backup_is_requested(): void
    {
        $website = $this->consoleSite()['website'];
        $this->asOperator([Permission::WebsitesView, Permission::BackupsView]);

        $this->post(route('operator.websites.backups.store', $website))->assertForbidden();

        $this->assertSame(0, Backup::count());
    }

    public function test_without_admin_access_full_full_admin_cannot_be_requested(): void
    {
        $website = $this->consoleSite()['website'];
        $this->asOperator([Permission::WebsitesView, Permission::AdminAccessRequest]);

        $this->get(route('operator.websites.show', $website))->assertDontSee(route('operator.websites.admin-access.full-admin', $website));
        $this->get(route('operator.websites.admin-access.full-admin', $website))->assertForbidden();
        $this->post(route('operator.websites.admin-access.full-admin.store', $website), ['confirm' => '1'])->assertForbidden();

        $this->assertSame(0, AdminAccessRequest::count());
    }

    public function test_full_admin_stays_customer_only_unless_enabled_for_operators(): void
    {
        $website = $this->consoleSite()['website'];
        $operator = $this->asOperator([Permission::WebsitesView, Permission::AdminAccessRequest, Permission::AdminAccessFull]);

        // Default: the service refuses even with the permission.
        $this->post(route('operator.websites.admin-access.full-admin.store', $website), ['confirm' => '1'])
            ->assertRedirect(route('operator.websites.show', $website).'#admin-access')
            ->assertSessionHas('operator_error', fn ($e) => $e['code'] === 'FULL_ADMIN_REQUIRES_CUSTOMER' && $e['kind'] === 'permission');
        $this->assertSame(0, AdminAccessRequest::count());

        // Enabled: explicit confirmation still required, then granted and tied to the operator.
        config(['admin_access.operator_full_admin' => true]);
        $this->post(route('operator.websites.admin-access.full-admin.store', $website))->assertSessionHasErrors('confirm');
        $this->post(route('operator.websites.admin-access.full-admin.store', $website), ['confirm' => '1'])->assertOk();

        $request = AdminAccessRequest::sole();
        $this->assertSame(AccessLevel::FullAdmin, $request->access_level);
        $this->assertSame(ActorType::Operator, $request->actor_type);
        $this->assertSame($operator->id, $request->requested_by_user_id);
    }

    public function test_admin_access_service_cannot_be_bypassed_by_calling_it_directly(): void
    {
        $website = $this->consoleSite()['website'];
        $service = app(AdminAccessService::class);
        config(['admin_access.operator_full_admin' => true]);

        $cases = [
            'no operator' => [null, AccessLevel::Maintenance],
            'customer login as operator' => [$website->customer->user, AccessLevel::Maintenance],
            'operator without permission' => [$this->operator([Permission::WebsitesView]), AccessLevel::Maintenance],
            'maintenance only' => [$this->operator([Permission::AdminAccessRequest]), AccessLevel::FullAdmin],
        ];

        foreach ($cases as $name => [$user, $level]) {
            try {
                $service->request($website, $user, ActorType::Operator, $level, true);
                $this->fail("{$name}: access was granted");
            } catch (AdminAccessRefused $e) {
                $this->assertSame('NOT_AUTHORIZED', $e->errorCode, $name);
            }
        }

        // An operator can never act as a customer either.
        $this->expectException(AdminAccessRefused::class);
        $service->request($website, $this->operator(), ActorType::Customer, AccessLevel::Maintenance);
    }

    public function test_without_websites_manage_the_connector_cannot_be_disconnected_or_re_paired(): void
    {
        $website = $this->consoleSite()['website'];
        $this->asOperator(array_values(array_filter(Permission::cases(), fn ($p) => $p !== Permission::WebsitesManage)));

        $this->get(route('operator.websites.show', $website))->assertDontSee(route('operator.websites.disconnect', $website));
        $this->post(route('operator.websites.disconnect', $website), ['confirm' => '1'])->assertForbidden();
        $this->post(route('operator.websites.pairing-code', $website))->assertForbidden();

        $this->assertNotNull($website->refresh()->activeConnector);
        $this->assertSame(0, $website->pairingCodes()->whereNull('used_at')->count());
    }

    public function test_operators_cannot_use_customer_routes_or_pass_website_policy(): void
    {
        $website = $this->consoleSite()['website'];
        $operator = $this->operator();

        foreach (['view', 'backup', 'restore', 'adminAccess', 'disconnect', 'pair'] as $ability) {
            $this->assertFalse(Gate::forUser($operator)->allows($ability, $website), "WebsitePolicy::{$ability}");
        }

        // Signed in on the customer guard, an operator has no customer account: signed out.
        $this->actingAs($operator)->get(route('account.websites.show', $website))->assertRedirect(route('login'));
        $this->actingAs($operator)->post(route('account.websites.backups.store', $website))->assertRedirect(route('login'));
        $this->actingAs($operator)->post(route('account.websites.disconnect', $website))->assertRedirect(route('login'));

        $this->assertSame(0, Backup::count());
        $this->assertNotNull($website->refresh()->activeConnector);
    }

    public function test_customers_cannot_post_to_operator_actions(): void
    {
        $site = $this->consoleSite();
        $website = $site['website'];
        $backup = $this->verifiedBackup($website);
        $customer = $website->customer->user;

        foreach ([
            route('operator.websites.backups.store', $website) => [],
            route('operator.websites.updates.store', $website) => ['kind' => 'core'],
            route('operator.websites.disconnect', $website) => ['confirm' => '1'],
            route('operator.websites.pairing-code', $website) => [],
            route('operator.websites.admin-access.maintenance', $website) => [],
            route('operator.websites.backups.restore.store', [$website, $backup]) => ['scope' => 'full', 'confirmation' => 'company.example', 'understood' => '1'],
        ] as $url => $data) {
            $this->actingAs($customer)->post($url, $data)->assertRedirect(route('operator.login'));
        }

        $this->assertSame(1, Backup::count());
        $this->assertSame(0, WebsiteJob::count());
        $this->assertSame(0, AdminAccessRequest::count());
        $this->assertNotNull($website->refresh()->activeConnector);
    }

    public function test_backup_service_rules_still_apply_to_operators(): void
    {
        $site = $this->consoleSite();
        $website = $site['website'];
        $this->asOperator();

        // The plan does not allow on-demand backups: the service refuses, the operator sees why.
        config(['pricing.plans.wordpress.business.manual_backup_allowed' => false]);
        app()->forgetInstance(PricingCatalog::class);
        $this->post(route('operator.websites.backups.store', $website))
            ->assertSessionHas('operator_error', fn ($e) => $e['code'] === 'MANUAL_NOT_ALLOWED');
        $this->assertSame(0, Backup::count());

        // Inactive subscription: refused too.
        config(['pricing.plans.wordpress.business.manual_backup_allowed' => true]);
        app()->forgetInstance(PricingCatalog::class);
        $website->subscription->update(['status' => 'canceled']);
        $this->post(route('operator.websites.backups.store', $website))
            ->assertSessionHas('operator_error', fn ($e) => $e['code'] === 'SUBSCRIPTION_INACTIVE');
        $this->assertSame(0, Backup::count());

        // And the same service refuses the same request made directly.
        $this->expectException(BackupRefused::class);
        app(BackupService::class)->request($website->refresh(), BackupReason::Manual, 'operator:1');
    }

    public function test_permissions_cannot_be_mass_assigned(): void
    {
        $user = new User(['name' => 'X', 'email' => 'x@example.test', 'password' => 'secret-secret', 'role' => 'operator', 'operator_permissions' => ['websites.view']]);
        $this->assertNull($user->role);
        $this->assertNull($user->operator_permissions);

        // The customer profile form cannot turn a customer into an operator.
        $site = $this->consoleSite();
        $customer = $site['website']->customer;
        $this->actingAs($customer->user)->put(route('account.profile'), [
            'first_name' => 'A', 'last_name' => 'B', 'role' => 'operator', 'operator_permissions' => Permission::values(),
        ]);
        $this->assertFalse($customer->user->refresh()->isOperator());
        $this->assertNull($customer->user->operator_permissions);
    }
}
