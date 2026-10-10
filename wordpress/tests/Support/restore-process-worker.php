<?php

declare(strict_types=1);

use WPCareGrid\Connector\Backups\WordPressBackupIndexLookup;
use WPCareGrid\Connector\Core\Plugin;
use WPCareGrid\Connector\Health\HealthChecker;
use WPCareGrid\Connector\Jobs\Handlers\RestoreHandler;
use WPCareGrid\Connector\Jobs\JobRegistry;
use WPCareGrid\Connector\Jobs\JobRunner;
use WPCareGrid\Connector\Tests\Support\ProcessRestoreBarrier;

$configPath = $argv[1] ?? '';
$root = realpath((string) getenv('WP_CAREGRID_TEST_ROOT'));
$temp = realpath(sys_get_temp_dir());
$tokenPath = is_string($root) ? $root.'/.wp-caregrid-test-owned' : '';
$token = is_file($tokenPath) ? trim((string) file_get_contents($tokenPath)) : '';
$rootNameMatches = is_string($root) && preg_match('/^wp-caregrid-tests-[0-9]+-([a-f0-9]{16})$/D', basename($root), $rootMatch) === 1;
if (getenv('APP_ENV') !== 'testing'
    || getenv('WP_CAREGRID_RESTORE_HARNESS') !== '1'
    || ! is_string($root)
    || ! is_string($temp)
    || ! str_starts_with($root, rtrim($temp, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
    || ! preg_match('/^wp-caregrid-tests-[0-9]+-[a-f0-9]{16}$/D', basename($root))
    || ! preg_match('/^[a-f0-9]{16}$/D', $token)
    || ! $rootNameMatches
    || ! hash_equals((string) ($rootMatch[1] ?? ''), $token)
    || ! is_file($configPath)
    || is_link($configPath)) {
    fwrite(STDERR, "Refusing to start outside an owned disposable WordPress test site.\n");
    exit(2);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
putenv('WP_CAREGRID_TEST_ROOT='.$root);
require dirname(__DIR__).'/wp-tests-config.php';
require ABSPATH.'wp-settings.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
require_once ABSPATH.'wp-admin/includes/admin.php';

if (get_option('wp_caregrid_test_fixture') !== 'synthetic' || ! is_plugin_active('wp-caregrid-connector/wp-caregrid-connector.php')) {
    fwrite(STDERR, "Refusing to start without the synthetic Connector fixture.\n");
    exit(2);
}

$config = json_decode((string) file_get_contents($configPath), true, 32, JSON_THROW_ON_ERROR);
$barrierDir = (string) ($config['barrier_dir'] ?? '');
$target = (string) ($config['barrier'] ?? '');
$jobs = (array) ($config['jobs'] ?? []);
$artifacts = (array) ($config['artifacts'] ?? []);
$resultFile = (string) ($config['result_file'] ?? '');
$stateFile = (string) ($config['state_file'] ?? '');
$realBarrier = realpath($barrierDir);
if (! is_string($realBarrier) || ! str_starts_with($realBarrier.DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR)
    || ! str_starts_with($resultFile, $root.DIRECTORY_SEPARATOR)
    || ! str_starts_with($stateFile, $root.DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Refusing worker paths outside the disposable fixture.\n");
    exit(2);
}

foreach ($artifacts as $name => $artifact) {
    $realArtifact = is_string($artifact['path'] ?? null) ? realpath($artifact['path']) : false;
    if (! in_array($name, ['database', 'files'], true)
        || ! is_string($realArtifact)
        || ! str_starts_with($realArtifact.DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR)
        || ! is_file($realArtifact)
        || filesize($realArtifact) !== (int) ($artifact['size'] ?? -1)
        || ! hash_equals((string) ($artifact['sha256'] ?? ''), (string) hash_file('sha256', $realArtifact))) {
        fwrite(STDERR, "Refusing an unverified test artifact.\n");
        exit(2);
    }
    $artifacts[$name]['path'] = $realArtifact;
}

add_filter('pre_http_request', static function ($pre, array $args, string $url) use ($jobs, $artifacts, $resultFile) {
    if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== 'app.caregrid.test') {
        return new WP_Error('m8_test_http_denied', 'Only the synthetic test API host is allowed.');
    }

    $method = strtoupper((string) ($args['method'] ?? 'GET'));
    $path = (string) parse_url($url, PHP_URL_PATH);
    $response = static fn (int $code, string $body, array $headers = []): array => [
        'headers' => array_merge(['content-type' => 'application/json'], $headers),
        'body' => $body,
        'response' => ['code' => $code, 'message' => 'test'],
        'cookies' => [],
        'filename' => null,
    ];

    if ($method === 'GET' && str_ends_with($path, '/jobs')) {
        return $response(200, wp_json_encode(['jobs' => $jobs, 'poll_interval' => 300]));
    }

    if ($method === 'GET' && preg_match('#/backups/[0-9a-f-]{36}/objects/(database|files)/content$#', $path, $match)) {
        $name = $match[1];
        $artifact = $artifacts[$name] ?? null;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $offset = (int) ($query['offset'] ?? -1);
        $length = (int) ($query['length'] ?? 0);
        if (! is_array($artifact) || $offset < 0 || $length < 1 || $offset >= (int) $artifact['size']) {
            return $response(404, wp_json_encode(['error' => ['code' => 'NOT_FOUND', 'message' => 'Not found.']]));
        }
        $handle = fopen($artifact['path'], 'rb');
        fseek($handle, $offset);
        $bytes = (string) fread($handle, min($length, (int) $artifact['size'] - $offset));
        fclose($handle);

        return $response(200, $bytes, ['content-type' => 'application/octet-stream', 'x-chunk-sha256' => hash('sha256', $bytes)]);
    }

    if ($method === 'POST' && preg_match('#/jobs/([0-9a-f-]{36})/result$#', $path, $match)) {
        $body = json_decode((string) ($args['body'] ?? ''), true);
        file_put_contents($resultFile, wp_json_encode(['job_uuid' => $match[1], 'result' => $body]), LOCK_EX);

        return $response(202, wp_json_encode(['accepted' => true]));
    }

    return $response(404, wp_json_encode(['error' => ['code' => 'NOT_FOUND', 'message' => 'Not found.']]));
}, 10, 3);

$plugin = Plugin::instance();
$plugin->reset();
$downloader = $plugin->downloader();
$hooks = $target === '' ? null : new ProcessRestoreBarrier($barrierDir, $target);
$handler = new RestoreHandler($downloader, new HealthChecker, $plugin->jobs(), new WordPressBackupIndexLookup, $hooks);
$registry = new JobRegistry(['RESTORE' => $handler]);
$runner = new JobRunner($plugin->api(), $registry, $plugin->jobs(), $plugin->connection());
$plugin->set('registry', $registry);
$plugin->set('runner', $runner);
$runner->poll();
file_put_contents($stateFile, wp_json_encode([
    'poll_lock_after' => get_option('wp_caregrid_poll_lock', false),
    'job' => $plugin->jobs()->find((string) ($jobs[0]['uuid'] ?? '')),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
