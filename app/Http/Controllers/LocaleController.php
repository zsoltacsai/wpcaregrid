<?php

namespace App\Http\Controllers;

use App\Localization\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

/**
 * Changes the display language. The only input is a supported language code
 * (anything else is refused and changes nothing). A signed-in customer's own
 * preference is saved on their own login; no id is ever taken from the request.
 */
class LocaleController extends Controller
{
    /** Fields that never go back into a form after a language switch. */
    private const NEVER_RESTORED = ['_token', '_method', 'locale', 'password', 'password_confirmation', 'current_password', 'token'];

    public function __invoke(Request $request): RedirectResponse
    {
        $locale = $request->validate(['locale' => ['required', 'string', Rule::in(Locales::codes())]])['locale'];

        if ($user = $request->user('web')) {
            $user->forceFill(['locale' => $locale])->save();
        }

        Cookie::queue(Locales::cookie($locale));

        // The form the visitor was filling in comes back with what they had typed, minus secrets.
        return redirect()->to(Locales::returnUrl($request))->withInput($request->except(self::NEVER_RESTORED));
    }
}
