<?php

namespace WPCareGrid\Connector\Licenses;

/**
 * License state per plugin, only as far as it can be known.
 *
 *   valid / expired  only when a provider reports it with evidence from the
 *                    plugin itself (e.g. the plugin's own license API)
 *   unknown          the plugin looks commercial (it updates from somewhere
 *                    other than WordPress.org) but its license cannot be read
 *   not_available    no license concept detected (e.g. a WordPress.org plugin)
 *
 * "Could not check" is never reported as expired or valid.
 *
 * Providers can be added with the `wp_caregrid_license_providers` filter:
 * callables receiving ($plugin_file, $plugin_data) and returning null or
 * array( 'status' => 'valid'|'expired', 'expires_at' => ?string ).
 */
final class LicenseDetector
{
    public const VALID = 'valid';

    public const EXPIRED = 'expired';

    public const UNKNOWN = 'unknown';

    public const NOT_AVAILABLE = 'not_available';

    /** @return array{status: string, expires_at: ?string, source: string} */
    public function detect(string $file, array $data, ?string $update_source): array
    {
        foreach ((array) apply_filters('wp_caregrid_license_providers', []) as $name => $provider) {
            if (! is_callable($provider)) {
                continue;
            }

            $reported = $provider($file, $data);

            // Providers may only assert valid or expired; anything else is ignored.
            if (is_array($reported) && in_array($reported['status'] ?? null, [self::VALID, self::EXPIRED], true)) {
                return [
                    'status' => $reported['status'],
                    'expires_at' => isset($reported['expires_at']) && strtotime((string) $reported['expires_at']) ? gmdate('Y-m-d', strtotime((string) $reported['expires_at'])) : null,
                    'source' => is_string($name) ? substr(sanitize_key($name), 0, 64) : 'provider',
                ];
            }
        }

        return [
            'status' => $this->looks_commercial($data, $update_source) ? self::UNKNOWN : self::NOT_AVAILABLE,
            'expires_at' => null,
            'source' => 'detection',
        ];
    }

    private function looks_commercial(array $data, ?string $update_source): bool
    {
        $update_uri = (string) ($data['UpdateURI'] ?? '');

        if ($update_uri !== '' && ! preg_match('#^(https?://)?(w\.org|wordpress\.org)(/|$)#i', $update_uri)) {
            return true;
        }

        return $update_source !== null && $update_source !== 'wordpress.org';
    }
}
