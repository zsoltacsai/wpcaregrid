<?php

namespace App\Localization;

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The supported display languages and how one is chosen for a request. The
 * locale is presentation only: prices, currencies and billing data are
 * always resolved on the server from the pricing catalogue and the billing
 * country, never from the language.
 */
final class Locales
{
    /** @return array<string, string> code => name in its own language */
    public static function supported(): array
    {
        return (array) config('localization.locales');
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::supported());
    }

    public static function isSupported(mixed $code): bool
    {
        return is_string($code) && array_key_exists($code, self::supported());
    }

    /** APP_LOCALE (as configured at boot) when it is supported, else the first supported language. */
    public static function default(): string
    {
        $default = (string) config('localization.default');

        return self::isSupported($default) ? $default : (self::codes()[0] ?? 'en');
    }

    /** The owner area, the operator console and staff invitations stay English. */
    public static function isEnglishOnly(Request $request): bool
    {
        return $request->is(...(array) config('localization.english_only'));
    }

    /**
     * The language for this request: English in the staff areas; otherwise
     * the choice remembered on this device (the cookie, which also carries a
     * choice made before signing in), else a signed-in customer's saved
     * preference (it follows them to other devices), else the default. Every
     * candidate is validated against the supported list, so a tampered cookie
     * or stored value is ignored.
     */
    public static function resolve(Request $request): string
    {
        if (self::isEnglishOnly($request)) {
            return 'en';
        }

        $remembered = $request->cookie((string) config('localization.cookie'));
        if (self::isSupported($remembered)) {
            return $remembered;
        }

        $saved = $request->user('web')?->locale;

        return self::isSupported($saved) ? $saved : self::default();
    }

    /**
     * The language for a request that matched no route (a 404 page): the web
     * middleware, and with it cookie decryption and the session, did not run,
     * so the remembered-language cookie is decrypted here. Anything that does
     * not decrypt, or is not a supported language, is ignored.
     */
    public static function resolveWithoutSession(Request $request): string
    {
        if (self::isEnglishOnly($request)) {
            return 'en';
        }

        $name = (string) config('localization.cookie');

        try {
            $raw = $request->cookies->get($name);
            $encrypter = app('encrypter');
            $value = is_string($raw) ? CookieValuePrefix::validate($name, $encrypter->decrypt($raw, false), $encrypter->getAllKeys()) : null;
        } catch (\Throwable) {
            $value = null;
        }

        return self::isSupported($value) ? $value : self::default();
    }

    /** Back to the page the visitor came from, only when it is on this site; otherwise the homepage. */
    public static function returnUrl(Request $request): string
    {
        $previous = (string) $request->headers->get('referer');

        return $previous !== '' && parse_url($previous, PHP_URL_HOST) === $request->getHost() ? $previous : route('home');
    }

    /** The cookie that remembers the choice. Not sensitive; no secret in it. */
    public static function cookie(string $locale): Cookie
    {
        return cookie(
            (string) config('localization.cookie'),
            $locale,
            (int) config('localization.cookie_days') * 24 * 60,
            '/',
            null,
            (bool) config('session.secure'),
            true,
            false,
            'lax',
        );
    }
}
