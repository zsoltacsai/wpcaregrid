<?php

namespace App\Http\Controllers\Auth;

use App\Auth\SessionRevoker;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * An invited operator or owner sets their own password (the
 * `staff_invitations` broker: hashed, single-use, expiring tokens in a
 * table of their own). Nobody is signed in here; they sign in on their own
 * login page afterwards. Customer logins and disabled operators are refused.
 */
class StaffActivationController extends Controller
{
    public function show(Request $request, string $token): View
    {
        return view('auth.staff-activate', ['token' => $token, 'email' => is_string($request->query('email')) ? $request->query('email') : null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ]);

        $email = mb_strtolower(trim($request->input('email')));
        $user = User::where('email', $email)->first();
        $eligible = $user !== null && ! $user->customer()->exists()
            && ($user->isOwner() || ($user->isOperator() && $user->operator_disabled_at === null));

        $status = $eligible ? Password::broker('staff_invitations')->reset(
            [...$request->only('password', 'password_confirmation', 'token'), 'email' => $email],
            function (User $user, string $password) {
                DB::transaction(function () use ($user, $password) {
                    $user->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                        'email_verified_at' => $user->email_verified_at ?? now(),
                    ])->save();

                    app(SessionRevoker::class)->revoke($user);
                });
            },
        ) : Password::INVALID_USER;

        if ($status !== Password::PASSWORD_RESET) {
            Log::notice('Staff invitation refused.', ['ip' => $request->ip()]);

            return back()->withInput($request->only('email'))->withErrors(['email' => __('owner.invitation.invalid')]);
        }

        Log::info('Staff invitation accepted.', ['user' => $user->id, 'role' => $user->role]);

        return redirect()->route($user->isOwner() ? 'owner.login' : 'operator.login')->with('status', __('owner.invitation.accepted'));
    }
}
