<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Owner\AdminAudit;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Owner sign-in, on its own guard. Only verified owner logins without a
 * customer account get in; everyone else (customers, operators) sees the
 * same failure message.
 */
class LoginController extends Controller
{
    public function show(): View
    {
        return view('owner.auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $guard = Auth::guard('owner');
        $attempt = [
            'email' => mb_strtolower(trim($credentials['email'])),
            'password' => $credentials['password'],
            fn (Builder $query) => $query->where('role', User::ROLE_OWNER)->whereNotNull('email_verified_at')->whereDoesntHave('customer'),
        ];

        if (! $guard->attempt($attempt)) {
            Log::notice('Owner sign-in refused.', ['ip' => $request->ip()]);

            throw ValidationException::withMessages(['email' => __('owner.auth.failed')]);
        }

        // New session ID after login prevents session fixation.
        $request->session()->regenerate();
        AdminAudit::record($guard->user(), 'owner.signed_in', 'owner', (string) $guard->id());

        // Only follow an intended URL inside the owner area (the session is shared with the other areas).
        $intended = (string) $request->session()->pull('url.intended', '');
        $home = route('owner.dashboard');

        return redirect()->to(str_starts_with($intended, $home) ? $intended : $home);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('owner')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('owner.login')->with('status', __('owner.auth.logged_out'));
    }
}
