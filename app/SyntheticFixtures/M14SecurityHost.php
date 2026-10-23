<?php

namespace App\SyntheticFixtures;

/** Strict canonical host policy for the Owner-configured M14 test site. */
final class M14SecurityHost
{
    public static function isAllowed(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || $host !== strtolower($host)
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || str_ends_with($host, '.') || str_contains($host, '*')) {
            return false;
        }

        $labels = explode('.', $host);
        if (count($labels) < 2 || strlen((string) end($labels)) < 2) {
            return false;
        }

        if (count(array_filter($labels, 'ctype_digit')) === count($labels)) {
            return false;
        }

        foreach ($labels as $label) {
            if (strlen($label) > 63 || ! preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $label)) {
                return false;
            }
        }

        return ! in_array(end($labels), ['localhost', 'local', 'test', 'invalid', 'example', 'onion', 'internal', 'arpa'], true)
            && ! str_ends_with($host, '.home.arpa');
    }

    public static function isExactHttpsUrl(string $url, string $configuredHost): bool
    {
        return self::isAllowed($configuredHost) && $url === 'https://'.$configuredHost;
    }
}
