<?php

declare(strict_types=1);

use WPCareGrid\Connector\Core\Lock;
use WPCareGrid\Connector\Core\Plugin;
use WPCareGrid\Connector\Core\Scheduler;
use WPCareGrid\Connector\Jobs\JobRunner;

$configPath = $argv[1] ?? '';
$root = realpath((string) getenv('WP_CAREGRID_TEST_ROOT'));
$temp = realpath(sys_get_temp_dir());
$marker = is_string($root) ? $root.'/.wp-caregrid-test-owned' : '';
$token = is_file($marker) ? trim((string) file_get_contents($marker)) : '';
$rootMatches = is_string($root) && preg_match('/^wp-caregrid-tests-[0-9]+-([a-f0-9]{16})$/D', basename($root), $matches) === 1;

if (getenv('APP_ENV') !== 'testing'
    || getenv('WP_CAREGRID_POLL_LOCK_TEST') !== '1'
    || ! is_string($root)
    || ! is_string($temp)
    || ! str_starts_with($root, rtrim($temp, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
    || ! $rootMatches
    || ! preg_match('/^[a-f0-9]{16}$/D', $token)
    || ! hash_equals((string) ($matches[1] ?? ''), $token)
    || ! is_file($configPath)
    || is_link($configPath)) {
    fwrite(STDERR, "Refusing to start outside an owned disposable WordPress test site.\n");
    exit(2);
}

$configReal = realpath($configPath);
if (! is_string($configReal) || ! str_starts_with($configReal, $root.DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Refusing worker configuration outside the disposable fixture.\n");
    exit(2);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
putenv('WP_CAREGRID_TEST_ROOT='.$root);
require dirname(__DIR__).'/wp-tests-config.php';
require ABSPATH.'wp-settings.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';

if (! isset($wpdb) || $wpdb->is_mysql !== true) {
    fwrite(STDERR, "Poll-lock process tests require the dedicated MySQL test database.\n");
    exit(2);
}

$config = json_decode((string) file_get_contents($configReal), true, 32, JSON_THROW_ON_ERROR);
$mode = (string) ($config['mode'] ?? '');

$insideRoot = static function (string $path) use ($root): bool {
    return str_starts_with($path, $root.DIRECTORY_SEPARATOR);
};
$waitFor = static function (string $path, int $timeout = 20) use ($insideRoot): bool {
    if (! $insideRoot($path)) {
        return false;
    }
    $deadline = microtime(true) + $timeout;
    while (! is_file($path) && microtime(true) < $deadline) {
        usleep(1000);
    }

    return is_file($path);
};
$write = static function (string $path, array $data) use ($insideRoot): void {
    if (! $insideRoot($path)) {
        throw new RuntimeException('Invalid test result path.');
    }
    file_put_contents($path, wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
};

if ($mode === 'loop') {
    $iterations = (int) ($config['iterations'] ?? 0);
    $id = (int) ($config['worker_id'] ?? -1);
    $ready = (string) ($config['ready'] ?? '');
    if ($iterations < 1 || $iterations > 200 || $id < 0 || ! $insideRoot($ready)) {
        exit(2);
    }
    touch($ready);
    for ($i = 0; $i < $iterations; $i++) {
        $go = (string) ($config['go_prefix'] ?? '').$i;
        $release = (string) ($config['release_prefix'] ?? '').$i;
        $result = (string) ($config['result_prefix'] ?? '').$i.'-'.$id.'.json';
        if (! $waitFor($go)) {
            exit(3);
        }
        $acquired = Lock::acquire();
        $write($result, ['worker' => $id, 'iteration' => $i, 'acquired' => $acquired]);
        if (! $waitFor($release)) {
            exit(4);
        }
        $write((string) ($config['done_prefix'] ?? '').$i.'-'.$id.'.json', ['released' => Lock::release()]);
    }
    exit(0);
}

if ($mode === 'single') {
    $ready = (string) ($config['ready'] ?? '');
    $start = (string) ($config['start_gate'] ?? '');
    $result = (string) ($config['result_file'] ?? '');
    if (! $insideRoot($ready) || ! $insideRoot($start) || ! $insideRoot($result)) {
        exit(2);
    }
    touch($ready);
    if (! $waitFor($start)) {
        exit(3);
    }
    $acquired = Lock::acquire();
    $write($result, ['acquired' => $acquired]);
    exit(0);
}

if ($mode === 'hold') {
    $ready = (string) ($config['ready'] ?? '');
    $actionFile = (string) ($config['action_file'] ?? '');
    $resultFile = (string) ($config['result_file'] ?? '');
    if (! $insideRoot($ready) || ! $insideRoot($actionFile) || ! $insideRoot($resultFile)) {
        exit(2);
    }
    $acquired = Lock::acquire();
    $write($ready, ['acquired' => $acquired]);
    if (! $acquired) {
        exit(0);
    }
    if (! $waitFor($actionFile)) {
        exit(5);
    }
    $action = json_decode((string) file_get_contents($actionFile), true, 8, JSON_THROW_ON_ERROR);
    if (($action['action'] ?? '') === 'refresh') {
        $property = new ReflectionProperty(Lock::class, 'refreshedAt');
        $property->setValue(null, time() - 61);
        $result = ['refreshed' => Lock::refresh()];
    } elseif (($action['action'] ?? '') === 'release') {
        $result = ['released' => Lock::release()];
    } else {
        exit(6);
    }
    $write($resultFile, $result);
    exit(0);
}

if ($mode === 'poll') {
    $ready = (string) ($config['ready'] ?? '');
    $apiGate = (string) ($config['api_gate'] ?? '');
    $entered = (string) ($config['entered'] ?? '');
    $events = (string) ($config['events'] ?? '');
    $job = (array) ($config['job'] ?? []);
    if (! $insideRoot($ready) || ! $insideRoot($apiGate) || ! $insideRoot($entered) || ! $insideRoot($events)) {
        exit(2);
    }
    add_filter('pre_http_request', static function ($pre, array $args, string $url) use ($apiGate, $entered, $events, $job, $waitFor) {
        if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== 'app.caregrid.test') {
            return new WP_Error('poll_lock_test_denied', 'Unexpected test API host.');
        }
        $method = strtoupper((string) ($args['method'] ?? 'GET'));
        $path = (string) parse_url($url, PHP_URL_PATH);
        $response = static fn (int $code, array $body): array => [
            'headers' => ['content-type' => 'application/json'],
            'body' => wp_json_encode($body),
            'response' => ['code' => $code, 'message' => 'test'],
            'cookies' => [],
            'filename' => null,
        ];
        if ($method === 'GET' && str_ends_with($path, '/jobs')) {
            touch($entered);
            file_put_contents($events, wp_json_encode(['method' => 'GET', 'path' => 'jobs', 'pid' => getmypid()])."\n", FILE_APPEND | LOCK_EX);
            if (! $waitFor($apiGate)) {
                return new WP_Error('poll_lock_test_timeout', 'Test gate timed out.');
            }

            return $response(200, ['jobs' => [$job], 'poll_interval' => 300]);
        }
        if ($method === 'POST' && preg_match('#/jobs/([0-9a-f-]{36})/result$#i', $path, $match)) {
            file_put_contents($events, wp_json_encode(['method' => 'POST', 'path' => 'result', 'uuid' => $match[1], 'pid' => getmypid()])."\n", FILE_APPEND | LOCK_EX);

            return $response(202, ['accepted' => true]);
        }

        return $response(200, []);
    }, 10, 3);

    touch($ready);
    if (! $waitFor((string) ($config['start_gate'] ?? ''))) {
        exit(3);
    }
    $plugin = Plugin::instance();
    $plugin->reset();
    $runner = new JobRunner($plugin->api(), $plugin->registry(), $plugin->jobs(), $plugin->connection());
    $summary = $runner->poll();
    $write((string) ($config['result_file'] ?? ''), ['summary' => $summary, 'pid' => getmypid()]);
    exit(0);
}

if ($mode === 'controlled_poll') {
    $ready = (string) ($config['ready'] ?? '');
    $start = (string) ($config['start_gate'] ?? '');
    $apiGate = (string) ($config['api_gate'] ?? '');
    $entered = (string) ($config['entered'] ?? '');
    $events = (string) ($config['events'] ?? '');
    $resultFile = (string) ($config['result_file'] ?? '');
    $siteUuid = strtolower((string) ($config['site_uuid'] ?? ''));
    $job = (array) ($config['job'] ?? []);
    if (! $insideRoot($ready) || ! $insideRoot($start) || ! $insideRoot($apiGate)
        || ! $insideRoot($entered) || ! $insideRoot($events) || ! $insideRoot($resultFile)
        || getenv('WP_CAREGRID_TEST_CONTROLLED') !== '1'
        || strtolower((string) getenv('WP_CAREGRID_TEST_CONTROLLED_SITE_UUID')) !== $siteUuid) {
        exit(2);
    }

    add_filter('pre_http_request', static function ($pre, array $args, string $url) use ($apiGate, $entered, $events, $job, $siteUuid, $waitFor) {
        if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== 'app.caregrid.test') {
            return new WP_Error('controlled_poll_test_denied', 'Unexpected test API host.');
        }
        $method = strtoupper((string) ($args['method'] ?? 'GET'));
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = (string) parse_url($url, PHP_URL_QUERY);
        $response = static fn (int $code, array $body): array => [
            'headers' => ['content-type' => 'application/json'],
            'body' => wp_json_encode($body),
            'response' => ['code' => $code, 'message' => 'test'],
            'cookies' => [],
            'filename' => null,
        ];
        if ($method === 'GET' && str_ends_with($path, '/jobs')) {
            if ($query !== 'execution_mode=controlled') {
                return new WP_Error('controlled_poll_test_denied', 'Controlled runner did not use the bounded execution mode.');
            }
            touch($entered);
            file_put_contents($events, wp_json_encode(['method' => 'GET', 'mode' => 'controlled', 'pid' => getmypid()])."\n", FILE_APPEND | LOCK_EX);
            if (! $waitFor($apiGate)) {
                return new WP_Error('controlled_poll_test_timeout', 'Test gate timed out.');
            }

            return $response(200, ['jobs' => [$job], 'poll_interval' => 300, 'website_uuid' => $siteUuid, 'synthetic_site' => true, 'controlled_execution' => true]);
        }
        if ($method === 'POST' && preg_match('#/jobs/([0-9a-f-]{36})/result$#i', $path, $match)) {
            file_put_contents($events, wp_json_encode(['method' => 'POST', 'mode' => 'controlled', 'uuid' => $match[1], 'pid' => getmypid()])."\n", FILE_APPEND | LOCK_EX);

            return $response(202, ['accepted' => true]);
        }

        return $response(200, []);
    }, 10, 3);

    touch($ready);
    if (! $waitFor($start)) {
        exit(3);
    }
    $plugin = Plugin::instance();
    $plugin->reset();
    $runner = new JobRunner($plugin->api(), $plugin->registry(), $plugin->jobs(), $plugin->connection());
    $summary = $runner->poll(controlledExecution: true);
    $write($resultFile, ['summary' => $summary, 'pid' => getmypid(), 'poll_scheduled' => wp_next_scheduled(Scheduler::POLL) !== false]);
    exit(0);
}

fwrite(STDERR, "Unknown test worker mode.\n");
exit(2);
