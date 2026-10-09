<?php

use WPCareGrid\Connector\Core\Plugin;

/**
 * Boots a real WordPress for the connector tests: WordPress core from
 * Composer, SQLite instead of MySQL, a fresh site per run. Only outbound
 * HTTP is replaced (see Support/FakeHttp.php); options, users, nonces,
 * capabilities, cron and the upgraders are WordPress's own.
 */
$rootToken = (string) getenv('WP_CAREGRID_TEST_RUN_TOKEN');
if (! preg_match('/^[a-f0-9]{16}$/D', $rootToken)) {
    $rootToken = bin2hex(random_bytes(8));
}
$root = sys_get_temp_dir().'/wp-caregrid-tests-'.getmypid().'-'.$rootToken;
$marker = $root.'/.wp-caregrid-test-owned';
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

if (file_exists($root) || is_link($root) || ! mkdir($root, 0777, true)) {
    exit("Refusing to reuse an existing WordPress test directory.\n");
}
file_put_contents($marker, $rootToken, LOCK_EX);
mkdir($root.'/database', 0777, true);
mkdir($root.'/wp-content/plugins', 0777, true);
mkdir($root.'/wp-content/uploads', 0777, true);
$mysql = null;
if (preg_match('#^([^:]+):([^@]*)@([^/]+)/(\w+)$#', (string) getenv('WP_CAREGRID_TEST_MYSQL'), $mysql)) {
    // A fresh site every run: empty the test database first.
    $link = new mysqli($mysql[3], $mysql[1], $mysql[2], $mysql[4]);
    foreach ($link->query('SHOW TABLES')->fetch_all() as [$table]) {
        $link->query("DROP TABLE `{$table}`");
    }
    $link->close();
} else {
    copy($vendor.'/aaemnnosttv/wp-sqlite-db/src/db.php', $root.'/wp-content/db.php');
}
$copy($core.'/wp-content/themes/twentytwentyfive', $root.'/wp-content/themes/twentytwentyfive');
// A copy, not a symlink, so plugin_basename() works as on a real site.
$copy(dirname(__DIR__).'/wp-caregrid-connector', $root.'/wp-content/plugins/wp-caregrid-connector');

$phpIni = php_ini_loaded_file();
$installCommand = escapeshellarg(PHP_BINARY).($phpIni ? ' -c '.escapeshellarg($phpIni) : '').' '.escapeshellarg(__DIR__.'/install.php').' 2>&1';
exec($installCommand, $output, $status);
if ($status !== 0) {
    exit("Installing WordPress failed:\n".implode("\n", $output)."\n");
}
fwrite(STDOUT, implode("\n", $output).($mysql ? ' Database: MySQL/MariaDB.' : ' Database: SQLite (database backup tests are skipped).').PHP_EOL);

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

if (! getenv('WP_CAREGRID_KEEP_SITE') && PHP_OS_FAMILY !== 'Windows') {
    // Register after wp-settings has created SQLite's PDOEngine destructor.
    // That callback must release its handle before Windows can unlink wp.sqlite.
    register_shutdown_function(static function () use ($root, $rootToken, $marker, $remove): void {
        $temp = rtrim((string) realpath(sys_get_temp_dir()), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $realRoot = realpath($root);
        $expectedName = 'wp-caregrid-tests-'.getmypid().'-'.$rootToken;
        if (! $realRoot || ! str_starts_with($realRoot.DIRECTORY_SEPARATOR, $temp)
            || basename($realRoot) !== $expectedName || ! is_file($marker)
            || file_get_contents($marker) !== $rootToken) {
            fwrite(STDERR, "Skipped cleanup because the test-owned directory marker did not match.\n");

            return;
        }

        unset($GLOBALS['@pdo'], $GLOBALS['wpdb']);
        gc_collect_cycles();
        $remove($realRoot);
    });
}
