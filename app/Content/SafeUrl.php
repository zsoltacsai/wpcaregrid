<?php

namespace App\Content;

/**
 * Which link targets owner-edited content may use: an anchor on the page
 * ("#pricing"), a path on this site ("/pricing"), an http(s) URL, or a
 * mailto: address. Everything else is refused: javascript:, data:,
 * vbscript:, protocol-relative "//host", backslashes (which browsers read
 * as slashes), whitespace and control characters.
 *
 * Checked when content is saved and again when it is rendered.
 */
final class SafeUrl
{
    public static function isSafe(string $url, bool $sameHost = false): bool
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
            return false;
        }

        if ($sameHost) {
            return self::isOwnHost($url);
        }

        if (str_starts_with($url, '#')) {
            return preg_match('/^#[A-Za-z][A-Za-z0-9_-]*$/', $url) === 1;
        }

        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return match ($scheme) {
            'http', 'https' => isset($parts['host']) && $parts['host'] !== '' && ! isset($parts['user']) && ! isset($parts['pass']),
            'mailto' => filter_var(substr($url, 7), FILTER_VALIDATE_EMAIL) !== false,
            default => false,
        };
    }

    /** The URL if it is safe, otherwise a harmless anchor. */
    public static function orFallback(?string $url, string $fallback = '#'): string
    {
        return is_string($url) && self::isSafe($url) ? $url : $fallback;
    }

    /** An absolute http(s) URL on this application's host (canonical URLs). */
    public static function isOwnHost(string $url): bool
    {
        $parts = parse_url($url);
        $own = parse_url((string) config('app.url'));

        return in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && isset($parts['host'], $own['host'])
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && strtolower($parts['host']) === strtolower($own['host'])
            && ($parts['port'] ?? null) === ($own['port'] ?? null);
    }
}
