<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Operator sign-in, on its own guard. Only verified operator logins without
 * a customer account get in; everyone else sees the same failure message.
 */
class LoginController extends Controller
{
    public function show(): View
    {
        return view('operator.auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $guard = Auth::guard('operator');
        $attempt = [
            'email' => mb_strtolower(trim($credentials['email'])),
            'password' => $credentials['password'],
            fn (Builder $query) => $query->where('role', User::ROLE_OPERATOR)->whereNotNull('email_verified_at')->whereDoesntHave('customer'),
        ];

        if (! $guard->attempt($attempt)) {
            Log::notice('Operator sign-in refused.', ['ip' => $request->ip()]);

            throw ValidationException::withMessages(['email' => __('operator.auth.failed')]);
        }

        // New session ID after login prevents session fixation.
        $request->session()->regenerate();
        Log::info('Operator signed in.', ['operator' => $guard->id(), 'ip' => $request->ip()]);

        // Only follow an intended URL inside the console (the session is shared with the customer area).
        $intended = (string) $request->session()->pull('url.intended', '');
        $console = route('operator.dashboard');

        return redirect()->to(str_starts_with($intended, $console) ? $intended : $console);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('operator')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('operator.login')->with('status', __('operator.auth.logged_out'));
    }
}
