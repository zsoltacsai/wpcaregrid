<?php

namespace Tests\Feature\Owner;

use App\Mail\StaffInvitation;
use App\Models\AdminAuditEvent;
use App\Models\Customer;
use App\Models\User;
use App\Operator\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

/** Operators managed by the owner: invitation, permissions, disable and enable. */
class OwnerOperatorsTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    private function invite(array $permissions = [], bool $confirm = false): User
    {
        $this->post(route('owner.operators.store'), [
            'name' => 'Olga Operator',
            'email' => 'Olga@WPcaregrid.example',
            'permissions' => array_map(fn (Permission $p) => $p->value, $permissions),
            'confirm_sensitive' => $confirm ? '1' : '0',
        ])->assertRedirect();

        return User::where('email', 'olga@wpcaregrid.example')->sole();
    }

    /** The link from the invitation email. */
    private function invitationLink(User $operator): string
    {
        $link = null;
        Mail::assertQueued(StaffInvitation::class, function (StaffInvitation $mail) use ($operator, &$link) {
            $link = StaffInvitation::url($mail->user, $mail->token);

            return $mail->user->is($operator);
        });

        return $link;
    }

    public function test_an_invited_operator_gets_exactly_the_ticked_permissions_and_sets_their_own_password(): void
    {
        Mail::fake();
        $owner = $this->asOwner();
        $operator = $this->invite([Permission::WebsitesView, Permission::BackupsView]);

        $this->assertTrue($operator->isOperator());
        $this->assertSame(['backups.view', 'websites.view'], $operator->operator_permissions);
        $this->assertFalse($operator->hasVerifiedEmail(), 'Not usable until the invitation is accepted.');
        $this->assertFalse($operator->isActiveOperator());

        $event = AdminAuditEvent::where('event', 'operator.invited')->sole();
        $this->assertSame([$owner->id, (string) $operator->id, ['backups.view', 'websites.view']], [$event->actor_user_id, $event->subject_key, $event->data['permissions']]);

        // The email carries a link, never a password.
        $link = $this->invitationLink($operator);
        $html = (new StaffInvitation($operator, 'TOKEN'))->render();
        $this->assertStringContainsString('Set your password', $html);

        // Before activation the operator console refuses the login.
        $this->post(route('operator.login.store'), ['email' => 'olga@wpcaregrid.example', 'password' => 'chosen-password-123'])->assertSessionHasErrors('email');

        $token = basename(parse_url($link, PHP_URL_PATH));
        $this->get($link)->assertOk()->assertSee('Set your password');
        $this->post(route('staff.activate.store'), ['token' => $token, 'email' => 'olga@wpcaregrid.example', 'password' => 'chosen-password-123', 'password_confirmation' => 'chosen-password-123'])
            ->assertRedirect(route('operator.login'));
        $this->assertTrue(Hash::check('chosen-password-123', $operator->refresh()->password));
        $this->assertTrue($operator->isActiveOperator());
        $this->assertGuest('operator');

        // Single use.
        $this->post(route('staff.activate.store'), ['token' => $token, 'email' => 'olga@wpcaregrid.example', 'password' => 'other-password-123', 'password_confirmation' => 'other-password-123'])->assertSessionHasErrors('email');

        $this->post(route('operator.login.store'), ['email' => 'olga@wpcaregrid.example', 'password' => 'chosen-password-123'])->assertRedirect(route('operator.dashboard'));
        $this->assertAuthenticatedAs($operator, 'operator');
    }

    public function test_nothing_is_granted_without_ticking_it(): void
    {
        Mail::fake();
        $this->asOwner();

        $operator = $this->invite();

        $this->assertSame([], $operator->operator_permissions);
        $this->assertSame([], $operator->operatorPermissions());
    }

    public function test_sensitive_permissions_need_an_explicit_confirmation(): void
    {
        Mail::fake();
        $this->asOwner();

        $this->post(route('owner.operators.store'), ['name' => 'X', 'email' => 'x@wpcaregrid.example', 'permissions' => ['security.remediate']])->assertSessionHasErrors('confirm_sensitive');
        $this->assertSame(0, User::where('email', 'x@wpcaregrid.example')->count());

        $operator = User::factory()->operator([Permission::WebsitesView])->create();
        foreach (['backups.restore', 'security.remediate', 'admin_access.full'] as $sensitive) {
            $this->put(route('owner.operators.permissions', $operator), ['permissions' => ['websites.view', $sensitive]])->assertSessionHasErrors('confirm_sensitive');
            $this->assertSame(['websites.view'], $operator->refresh()->operator_permissions);
        }

        $this->put(route('owner.operators.permissions', $operator), ['permissions' => ['websites.view', 'admin_access.full'], 'confirm_sensitive' => '1'])->assertSessionHas('status');
        $this->assertSame(['admin_access.full', 'websites.view'], $operator->refresh()->operator_permissions);

        // Keeping an already granted sensitive permission does not ask again.
        $this->put(route('owner.operators.permissions', $operator), ['permissions' => ['admin_access.full', 'backups.view']])->assertSessionHasNoErrors();
        $this->assertSame(['admin_access.full', 'backups.view'], $operator->refresh()->operator_permissions);
    }

    public function test_permission_changes_are_audited_with_what_was_granted_and_revoked(): void
    {
        $this->asOwner();
        $operator = User::factory()->operator([Permission::WebsitesView, Permission::BackupsView])->create();

        $this->put(route('owner.operators.permissions', $operator), ['permissions' => ['websites.view', 'updates.view']])->assertSessionHas('status');

        $event = AdminAuditEvent::where('event', 'operator.permissions_changed')->sole();
        $this->assertSame(['updates.view'], $event->data['granted']);
        $this->assertSame(['backups.view'], $event->data['revoked']);

        // Unknown permissions are refused, not stored.
        $this->put(route('owner.operators.permissions', $operator), ['permissions' => ['websites.view', 'owner.everything']])->assertSessionHasErrors('permissions.1');
        $this->assertSame(['updates.view', 'websites.view'], $operator->refresh()->operator_permissions);

        // No change, no audit entry.
        $this->put(route('owner.operators.permissions', $operator), ['permissions' => ['updates.view', 'websites.view']]);
        $this->assertSame(1, AdminAuditEvent::where('event', 'operator.permissions_changed')->count());
    }

    public function test_disabling_an_operator_ends_console_access_at_once_and_enabling_restores_it(): void
    {
        $this->asOwner();
        $operator = User::factory()->operator([Permission::WebsitesView])->create(['password' => 'operator-password-1']);

        $this->actingAs($operator, 'operator');
        $this->get(route('operator.dashboard'))->assertOk();

        $this->post(route('owner.operators.disable', $operator))->assertSessionHas('status');
        $this->assertNotNull($operator->refresh()->operator_disabled_at);
        $this->assertFalse($operator->hasOperatorPermission(Permission::WebsitesView), 'A disabled operator holds no permission.');

        $this->get(route('operator.dashboard'))->assertRedirect(route('operator.login'));
        $this->assertGuest('operator');
        $this->post(route('operator.login.store'), ['email' => $operator->email, 'password' => 'operator-password-1'])->assertSessionHasErrors('email');

        $this->post(route('owner.operators.enable', $operator))->assertSessionHas('status');
        $this->assertSame(['websites.view'], $operator->refresh()->operator_permissions, 'Permissions were kept.');
        $this->post(route('operator.login.store'), ['email' => $operator->email, 'password' => 'operator-password-1'])->assertRedirect(route('operator.dashboard'));

        $this->assertSame(['operator.disabled', 'operator.enabled'], AdminAuditEvent::whereIn('event', ['operator.disabled', 'operator.enabled'])->orderBy('id')->pluck('event')->all());
    }

    public function test_a_disabled_operator_cannot_accept_an_invitation(): void
    {
        Mail::fake();
        $this->asOwner();
        $operator = $this->invite([Permission::WebsitesView]);
        $link = $this->invitationLink($operator);

        $this->post(route('owner.operators.disable', $operator));

        // Disabling deletes the pending link...
        $this->post(route('staff.activate.store'), ['token' => basename(parse_url($link, PHP_URL_PATH)), 'email' => $operator->email, 'password' => 'chosen-password-123', 'password_confirmation' => 'chosen-password-123'])->assertSessionHasErrors('email');
        $this->assertFalse($operator->refresh()->hasVerifiedEmail());

        // ...and even a valid token is refused while the operator is disabled.
        $token = Password::broker('staff_invitations')->createToken($operator);
        $this->post(route('staff.activate.store'), ['token' => $token, 'email' => $operator->email, 'password' => 'chosen-password-123', 'password_confirmation' => 'chosen-password-123'])->assertSessionHasErrors('email');
        $this->assertFalse($operator->refresh()->hasVerifiedEmail());
    }

    public function test_an_existing_login_cannot_be_turned_into_an_operator(): void
    {
        Mail::fake();
        $this->asOwner();
        $customer = Customer::factory()->withUser()->create(['email' => 'anna@company.example']);
        $owner = $this->owner(['email' => 'boss@wpcaregrid.example']);

        foreach (['anna@company.example', 'boss@wpcaregrid.example'] as $email) {
            $this->post(route('owner.operators.store'), ['name' => 'X', 'email' => $email, 'permissions' => ['websites.view']])->assertSessionHasErrors('email');
        }

        $this->assertFalse($customer->user->refresh()->isOperator());
        $this->assertTrue($owner->refresh()->isOwner());
        Mail::assertNothingQueued();
    }

    public function test_a_new_invitation_replaces_the_previous_link(): void
    {
        Mail::fake();
        $this->asOwner();
        $operator = $this->invite([Permission::WebsitesView]);
        $first = basename(parse_url($this->invitationLink($operator), PHP_URL_PATH));

        $this->post(route('owner.operators.invitation', $operator))->assertSessionHas('status');
        Mail::assertQueued(StaffInvitation::class, 2);

        $this->post(route('staff.activate.store'), ['token' => $first, 'email' => $operator->email, 'password' => 'chosen-password-123', 'password_confirmation' => 'chosen-password-123'])->assertSessionHasErrors('email');
        $this->assertSame(1, AdminAuditEvent::where('event', 'operator.invitation_resent')->count());
    }

    public function test_staff_invitation_tokens_and_customer_activation_tokens_are_separate(): void
    {
        Mail::fake();
        $this->asOwner();
        $operator = $this->invite([Permission::WebsitesView]);
        $staffToken = basename(parse_url($this->invitationLink($operator), PHP_URL_PATH));

        // A staff invitation token is not a customer activation or password reset token.
        $this->post(route('account.activate.store'), ['token' => $staffToken, 'email' => $operator->email, 'password' => 'chosen-password-123', 'password_confirmation' => 'chosen-password-123']);
        $this->assertFalse($operator->refresh()->hasVerifiedEmail());

        // And a customer's activation token does not work as a staff invitation.
        $customer = Customer::factory()->create(['email' => 'new@company.example']);
        $user = User::factory()->unverified()->create(['email' => 'new@company.example']);
        $customer->user()->associate($user)->save();
        $customerToken = Password::broker('activations')->createToken($user);
        $this->post(route('staff.activate.store'), ['token' => $customerToken, 'email' => 'new@company.example', 'password' => 'chosen-password-123', 'password_confirmation' => 'chosen-password-123'])->assertSessionHasErrors('email');
        $this->assertFalse($user->refresh()->hasVerifiedEmail());
    }

    public function test_the_operator_pages_never_show_passwords(): void
    {
        $this->asOwner();
        $operator = User::factory()->operator([Permission::WebsitesView])->create();

        $this->get(route('owner.operators.index'))->assertOk()->assertSee($operator->email)->assertDontSee($operator->password);
        $this->get(route('owner.operators.show', $operator))->assertOk()->assertSee('websites.view')->assertDontSee($operator->password)->assertDontSee($operator->remember_token);
    }

    public function test_the_cli_still_creates_operators_and_refuses_owner_logins(): void
    {
        $this->assertSame(0, Artisan::call('operator:user', ['email' => 'cli@wpcaregrid.example', '--grant' => ['websites.view']]));
        $this->assertSame(['websites.view'], User::where('email', 'cli@wpcaregrid.example')->sole()->operator_permissions);

        $owner = $this->owner(['email' => 'boss@wpcaregrid.example']);
        $this->assertSame(1, Artisan::call('operator:user', ['email' => 'boss@wpcaregrid.example', '--standard' => true]));
        $this->assertTrue($owner->refresh()->isOwner());
    }

    public function test_owners_are_created_from_the_cli_by_invitation(): void
    {
        Mail::fake();

        $this->assertSame(0, Artisan::call('owner:user', ['email' => 'Boss@WPcaregrid.example', '--name' => 'Boss']));
        $owner = User::where('email', 'boss@wpcaregrid.example')->sole();
        $this->assertTrue($owner->isOwner());
        $this->assertFalse($owner->hasVerifiedEmail());
        $output = Artisan::output(); // fetch() empties the buffer: read it once
        $this->assertStringContainsString('/staff/activate/', $output);
        $this->assertStringNotContainsStringIgnoringCase('password:', $output);
        Mail::assertQueued(StaffInvitation::class);

        $link = trim(collect(explode("\n", $output))->first(fn ($line) => str_contains($line, '/staff/activate/')));
        $this->post(route('staff.activate.store'), ['token' => basename(parse_url($link, PHP_URL_PATH)), 'email' => 'boss@wpcaregrid.example', 'password' => 'owner-password-123', 'password_confirmation' => 'owner-password-123'])
            ->assertRedirect(route('owner.login'));
        $this->post(route('owner.login.store'), ['email' => 'boss@wpcaregrid.example', 'password' => 'owner-password-123'])->assertRedirect(route('owner.dashboard'));

        // Customer and operator logins are not turned into owners.
        $customer = Customer::factory()->withUser()->create(['email' => 'anna@company.example']);
        $this->assertSame(1, Artisan::call('owner:user', ['email' => 'anna@company.example']));
        $this->assertFalse($customer->user->refresh()->isOwner());

        $this->assertSame(0, Artisan::call('owner:user', ['email' => 'boss@wpcaregrid.example', '--remove' => true]));
        $this->assertFalse($owner->refresh()->isOwner());
    }
}
