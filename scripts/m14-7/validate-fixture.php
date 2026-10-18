<?php

declare(strict_types=1);

/**
 * Installs and validates the M147-A disposable WordPress fixture.
 * This script intentionally does not activate or pair the Connector, start a
 * server, invoke scanners, or make provider/customer requests.
 */

const FIXTURE_RELATIVE_PATH = 'storage/e2e/m14-7-local-fixture';
const MARKER_NAME = '.wpcaregrid-m147-a-fixture.json';
const FIXTURE_PURPOSE = 'm147-a-local-disposable-wordpress';
const ALLOWED_MODES = ['install', 'health'];

function blocked(string $code): never
{
    fwrite(STDERR, json_encode(['classification' => 'BLOCKED', 'reason' => $code], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(3);
}

function safeFile(string $path): bool
{
    return is_file($path) && !is_link($path) && realpath($path) === $path;
}

function gitValue(array $arguments, string $repository): string
{
    $process = proc_open(
        array_merge(['git'], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $repository,
        ['PATH' => getenv('PATH') ?: '']
    );
    if (!is_resource($process)) {
        blocked('GIT_IDENTITY_UNAVAILABLE');
    }
    fclose($pipes[0]);
    $value = trim((string) stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || $error !== '') {
        blocked('GIT_IDENTITY_UNAVAILABLE');
    }
    return $value;
}

$repository = realpath(dirname(__DIR__, 2));
if ($repository === false || !preg_match('/^[A-Za-z]:\\\\/', $repository)) {
    blocked('REPOSITORY_PATH_INVALID');
}

$branch = gitValue(['branch', '--show-current'], $repository);
$repositorySha = gitValue(['rev-parse', 'HEAD'], $repository);
if (!preg_match('/^codex\/m14-7-[a-z0-9-]+$/', $branch) || !preg_match('/^[a-f0-9]{40}$/', $repositorySha)) {
    blocked('UNAPPROVED_WORKTREE');
}

$fixture = $repository . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, FIXTURE_RELATIVE_PATH);
$markerPath = $fixture . DIRECTORY_SEPARATOR . MARKER_NAME;
$identitiesPath = $fixture . DIRECTORY_SEPARATOR . 'synthetic-identities.json';
$configPath = $fixture . DIRECTORY_SEPARATOR . 'wp-config.php';
$corePath = $fixture . DIRECTORY_SEPARATOR . 'wordpress';
$sqlitePath = $fixture . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'wp.sqlite';
$pluginRelative = 'wp-content/plugins/wp-caregrid-connector/wp-caregrid-connector.php';
$pluginPath = $corePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pluginRelative);

foreach ([$fixture, $corePath, dirname($sqlitePath)] as $directory) {
    if (!is_dir($directory) || is_link($directory) || realpath($directory) !== $directory) {
        blocked('FIXTURE_PATH_INVALID');
    }
}
foreach ([$markerPath, $identitiesPath, $configPath, $corePath . DIRECTORY_SEPARATOR . 'wp-load.php', $pluginPath, $corePath . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'db.php'] as $file) {
    if (!safeFile($file)) {
        blocked('FIXTURE_FILE_INVALID');
    }
}

try {
    $marker = json_decode((string) file_get_contents($markerPath), true, 32, JSON_THROW_ON_ERROR);
    $identities = json_decode((string) file_get_contents($identitiesPath), true, 32, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    blocked('FIXTURE_METADATA_INVALID');
}

if (($marker['purpose'] ?? null) !== FIXTURE_PURPOSE
    || ($marker['repository_root'] ?? null) !== $repository
    || ($marker['repository_sha'] ?? null) !== $repositorySha
    || ($marker['branch'] ?? null) !== $branch
    || ($marker['environment'] ?? null) !== 'local-disposable'
    || !preg_match('/^m147-a-[a-f0-9-]{36}$/', (string) ($marker['target_id'] ?? ''))
    || ($identities['target_id'] ?? null) !== $marker['target_id']
    || ($identities['environment'] ?? null) !== 'local-disposable'
    || ($identities['tenant_id'] ?? null) !== ($marker['synthetic_tenant_id'] ?? null)
    || ($identities['website_id'] ?? null) !== ($marker['synthetic_website_id'] ?? null)
    || !str_ends_with((string) ($identities['website_url'] ?? ''), '.example.invalid')
    || !str_ends_with((string) ($identities['wp_admin_email'] ?? ''), '@example.invalid')) {
    blocked('FIXTURE_MARKER_MISMATCH');
}

$versionSource = file_get_contents($corePath . DIRECTORY_SEPARATOR . 'wp-includes' . DIRECTORY_SEPARATOR . 'version.php');
$connectorSource = file_get_contents($pluginPath);
$release = json_decode((string) file_get_contents(dirname($pluginPath) . DIRECTORY_SEPARATOR . 'release.json'), true);
if (!preg_match('/\$wp_version\s*=\s*\'([^\']+)\'/', (string) $versionSource, $wpMatch)
    || ($wpMatch[1] ?? null) !== ($marker['wordpress_version'] ?? null)
    || !preg_match('/^\s*\*\s*Version:\s*([^\s]+)\s*$/m', (string) $connectorSource, $connectorMatch)
    || ($connectorMatch[1] ?? null) !== ($release['version'] ?? null)
    || ($release['version'] ?? null) !== ($marker['connector_version'] ?? null)) {
    blocked('FIXTURE_VERSION_MISMATCH');
}

if (!extension_loaded('pdo_sqlite') || !extension_loaded('sqlite3')) {
    blocked('SQLITE_EXTENSIONS_UNAVAILABLE');
}
$disabledFunctions = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
foreach (['curl_exec', 'curl_init', 'ftp_connect', 'fsockopen', 'pfsockopen', 'socket_connect', 'socket_create', 'socket_sendto', 'stream_socket_client', 'stream_socket_server'] as $networkFunction) {
    if (function_exists($networkFunction) && !in_array($networkFunction, $disabledFunctions, true)) {
        blocked('PHP_NETWORK_FUNCTIONS_AVAILABLE');
    }
}
if (filter_var((string) ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)
    || filter_var((string) ini_get('allow_url_include'), FILTER_VALIDATE_BOOLEAN)
    || extension_loaded('curl')
    || extension_loaded('sockets')) {
    blocked('PHP_NETWORK_WRAPPERS_AVAILABLE');
}

$mode = $argv[1] ?? '';
if (!in_array($mode, ALLOWED_MODES, true)) {
    blocked('VALIDATION_MODE_INVALID');
}

$existingDatabase = safeFile($sqlitePath);
if (file_exists($sqlitePath) && !$existingDatabase) {
    blocked('DATABASE_PATH_INVALID');
}

try {
    if ($mode === 'install') {
        define('WP_INSTALLING', true);
    }
    require $configPath;
    if (!function_exists('is_blog_installed') || !isset($wpdb) || !$wpdb instanceof wpdb) {
        blocked('WORDPRESS_BOOTSTRAP_FAILED');
    }
    if ($mode === 'install') {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }
    if (!is_blog_installed()) {
        if ($mode !== 'install') {
            blocked('WORDPRESS_NOT_INSTALLED');
        }
        $password = wp_generate_password(64, true, true);
        $install = wp_install(
            'M147 Synthetic Disposable Site',
            (string) $identities['wp_admin_login'],
            (string) $identities['wp_admin_email'],
            false,
            '',
            $password
        );
        $userId = is_array($install) ? ($install['user_id'] ?? null) : null;
        unset($password);
        unset($install);
        if (empty($userId)) {
            blocked('WORDPRESS_INSTALL_FAILED');
        }
        unset($userId);
    }

    if (!is_blog_installed() || !is_file($sqlitePath) || filesize($sqlitePath) < 512) {
        blocked('WORDPRESS_HEALTH_FAILED');
    }
    if (realpath(FQDB) !== realpath($sqlitePath)
        || substr((string) file_get_contents($sqlitePath, false, null, 0, 16), 0, 15) !== "SQLite format 3") {
        blocked('SQLITE_PATH_OR_FORMAT_INVALID');
    }
    $admin = get_user_by('login', (string) $identities['wp_admin_login']);
    if (!$admin || !is_email((string) $admin->user_email) || $admin->user_email !== $identities['wp_admin_email']) {
        blocked('SYNTHETIC_ADMIN_MISSING');
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $plugins = get_plugins();
    $pluginDirectory = $corePath . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'plugins';
    $pluginKey = str_replace(DIRECTORY_SEPARATOR, '/', substr($pluginPath, strlen($pluginDirectory) + 1));
    $connectorDiscovered = isset($plugins[$pluginKey]) && ($plugins[$pluginKey]['Version'] ?? null) === $marker['connector_version'];
    $connectorActive = is_plugin_active($pluginKey);
    if (!$connectorDiscovered || $connectorActive) {
        blocked('CONNECTOR_SCOPE_INVALID');
    }

    $externalResult = apply_filters('pre_http_request', false, [], 'https://example.com/m147-a-egress-sentinel');
    if (!$externalResult instanceof WP_Error || $externalResult->get_error_code() !== 'm147_external_http_blocked') {
        blocked('EXTERNAL_WORDPRESS_HTTP_NOT_BLOCKED');
    }

    $dbCheck = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
    if ((int) $dbCheck < 1) {
        blocked('SQLITE_HEALTH_FAILED');
    }
} catch (Throwable) {
    blocked('WORDPRESS_HEALTH_FAILED');
}

fwrite(STDOUT, json_encode([
    'classification' => 'PASS',
    'target_id' => $marker['target_id'],
    'environment' => 'local-disposable',
    'wordpress_version' => $marker['wordpress_version'],
    'connector_version' => $marker['connector_version'],
    'connector_discovered' => true,
    'connector_active' => false,
    'installed' => true,
    'sqlite_database' => 'fixture-local',
    'sqlite_path_scoped' => true,
    'synthetic_admin_present' => true,
    'external_wordpress_http' => 'blocked',
    'php_network_functions' => 'disabled',
    'php_url_wrappers' => 'disabled',
    'password_output' => false,
    'operation' => $mode,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
