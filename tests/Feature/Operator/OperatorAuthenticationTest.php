<?php

namespace Tests\Feature\Operator;

use App\Models\Customer;
use App\Models\User;
use App\Operator\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class OperatorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function operatorWithPassword(array $permissions = [Permission::WebsitesView]): User
    {
        return User::factory()->operator($permissions)->create(['email' => 'ops@wpcaregrid.test', 'password' => 'operator-password-1']);
    }

    public function test_login_page_is_separate_from_the_customer_login(): void
    {
        $this->get(route('operator.login'))->assertOk()
            ->assertSee(__('operator.auth.title'))
            ->assertSee('action="'.route('operator.login.store').'"', false)
            ->assertDontSee('action="'.route('login').'"', false);
    }

    public function test_operator_signs_in_on_the_operator_guard_and_the_session_is_regenerated(): void
    {
        $operator = $this->operatorWithPassword();
        $this->get(route('operator.login'));
        $before = session()->getId();

        $this->post(route('operator.login.store'), ['email' => 'OPS@wpcaregrid.test ', 'password' => 'operator-password-1'])
            ->assertRedirect(route('operator.dashboard'));

        $this->assertNotSame($before, session()->getId(), 'A new session ID prevents session fixation.');
        $this->assertTrue(Auth::guard('operator')->check());
        $this->assertSame($operator->id, Auth::guard('operator')->id());
        $this->assertFalse(Auth::guard('web')->check(), 'Signing in as an operator does not sign in to the customer area.');
    }

    public function test_wrong_password_and_non_operators_get_the_same_refusal(): void
    {
        $this->operatorWithPassword();
        $customer = Customer::factory()->withUser()->create(['email' => 'client@company.example']);
        User::factory()->create(['email' => 'plain@company.example', 'password' => 'plain-password-1']);
        User::factory()->operator([Permission::WebsitesView])->unverified()->create(['email' => 'unverified@wpcaregrid.test', 'password' => 'operator-password-1']);

        foreach ([
            ['ops@wpcaregrid.test', 'wrong-password'],
            [$customer->email, 'password-123456'],          // a customer login
            ['plain@company.example', 'plain-password-1'],    // a login without the operator role
            ['unverified@wpcaregrid.test', 'operator-password-1'],
        ] as [$email, $password]) {
            $this->post(route('operator.login.store'), ['email' => $email, 'password' => $password])
                ->assertSessionHasErrors(['email' => __('operator.auth.failed')]);
            $this->assertFalse(Auth::guard('operator')->check(), $email);
        }
    }

    public function test_an_operator_role_on_a_customer_login_is_refused(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $customer->user->forceFill(['role' => User::ROLE_OPERATOR, 'operator_permissions' => [Permission::WebsitesView->value]])->save();

        $this->post(route('operator.login.store'), ['email' => $customer->email, 'password' => 'password-123456'])->assertSessionHasErrors('email');

        // Even if such a session existed, the console refuses it and signs it out.
        $this->actingAs($customer->user->refresh(), 'operator')->get(route('operator.dashboard'))
            ->assertRedirect(route('operator.login'))->assertSessionHasErrors(['email' => __('operator.auth.not_operator')]);
        $this->assertFalse(Auth::guard('operator')->check());
    }

    public function test_guests_and_customers_are_sent_to_the_operator_login(): void
    {
        $this->get(route('operator.dashboard'))->assertRedirect(route('operator.login'));
        $this->get(route('operator.websites.index'))->assertRedirect(route('operator.login'));

        // A signed-in customer is a guest here: the customer guard never opens the console.
        $customer = Customer::factory()->withUser()->create();
        $this->actingAs($customer->user)->get(route('operator.dashboard'))->assertRedirect(route('operator.login'));
        $this->actingAs($customer->user)->get(route('account.dashboard'))->assertOk();
    }

    public function test_a_customer_user_on_the_operator_guard_is_refused(): void
    {
        $plain = User::factory()->create();

        $this->actingAs($plain, 'operator')->get(route('operator.dashboard'))->assertRedirect(route('operator.login'));
        $this->assertFalse(Auth::guard('operator')->check());
    }

    public function test_an_intended_url_outside_the_console_is_not_followed(): void
    {
        $this->operatorWithPassword();

        $this->withSession(['url.intended' => url('/account/billing')])
            ->post(route('operator.login.store'), ['email' => 'ops@wpcaregrid.test', 'password' => 'operator-password-1'])
            ->assertRedirect(route('operator.dashboard'));

        Auth::guard('operator')->logout();
        $this->withSession(['url.intended' => route('operator.activity.index')])
            ->post(route('operator.login.store'), ['email' => 'ops@wpcaregrid.test', 'password' => 'operator-password-1'])
            ->assertRedirect(route('operator.activity.index'));
    }

    public function test_signed_in_operators_skip_the_login_page_and_can_log_out(): void
    {
        $operator = $this->operatorWithPassword();

        $this->actingAs($operator, 'operator')->get(route('operator.login'))->assertRedirect(route('operator.dashboard'));

        $this->post(route('operator.logout'))->assertRedirect(route('operator.login'));
        $this->assertFalse(Auth::guard('operator')->check());
        $this->get(route('operator.dashboard'))->assertRedirect(route('operator.login'));
    }

    public function test_operator_logins_cannot_use_the_customer_area(): void
    {
        $operator = $this->operatorWithPassword();

        $this->actingAs($operator)->get(route('account.dashboard'))->assertRedirect(route('login'));
        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_operator_login_is_rate_limited(): void
    {
        $this->operatorWithPassword();

        foreach (range(1, 5) as $attempt) {
            $this->post(route('operator.login.store'), ['email' => 'ops@wpcaregrid.test', 'password' => 'wrong-'.$attempt])->assertSessionHasErrors('email');
        }

        $this->post(route('operator.login.store'), ['email' => 'ops@wpcaregrid.test', 'password' => 'operator-password-1'])->assertStatus(429);
        $this->assertFalse(Auth::guard('operator')->check());
    }

    public function test_operator_command_creates_operators_and_manages_permissions(): void
    {
        $this->artisan('operator:user', ['email' => 'New.Ops@wpcaregrid.test', '--name' => 'New Ops', '--standard' => true])
            ->expectsOutputToContain('Initial password (shown once)')
            ->assertSuccessful();

        $operator = User::where('email', 'new.ops@wpcaregrid.test')->sole();
        $this->assertTrue($operator->isOperator());
        $this->assertNotNull($operator->email_verified_at);
        $this->assertTrue($operator->hasOperatorPermission(Permission::BackupsRestore));
        $this->assertFalse($operator->hasOperatorPermission(Permission::AdminAccessFull), 'Full admin is never part of the standard set.');

        $this->artisan('operator:user', ['email' => 'new.ops@wpcaregrid.test', '--grant' => ['admin_access.full'], '--revoke' => ['backups.restore']])->assertSuccessful();
        $operator->refresh();
        $this->assertTrue($operator->hasOperatorPermission(Permission::AdminAccessFull));
        $this->assertFalse($operator->hasOperatorPermission(Permission::BackupsRestore));

        $this->artisan('operator:user', ['email' => 'new.ops@wpcaregrid.test', '--grant' => ['everything']])->assertFailed();

        $this->artisan('operator:user', ['email' => 'new.ops@wpcaregrid.test', '--disable' => true])->assertSuccessful();
        $this->assertFalse($operator->refresh()->isOperator());
        $this->assertSame([], $operator->operatorPermissions());
    }

    public function test_operator_command_refuses_customer_logins(): void
    {
        $customer = Customer::factory()->withUser()->create();

        $this->artisan('operator:user', ['email' => $customer->email, '--standard' => true])->assertFailed();

        $this->assertFalse($customer->user->refresh()->isOperator());
    }
}
