<?php

use WPCareGrid\Connector\Core\Plugin;

/**
 * Boots a real WordPress for the connector tests: WordPress core from
 * Composer, SQLite instead of MySQL, a fresh site per run. Only outbound
 * HTTP is replaced (see Support/FakeHttp.php); options, users, nonces,
 * capabilities, cron and the upgraders are WordPress's own.
 */
$root = sys_get_temp_dir().'/wp-caregrid-tests-'.getmypid();
putenv('WP_CAREGRID_TEST_ROOT='.$root);

$vendor = dirname(__DIR__).'/vendor';
$core = $vendor.'/johnpbloch/wordpress-core';

if (! is_dir($core)) {
    exit("Run `composer install` in wordpress/ first.\n");
}

$copy = static function (string $from, string $to) use (&$copy): void {
    if (is_dir($from)) {
        @mkdir($to, 0777, true);
        foreach (scandir($from) as $item) {
            if ($item !== '.' && $item !== '..') {
                $copy("{$from}/{$item}", "{$to}/{$item}");
            }
        }
    } else {
        copy($from, $to);
    }
};

$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && ! is_link($path)) {
        foreach (scandir($path) as $item) {
            if ($item !== '.' && $item !== '..') {
                $remove("{$path}/{$item}");
            }
        }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        unlink($path);
    }
};

$remove($root);
mkdir($root.'/database', 0777, true);
mkdir($root.'/wp-content/plugins', 0777, true);
mkdir($root.'/wp-content/uploads', 0777, true);
copy($vendor.'/aaemnnosttv/wp-sqlite-db/src/db.php', $root.'/wp-content/db.php');
$copy($core.'/wp-content/themes/twentytwentyfive', $root.'/wp-content/themes/twentytwentyfive');
// A copy, not a symlink, so plugin_basename() works as on a real site.
$copy(dirname(__DIR__).'/wp-caregrid-connector', $root.'/wp-content/plugins/wp-caregrid-connector');

exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/install.php').' 2>&1', $output, $status);
if ($status !== 0) {
    exit("Installing WordPress failed:\n".implode("\n", $output)."\n");
}
fwrite(STDOUT, implode("\n", $output).PHP_EOL);

if (! getenv('WP_CAREGRID_KEEP_SITE')) {
    register_shutdown_function(static fn () => $remove($root));
}

require __DIR__.'/wp-tests-config.php';

// PHPUnit includes this file inside a function; WordPress expects globals.
global $wpdb, $wp_version, $wp_db_version, $wp_rewrite, $wp, $wp_query, $wp_the_query, $current_user;
global $wp_filter, $wp_actions, $wp_current_filter, $shortcode_tags, $wp_roles, $wp_locale, $wp_plugin_paths;

require ABSPATH.'wp-settings.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
require_once ABSPATH.'wp-admin/includes/admin.php';

if (! is_plugin_active('wp-caregrid-connector/wp-caregrid-connector.php') || ! class_exists(Plugin::class)) {
    exit("The connector is not active in the test site.\n");
}
