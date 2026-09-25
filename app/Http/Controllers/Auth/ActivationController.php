<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeEmail;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * First password for an account created after purchase. Uses the
 * `activations` password broker: a hashed, single-use, expiring token.
 */
class ActivationController extends Controller
{
    public function show(Request $request, string $token): View
    {
        return view('auth.activate', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ]);

        $activated = null;

        $status = Password::broker('activations')->reset(
            [...$request->only('password', 'password_confirmation', 'token'), 'email' => mb_strtolower(trim($request->input('email')))],
            function (User $user, string $password) use (&$activated) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                $activated = $user;
            },
        );

        if ($status !== Password::PASSWORD_RESET || ! $activated) {
            return redirect()->route('password.request')->withErrors(['email' => __('account.auth.invalid_link')]);
        }

        Auth::login($activated);
        $request->session()->regenerate();

        if ($activated->customer) {
            Mail::to($activated->email)->queue(new WelcomeEmail($activated->customer));
        }

        return redirect()->route('account.dashboard');
    }
}
