<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A public website address: http(s), a real domain name, default ports.
 * Rejects IP literals, localhost and internal-only names, so a stored
 * website URL can never point at the platform's own network (for example
 * cloud metadata at 169.254.169.254) if anything ever fetches it.
 */
class PublicWebsiteUrl implements ValidationRule
{
    private const INTERNAL_SUFFIXES = ['localhost', 'local', 'internal', 'intranet', 'lan', 'home', 'corp', 'home.arpa', 'localdomain'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = is_string($value) ? parse_url($value) : false;
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        $valid = $parts
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ! isset($parts['user'], $parts['pass'])
            && ! isset($parts['user'])
            && (! isset($parts['port']) || in_array($parts['port'], [80, 443], true))
            && $host !== ''
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && ! preg_match('/^\d+$/', str_replace('.', '', $host)) // decimal/octal IP tricks
            && preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/', $host)
            && ! collect(self::INTERNAL_SUFFIXES)->contains(fn ($suffix) => $host === $suffix || str_ends_with($host, '.'.$suffix));

        if (! $valid) {
            $fail(__('account.websites.invalid_url'));
        }
    }
}
