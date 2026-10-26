<?php

namespace Tests\Feature\Account;

use App\Mail\AccountActivation;
use App\Mail\WelcomeEmail;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\CustomerPasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::factory()->withUser()->create(['email' => 'anna@company.example']);
    }

    public function test_login_page_renders(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Log in');
    }

    public function test_customer_can_log_in_and_the_session_is_regenerated(): void
    {
        $this->customer();
        $this->get(route('login'));
        $before = session()->getId();

        $this->post(route('login'), ['email' => 'Anna@Company.example', 'password' => 'password-123456'])
            ->assertRedirect(route('account.dashboard'));

        $this->assertAuthenticated();
        $this->assertNotSame($before, session()->getId());
    }

    public function test_database_session_rows_are_associated_with_authenticated_users(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->customer()->user;

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password-123456'])
            ->assertRedirect(route('account.dashboard'));

        $this->assertDatabaseHas('sessions', ['user_id' => $user->id]);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->customer();

        $this->post(route('login'), ['email' => 'anna@company.example', 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => __('account.auth.failed')]);

        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        $this->customer();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login'), ['email' => 'anna@company.example', 'password' => 'wrong-password']);
        }

        $this->post(route('login'), ['email' => 'anna@company.example', 'password' => 'password-123456'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = $this->customer()->user;

        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->get(route('account.dashboard'))->assertRedirect(route('login'));
    }

    public function test_guests_are_redirected_from_the_customer_area(): void
    {
        foreach (['account.dashboard', 'account.websites.index', 'account.subscription', 'account.billing', 'account.profile'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    public function test_login_without_customer_or_unverified_email_cannot_use_the_customer_area(): void
    {
        $orphan = User::factory()->create();
        $this->actingAs($orphan)->get(route('account.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();

        $customer = $this->customer();
        $customer->user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($customer->user)->get(route('account.dashboard'))->assertRedirect(route('login'));
    }

    public function test_password_reset_link_is_sent_without_revealing_accounts(): void
    {
        Notification::fake();
        $user = $this->customer()->user;

        $known = $this->post(route('password.email'), ['email' => 'anna@company.example']);
        $unknown = $this->post(route('password.email'), ['email' => 'nobody@company.example']);

        $known->assertSessionHas('status', __('account.auth.link_sent'));
        $unknown->assertSessionHas('status', __('account.auth.link_sent'));
        Notification::assertSentTo($user, CustomerPasswordReset::class);
        Notification::assertCount(1);
    }

    public function test_password_reset_limit_is_per_account_across_ips_and_fair_to_shared_ip_users(): void
    {
        Notification::fake();
        $firstEmail = 'first-missing@company.example';
        $secondEmail = 'second-missing@company.example';

        foreach (range(1, 3) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => "192.0.2.{$attempt}"])
                ->post(route('password.email'), ['email' => $firstEmail])
                ->assertSessionHas('status', __('account.auth.link_sent'));

            if ($attempt < 3) {
                $this->withServerVariables(['REMOTE_ADDR' => "192.0.2.{$attempt}"])
                    ->post(route('password.email'), ['email' => $secondEmail])
                    ->assertSessionHas('status', __('account.auth.link_sent'));
            }
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->post(route('password.email'), ['email' => $firstEmail])
            ->assertStatus(429)
            ->assertDontSee($firstEmail);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->post(route('password.email'), ['email' => $secondEmail])
            ->assertSessionHas('status', __('account.auth.link_sent'));

        Notification::assertNothingSent();
    }

    public function test_password_can_be_reset_with_a_valid_token_only(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->customer()->user;

        $this->post(route('password.update'), ['token' => 'invalid', 'email' => $user->email, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
            ->assertSessionHasErrors('email');

        $token = Password::createToken($user);
        $parallelSessionId = Str::random(40);
        DB::table('sessions')->insert([
            'id' => $parallelSessionId,
            'user_id' => $user->id,
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->timestamp,
        ]);

        $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
            ->assertRedirect(route('login'));

        $this->assertDatabaseMissing('sessions', ['id' => $parallelSessionId]);
        $this->assertGuest();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'new-password-123'])->assertRedirect(route('account.dashboard'));

        // Tokens are single use.
        $this->post(route('logout'));
        $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'another-pass-123', 'password_confirmation' => 'another-pass-123'])
            ->assertSessionHasErrors('email');
    }

    public function test_activation_sets_the_first_password_verifies_the_email_and_logs_in(): void
    {
        Mail::fake();
        $customer = Customer::factory()->create(['email' => 'new@company.example']);
        $user = User::factory()->unverified()->create(['email' => 'new@company.example']);
        $customer->user()->associate($user)->save();
        $token = Password::broker('activations')->createToken($user);

        $this->get(route('account.activate', ['token' => $token, 'email' => $user->email]))->assertOk()->assertSee('Activate your account');

        $this->post(route('account.activate.store'), ['token' => $token, 'email' => $user->email, 'password' => 'a-strong-pass-1', 'password_confirmation' => 'a-strong-pass-1'])
            ->assertRedirect(route('account.dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->email_verified_at);
        Mail::assertQueued(WelcomeEmail::class);
    }

    public function test_activation_rejects_invalid_or_expired_tokens_and_short_passwords(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'new@company.example']);
        Customer::factory()->create(['email' => 'new@company.example', 'user_id' => $user->id]);
        $token = Password::broker('activations')->createToken($user);

        $this->post(route('account.activate.store'), ['token' => $token, 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');

        $this->post(route('account.activate.store'), ['token' => 'nope', 'email' => $user->email, 'password' => 'a-strong-pass-1', 'password_confirmation' => 'a-strong-pass-1'])
            ->assertRedirect(route('password.request'));

        $this->travel(8)->days();
        $this->post(route('account.activate.store'), ['token' => $token, 'email' => $user->email, 'password' => 'a-strong-pass-1', 'password_confirmation' => 'a-strong-pass-1'])
            ->assertRedirect(route('password.request'));

        $this->assertGuest();
        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_activation_email_contains_a_link_and_no_password(): void
    {
        $customer = Customer::factory()->create();
        $mail = new AccountActivation($customer, 'token-123');

        $mail->assertSeeInHtml(route('account.activate', ['token' => 'token-123', 'email' => $customer->email]), false);
        $mail->assertDontSeeInText('password-');
    }
}
