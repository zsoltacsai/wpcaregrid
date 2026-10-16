<?php

namespace WPCareGrid\Connector\Security\Integrity;

/**
 * Official checksums from WordPress.org, the only reference used:
 *
 *   core     api.wordpress.org/core/checksums/1.0 (what get_core_checksums() uses), MD5 per file
 *   plugins  downloads.wordpress.org/plugin-checksums/{slug}/{version}.json, SHA-256 per file
 *   themes   WordPress.org publishes none: integrity is "unavailable", never guessed
 *
 * Results are cached for a day per version; a failed lookup is cached for
 * an hour so a scan does not hammer WordPress.org.
 */
final class Checksums
{
    private const CORE_URL = 'https://api.wordpress.org/core/checksums/1.0/';

    private const PLUGIN_URL = 'https://downloads.wordpress.org/plugin-checksums/%s/%s.json';

    /** @return array<string, string>|null path => md5, or null when unavailable. */
    public static function core(string $version, string $locale = 'en_US'): ?array
    {
        if (! preg_match('/^\d+\.\d+(\.\d+)?([.-][0-9A-Za-z]+)?$/', $version)) {
            return null;
        }

        $key = 'wp_caregrid_core_sums_'.md5($version.'|'.$locale);
        $cached = get_site_transient($key);
        if (is_array($cached)) {
            return $cached['sums'] ?: null;
        }

        $sums = self::fetch_core($version, $locale);
        if (! is_array($sums) && $locale !== 'en_US') {
            $sums = self::fetch_core($version, 'en_US');
        }

        $clean = [];
        foreach (is_array($sums) ? $sums : [] as $path => $md5) {
            if (is_string($path) && is_string($md5) && preg_match('/^[a-f0-9]{32}$/', $md5)) {
                $clean[$path] = $md5;
            }
        }

        set_site_transient($key, ['sums' => $clean], $clean ? DAY_IN_SECONDS : HOUR_IN_SECONDS);

        return $clean ?: null;
    }

    /** @return array<string, array{md5: ?string, sha256: ?string}>|null relative path inside the plugin => hashes */
    public static function plugin(string $slug, string $version): ?array
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,190}$/', $slug) || ! preg_match('/^[0-9][0-9A-Za-z.\-]{0,31}$/', $version)) {
            return null;
        }

        $key = 'wp_caregrid_plugin_sums_'.md5($slug.'|'.$version);
        $cached = get_site_transient($key);
        if (is_array($cached)) {
            return $cached['sums'] ?: null;
        }

        $response = wp_remote_get(sprintf(self::PLUGIN_URL, rawurlencode($slug), rawurlencode($version)), ['timeout' => 15, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 10485760]);
        $body = is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200 ? null : json_decode(wp_remote_retrieve_body($response), true);

        $clean = [];
        foreach (is_array($body['files'] ?? null) ? $body['files'] : [] as $path => $hashes) {
            if (! is_string($path) || ! is_array($hashes)) {
                continue;
            }
            // The API lists one hash, or several when a file changed between builds; any listed hash is clean.
            $clean[$path] = [
                'md5' => self::hashes($hashes['md5'] ?? null, 32),
                'sha256' => self::hashes($hashes['sha256'] ?? null, 64),
            ];
        }

        set_site_transient($key, ['sums' => $clean], $clean ? DAY_IN_SECONDS : HOUR_IN_SECONDS);

        return $clean ?: null;
    }

    /**
     * The endpoint get_core_checksums() uses, over HTTPS only: WordPress's own
     * function falls back to plain HTTP and raises a PHP warning on failure.
     */
    private static function fetch_core(string $version, string $locale): ?array
    {
        $response = wp_remote_get(self::CORE_URL.'?'.http_build_query(['version' => $version, 'locale' => $locale], '', '&'), ['timeout' => 15, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 10485760]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = json_decode(trim(wp_remote_retrieve_body($response)), true);

        return is_array($body['checksums'] ?? null) ? $body['checksums'] : null;
    }

    /** @return string|null Comma-joined valid hashes. */
    private static function hashes(mixed $value, int $length): ?string
    {
        $list = array_filter((array) $value, fn ($h) => is_string($h) && preg_match('/^[a-f0-9]{'.$length.'}$/', $h));

        return $list ? implode(',', $list) : null;
    }

    public static function matches(?string $listed, string $actual): bool
    {
        return $listed !== null && in_array($actual, explode(',', $listed), true);
    }
}
