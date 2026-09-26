<?php

namespace App\Security;

/**
 * Untrusted text from scanners and feeds is stored as text, not HTML:
 * control characters are removed and whitespace is collapsed, but "<" and
 * ">" stay exactly as they are ("Akismet <= 5.3"). Blade escapes it on
 * output ({{ }}); it is never rendered raw.
 */
final class PlainText
{
    public static function clean(string $text, int $max): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, $max);
    }
}
