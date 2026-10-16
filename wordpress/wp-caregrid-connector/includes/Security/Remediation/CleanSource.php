<?php

namespace WPCareGrid\Connector\Security\Remediation;

use WPCareGrid\Connector\Security\Quarantine\QuarantineFailure;

/**
 * Downloads one clean file from WordPress.org for the version installed on
 * this site, and verifies it against the official checksum before anything
 * is written. Hosts are fixed here; WP CareGrid supplies no URL.
 *
 *   core     core.svn.wordpress.org/tags/{version}/{path}          (MD5 from get_core_checksums)
 *   plugin   plugins.svn.wordpress.org/{slug}/tags/{version}/{file} (SHA-256 from plugin checksums)
 */
final class CleanSource
{
    private const CORE = 'https://core.svn.wordpress.org/tags/%s/%s';

    private const PLUGIN = 'https://plugins.svn.wordpress.org/%s/tags/%s/%s';

    private const MAX_SIZE = 10485760;

    /** @throws QuarantineFailure */
    public static function core(string $version, string $path): string
    {
        return self::fetch(sprintf(self::CORE, rawurlencode($version), self::encode_path($path)));
    }

    /** @throws QuarantineFailure */
    public static function plugin(string $slug, string $version, string $file): string
    {
        return self::fetch(sprintf(self::PLUGIN, rawurlencode($slug), rawurlencode($version), self::encode_path($file)));
    }

    private static function encode_path(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /** @throws QuarantineFailure */
    private static function fetch(string $url): string
    {
        $response = wp_remote_get($url, ['timeout' => 30, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => self::MAX_SIZE + 1]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            throw new QuarantineFailure('SOURCE_UNAVAILABLE', 'The clean file could not be downloaded from WordPress.org.');
        }

        $body = (string) wp_remote_retrieve_body($response);
        if (strlen($body) > self::MAX_SIZE) {
            throw new QuarantineFailure('SOURCE_UNAVAILABLE', 'The clean file exceeds the supported download limit.');
        }

        return $body;
    }
}
