<?php

namespace WPCareGrid\Connector\Tests\Integration;

use WPCareGrid\Connector\Core\Lock;
use WPCareGrid\Connector\Tests\Support\TestCase;

final class ControlledRunnerEntrypointTest extends TestCase
{
    public function test_project_local_php_entrypoint_bootstraps_wordpress_and_reports_one_harmless_job(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('proc_open')) {
            $this->markTestSkipped('The local controlled runner process test requires POSIX process support.');
        }
        $root = realpath((string) getenv('WP_CAREGRID_TEST_ROOT'));
        $this->assertIsString($root);
        if (! defined('WP_CAREGRID_CONTROLLED_EXECUTION')) {
            define('WP_CAREGRID_CONTROLLED_EXECUTION', true);
            define('WP_CAREGRID_CONTROLLED_SITE_UUID', self::WEBSITE);
        }

        $this->http->on('POST pair', $this->pair_response([
            'synthetic_site' => true,
            'controlled_execution' => true,
            'website_uuid' => self::WEBSITE,
        ]));
        $this->assertTrue($this->plugin()->pairing()->pair(self::PAIRING_CODE)['ok']);

        $events = $root.'/controlled-entrypoint-events.jsonl';
        $configPath = $root.'/controlled-entrypoint-config.json';
        $job = $this->job('SITE_HEALTH');
        file_put_contents($configPath, wp_json_encode(['events' => $events, 'job' => $job], JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($configPath, 0600);

        // The fixture harness keeps WordPress core separate from wp-content.
        // This shim gives the project-local entrypoint a normal root-level wp-load.php.
        $wpLoad = '<?php require '.var_export(__DIR__.'/../wp-tests-config.php', true)."; require ABSPATH.'wp-settings.php';\n";
        file_put_contents($root.'/wp-load.php', $wpLoad, LOCK_EX);
        $muPlugins = $root.'/wp-content/mu-plugins';
        wp_mkdir_p($muPlugins);
        file_put_contents($muPlugins.'/controlled-runner-test.php', $this->httpFixturePlugin(), LOCK_EX);

        try {
            $environment = [
                'APP_ENV' => 'testing',
                'WP_CAREGRID_TEST_ROOT' => $root,
                'WP_CAREGRID_TEST_MYSQL' => (string) getenv('WP_CAREGRID_TEST_MYSQL'),
                'WP_CAREGRID_TEST_CONTROLLED' => '1',
                'WP_CAREGRID_TEST_CONTROLLED_SITE_UUID' => self::WEBSITE,
                'WP_CAREGRID_PROJECT_RUNNER_CONFIG' => $configPath,
            ];
            $runner = $root.'/wp-content/plugins/wp-caregrid-connector/bin/caregrid-controlled-poll.php';
            $process = proc_open(
                [PHP_BINARY, $runner],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root,
                $environment,
                ['bypass_shell' => true],
            );
            $this->assertIsResource($process, 'Project-local controlled runner did not start as a distinct process.');
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $deadline = microtime(true) + 20;
            do {
                $status = proc_get_status($process);
                if (! $status['running']) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            if ($status['running']) {
                proc_terminate($process, 15);
                $this->fail('Project-local controlled runner exceeded its bounded one-poll runtime.');
            }

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            if ($exit < 0) {
                $exit = (int) ($status['exitcode'] ?? -1);
            }
            $this->assertSame(0, $exit, 'Project-local runner failed: '.$stderr);
            $this->assertStringContainsString('jobs executed: 1; results accepted: 1', $stdout);

            $lines = array_filter(explode("\n", trim((string) file_get_contents($events))));
            $eventsRead = array_map(static fn (string $line): array => json_decode($line, true, 16, JSON_THROW_ON_ERROR), $lines);
            $this->assertCount(2, $eventsRead);
            $this->assertSame('GET', $eventsRead[0]['method']);
            $this->assertSame('execution_mode=controlled', $eventsRead[0]['query']);
            $this->assertSame('POST', $eventsRead[1]['method']);
            $this->assertSame($job['uuid'], $eventsRead[1]['uuid']);
            $this->assertSame('success', $eventsRead[1]['body']['status'] ?? null);
            $this->assertNull(Lock::inspect(), 'The bounded poll releases its atomic lock on clean exit.');
        } finally {
            // This WordPress test root is shared by the suite. Do not leave a
            // test HTTP interceptor loaded by later separate-process tests.
            @unlink($muPlugins.'/controlled-runner-test.php');
            @rmdir($muPlugins);
            @unlink($root.'/wp-load.php');
        }
    }

    private function httpFixturePlugin(): string
    {
        return <<<'PHP'
<?php
add_filter('pre_http_request', static function ($pre, array $args, string $url) {
    $configPath = (string) getenv('WP_CAREGRID_PROJECT_RUNNER_CONFIG');
    $config = json_decode((string) file_get_contents($configPath), true, 16, JSON_THROW_ON_ERROR);
    $root = realpath((string) getenv('WP_CAREGRID_TEST_ROOT'));
    if (! $root || ! str_starts_with(realpath($configPath), $root.DIRECTORY_SEPARATOR)
        || strtolower((string) parse_url($url, PHP_URL_HOST)) !== 'app.caregrid.test') {
        return new WP_Error('controlled_runner_test_denied', 'Unexpected runner test request.');
    }
    $method = strtoupper((string) ($args['method'] ?? 'GET'));
    $path = (string) parse_url($url, PHP_URL_PATH);
    $query = (string) parse_url($url, PHP_URL_QUERY);
    $append = static function (array $event) use ($config): void {
        file_put_contents($config['events'], wp_json_encode($event)."\n", FILE_APPEND | LOCK_EX);
    };
    $response = static fn (int $status, array $body): array => [
        'headers' => ['content-type' => 'application/json'],
        'body' => wp_json_encode($body),
        'response' => ['code' => $status, 'message' => 'test'],
        'cookies' => [],
        'filename' => null,
    ];
    if ($method === 'GET' && str_ends_with($path, '/jobs') && $query === 'execution_mode=controlled') {
        $append(['method' => 'GET', 'query' => $query]);

        return $response(200, [
            'jobs' => [$config['job']],
            'poll_interval' => 300,
            'website_uuid' => getenv('WP_CAREGRID_TEST_CONTROLLED_SITE_UUID'),
            'synthetic_site' => true,
            'controlled_execution' => true,
        ]);
    }
    if ($method === 'POST' && preg_match('#/jobs/([0-9a-f-]{36})/result$#i', $path, $match)) {
        $append(['method' => 'POST', 'uuid' => $match[1], 'body' => json_decode((string) ($args['body'] ?? '{}'), true)]);

        return $response(202, ['accepted' => true]);
    }

    return new WP_Error('controlled_runner_test_denied', 'Unexpected runner API operation.');
}, 10, 3);
PHP;
    }
}
