<?php

namespace WPCareGrid\Connector\Tests\Support;

use RuntimeException;

/** Test-only guard for the restore fault harness. This file is never shipped with the plugin. */
final class RestoreHarnessGuard
{
    public static function activeRoot(): string
    {
        $root = getenv('WP_CAREGRID_TEST_ROOT');
        $context = [
            'app_env' => getenv('APP_ENV'),
            'enabled' => getenv('WP_CAREGRID_RESTORE_HARNESS') === '1',
            'root' => is_string($root) ? $root : '',
            'site_url' => function_exists('get_option') ? (string) get_option('siteurl', '') : '',
            'synthetic' => function_exists('get_option') && get_option('wp_caregrid_test_fixture') === 'synthetic',
        ];

        self::assertContext($context);

        return realpath($context['root']);
    }

    /** @param array{app_env: mixed, enabled: bool, root: string, site_url: string, synthetic: bool} $context */
    public static function assertContext(array $context): void
    {
        $root = is_string($context['root'] ?? null) ? realpath($context['root']) : false;
        $temp = realpath(sys_get_temp_dir());
        $host = trim((string) parse_url((string) ($context['site_url'] ?? ''), PHP_URL_HOST), '[]');
        $loopback = in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true);

        if (($context['app_env'] ?? null) !== 'testing'
            || ($context['enabled'] ?? false) !== true
            || ! is_string($root)
            || ! is_string($temp)
            || ! str_starts_with($root, rtrim($temp, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
            || ! str_starts_with(basename($root), 'wp-caregrid-tests-')
            || ! $loopback
            || ($context['synthetic'] ?? false) !== true) {
            throw new RuntimeException('The restore fault harness is available only for an activated synthetic loopback test fixture.');
        }
    }
}
