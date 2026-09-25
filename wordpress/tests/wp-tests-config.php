<?php

/**
 * wp-config for the test site. Shared by the installer (separate process)
 * and the test run. WordPress core stays read-only in vendor/; everything
 * the tests write (database, plugins, themes, uploads) lives in a
 * throw-away directory.
 */
$root = getenv('WP_CAREGRID_TEST_ROOT');

if (! $root) {
    exit("WP_CAREGRID_TEST_ROOT is not set.\n");
}

define('ABSPATH', dirname(__DIR__).'/vendor/johnpbloch/wordpress-core/');
define('WP_CONTENT_DIR', $root.'/wp-content');
define('WP_CONTENT_URL', 'https://site.example/wp-content');

// SQLite drop-in (wp-content/db.php).
define('DB_DIR', $root.'/database/');
define('DB_FILE', 'wp.sqlite');
define('DB_NAME', 'wordpress');
define('DB_USER', '');
define('DB_PASSWORD', '');
define('DB_HOST', '');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $salt) {
    define($salt, 'wp-caregrid-tests-'.strtolower($salt));
}

define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
// Off: PHPUnit would print every error_log() line. The connector writes the
// same redacted entries to its own log option, which the tests inspect.
define('WP_DEBUG_LOG', false);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('FS_METHOD', 'direct');
define('DISABLE_WP_CRON', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_DEFAULT_THEME', 'twentytwentyfive');

// Connector: test mode (no exit after admin redirects) and a fake API host.
// Every HTTP request is answered by tests/Support/FakeHttp.php.
define('WP_CAREGRID_TESTING', true);
define('WP_CAREGRID_API_URL', 'https://app.caregrid.test/api/v1/connector');

$GLOBALS['table_prefix'] = 'wp_';

// No mail server in tests (pluggable function, defined before WordPress loads it).
if (! function_exists('wp_mail')) {
    function wp_mail(...$args): bool
    {
        return true;
    }
}

$_SERVER['HTTP_HOST'] = 'site.example';
$_SERVER['SERVER_NAME'] = 'site.example';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
