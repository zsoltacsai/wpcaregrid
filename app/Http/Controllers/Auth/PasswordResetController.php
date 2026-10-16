<?php

namespace App\Http\Controllers\Auth;

use App\Auth\SessionRevoker;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    /** Always the same answer, so the form cannot reveal which emails have accounts. */
    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:254']]);

        Password::sendResetLink(['email' => mb_strtolower(trim($request->input('email')))]);

        return back()->with('status', __('account.auth.link_sent'));
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ]);

        $status = Password::reset(
            [...$request->only('password', 'password_confirmation', 'token'), 'email' => mb_strtolower(trim($request->input('email')))],
            function (User $user, string $password) {
                DB::transaction(function () use ($user, $password) {
                    $user->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                        // Using the emailed link proves the address.
                        'email_verified_at' => $user->email_verified_at ?? now(),
                    ])->save();

                    app(SessionRevoker::class)->revoke($user);
                });

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))->withErrors(['email' => __('account.auth.invalid_link')]);
        }

        foreach (array_keys(config('auth.guards', [])) as $guard) {
            Auth::guard($guard)->logout(false);
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', __('account.auth.reset_done'));
    }
}
