<?php

namespace WPCareGrid\Connector\Security;

/**
 * Removes secrets before anything is logged or reported: values under
 * sensitive keys, connector credentials, bearer headers and pairing codes.
 */
final class Redactor
{
    private const SENSITIVE_KEY = '/(credential|token|secret|password|passwd|pwd|authorization|auth|api[_-]?key|cookie|pairing_code|salt|nonce)/i';

    private const PATTERNS = [
        '/cgc_[A-Za-z0-9]{8,}/' => 'cgc_[redacted]',
        '/Bearer\s+[A-Za-z0-9._~+\/=-]+/i' => 'Bearer [redacted]',
        '/\b[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}\b/' => '[pairing-code]',
    ];

    public static function redact($value, int $depth = 0)
    {
        if ($depth > 6) {
            return '[truncated]';
        }

        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $clean[$key] = is_string($key) && preg_match(self::SENSITIVE_KEY, $key) ? '[redacted]' : self::redact($item, $depth + 1);
            }

            return $clean;
        }

        if (is_string($value)) {
            return preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $value);
        }

        return is_scalar($value) || $value === null ? $value : '['.get_debug_type($value).']';
    }
}
