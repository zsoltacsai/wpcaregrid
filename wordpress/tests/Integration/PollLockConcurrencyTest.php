<?php

namespace WPCareGrid\Connector\Tests\Integration;

use WPCareGrid\Connector\Core\Lock;
use WPCareGrid\Connector\Core\Settings;
use WPCareGrid\Connector\Tests\Support\TestCase;

final class PollLockConcurrencyTest extends TestCase
{
    private string $root;

    private string $harness;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireMysqlProcesses();
        $root = realpath((string) getenv('WP_CAREGRID_TEST_ROOT'));
        $this->assertIsString($root);
        $this->root = $root;
        $this->harness = $root.'/poll-lock-process-harness-'.bin2hex(random_bytes(6));
        wp_mkdir_p($this->harness);
        chmod($this->harness, 0700);
        $this->clearLockDirectly();
    }

    protected function tearDown(): void
    {
        $this->clearLockDirectly();
        parent::tearDown();
    }

    public function test_fresh_and_expired_lock_races_have_one_winner_across_processes(): void
    {
        $freshWinners = $this->runLoopRace(1, 2, false);
        $this->assertSame([1], $freshWinners, 'An absent unique option can be inserted by only one contender.');

        $staleWinners = $this->runLoopRace(100, 5, true);
        $this->assertCount(100, $staleWinners);
        $this->assertSame(100, count(array_filter($staleWinners, static fn (int $winners): bool => $winners === 1)), 'Every expired-lock race has exactly one winner: '.wp_json_encode($staleWinners));
        $this->assertSame([], array_values(array_filter($staleWinners, static fn (int $winners): bool => $winners !== 1)), 'No zero-winner or double-owner iteration is allowed.');
    }

    public function test_non_expired_legacy_lock_cannot_be_stolen(): void
    {
        $this->writeLockDirectly(sprintf('%010d', time()));
        $results = $this->runSingleRace(2);

        $this->assertSame([false, false], $results);
    }

    public function test_connector_uninstall_cleanup_does_not_delete_an_unowned_poll_lease(): void
    {
        $lease = sprintf('%010d:%s', time() + Lock::TTL, bin2hex(random_bytes(32)));
        $this->writeLockDirectly($lease);

        Settings::delete_all();

        $this->assertSame($lease, $this->readLockDirectly());
    }

    public function test_expired_owner_cannot_release_or_refresh_successor_lease(): void
    {
        foreach (['release', 'refresh'] as $action) {
            $this->clearLockDirectly();
            $oldOwner = $this->startHoldOwner();
            $oldResult = $oldOwner['config']['result_file'];
            $oldValue = $this->readLockDirectly();
            $this->assertMatchesRegularExpression('/^\d{10}:[a-f0-9]{64}$/D', $oldValue);
            preg_match('/^\d{10}:([a-f0-9]{64})$/D', $oldValue, $matches);
            $this->writeLockDirectly(sprintf('%010d:%s', time() - Lock::TTL - 5, $matches[1]));

            $successorResults = $this->runSingleRace(1);
            $this->assertSame([true], $successorResults, 'Exactly one new owner reclaims the expired lease.');
            $successorValue = $this->readLockDirectly();
            $this->assertNotSame($oldValue, $successorValue);

            file_put_contents($oldOwner['config']['action_file'], wp_json_encode(['action' => $action], JSON_THROW_ON_ERROR), LOCK_EX);
            $this->waitForFiles([$oldResult]);
            $result = json_decode((string) file_get_contents($oldResult), true, 8, JSON_THROW_ON_ERROR);
            $this->assertSame(false, $result[$action === 'release' ? 'released' : 'refreshed'], 'A stale owner must not affect the successor.');
            $this->assertSame($successorValue, $this->readLockDirectly());
            $this->finishWorker($oldOwner['process']);
        }
    }

    public function test_jobrunner_poll_lock_allows_only_one_process_into_websitejob_poll(): void
    {
        $this->pair();
        $uuid = wp_generate_uuid4();
        $job = $this->job('SITE_HEALTH', [], $uuid);
        $startGate = $this->harness.'/poll-start';
        $apiGate = $this->harness.'/api-release';
        $events = $this->harness.'/poll-events.jsonl';
        $workers = [];

        for ($i = 0; $i < 2; $i++) {
            $base = $this->harness.'/poll-'.$i;
            $workers[] = $this->startWorker([
                'mode' => 'poll',
                'ready' => $base.'.ready',
                'start_gate' => $startGate,
                'api_gate' => $apiGate,
                'entered' => $base.'.entered',
                'events' => $events,
                'result_file' => $base.'.result',
                'job' => $job,
            ]);
        }

        try {
            $this->waitForFiles(array_column($workers, 'ready'));
            touch($startGate);
            $this->waitForAnyFiles(array_column(array_column($workers, 'config'), 'entered'), 20, $workers);

            $deadline = microtime(true) + 8;
            while (microtime(true) < $deadline) {
                $entered = count(array_filter($workers, static fn (array $worker): bool => is_file($worker['config']['entered'])));
                $finished = count(array_filter($workers, static fn (array $worker): bool => is_file($worker['config']['result_file'])));
                if ($entered === 2 || $finished >= 1) {
                    break;
                }
                usleep(10000);
            }
        } finally {
            touch($apiGate);
        }

        foreach ($workers as $worker) {
            $this->waitForFiles([$worker['config']['result_file']]);
            $this->finishWorker($worker['process']);
        }

        $eventContents = is_file($events) ? trim((string) file_get_contents($events)) : '';
        $lines = $eventContents === '' ? [] : explode("\n", $eventContents);
        $gets = array_filter(array_map(static fn (string $line): array => json_decode($line, true, 8, JSON_THROW_ON_ERROR), $lines), static fn (array $event): bool => ($event['method'] ?? null) === 'GET');
        $posts = array_filter(array_map(static fn (string $line): array => json_decode($line, true, 8, JSON_THROW_ON_ERROR), $lines), static fn (array $event): bool => ($event['method'] ?? null) === 'POST');
        $this->assertCount(1, $gets, 'Only the lock owner may make the authenticated WebsiteJob poll.');
        $this->assertCount(1, $posts, 'The harmless WebsiteJob result is reported once.');
        $this->assertSame($uuid, array_values($posts)[0]['uuid']);
    }

    public function test_controlled_runner_uses_same_atomic_lock_and_reports_one_harmless_job(): void
    {
        if (! defined('WP_CAREGRID_CONTROLLED_EXECUTION')) {
            define('WP_CAREGRID_CONTROLLED_EXECUTION', true);
            define('WP_CAREGRID_CONTROLLED_SITE_UUID', self::WEBSITE);
        }
        $this->http->on('POST pair', $this->pair_response([
            'synthetic_site' => true,
            'controlled_execution' => true,
            'website_uuid' => self::WEBSITE,
        ]));
        $paired = $this->plugin()->pairing()->pair(self::PAIRING_CODE);
        $this->assertTrue($paired['ok']);
        $uuid = wp_generate_uuid4();
        $job = $this->job('SITE_HEALTH', [], $uuid);
        $startGate = $this->harness.'/controlled-start';
        $apiGate = $this->harness.'/controlled-api-release';
        $events = $this->harness.'/controlled-events.jsonl';
        $workers = [];

        for ($i = 0; $i < 2; $i++) {
            $base = $this->harness.'/controlled-'.$i;
            $workers[] = $this->startWorker([
                'mode' => 'controlled_poll',
                'site_uuid' => self::WEBSITE,
                'ready' => $base.'.ready',
                'start_gate' => $startGate,
                'api_gate' => $apiGate,
                'entered' => $base.'.entered',
                'events' => $events,
                'result_file' => $base.'.result',
                'job' => $job,
            ]);
        }

        try {
            $this->waitForFiles(array_column(array_column($workers, 'config'), 'ready'));
            touch($startGate);
            $this->waitForAnyFiles(array_column(array_column($workers, 'config'), 'entered'), 20, $workers);

            $deadline = microtime(true) + 8;
            while (microtime(true) < $deadline) {
                $entered = count(array_filter($workers, static fn (array $worker): bool => is_file($worker['config']['entered'])));
                $finished = count(array_filter($workers, static fn (array $worker): bool => is_file($worker['config']['result_file'])));
                if ($entered === 2 || $finished >= 1) {
                    break;
                }
                usleep(10000);
            }
        } finally {
            touch($apiGate);
        }

        foreach ($workers as $worker) {
            $this->waitForFiles([$worker['config']['result_file']]);
            $this->finishWorker($worker['process']);
        }

        $lines = is_file($events) ? array_filter(explode("\n", trim((string) file_get_contents($events)))) : [];
        $eventsRead = array_map(static fn (string $line): array => json_decode($line, true, 8, JSON_THROW_ON_ERROR), $lines);
        $gets = array_values(array_filter($eventsRead, static fn (array $event): bool => ($event['method'] ?? null) === 'GET'));
        $posts = array_values(array_filter($eventsRead, static fn (array $event): bool => ($event['method'] ?? null) === 'POST'));
        $this->assertCount(1, $gets, 'Only one controlled runner owns the poll lock and reaches WebsiteJob polling.');
        $this->assertSame('controlled', $gets[0]['mode']);
        $this->assertCount(1, $posts, 'Only the lock owner reports the harmless result.');
        $this->assertSame($uuid, $posts[0]['uuid']);
        foreach ($workers as $worker) {
            $result = json_decode((string) file_get_contents($worker['config']['result_file']), true, 8, JSON_THROW_ON_ERROR);
            $this->assertFalse($result['poll_scheduled'], 'The synthetic site has no automatic WP-Cron poll event.');
        }
    }

    public function test_project_local_runner_refuses_when_paired_site_differs_from_local_binding(): void
    {
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

        Settings::update(['website_uuid' => '00000000-0000-4000-8000-000000000001']);
        $base = $this->harness.'/controlled-cross-site';
        $worker = $this->startWorker([
            'mode' => 'controlled_poll',
            'site_uuid' => self::WEBSITE,
            'expect_refused' => true,
            'ready' => $base.'.ready',
            'start_gate' => $base.'.start',
            'api_gate' => $base.'.api',
            'entered' => $base.'.entered',
            'events' => $base.'.events',
            'result_file' => $base.'.result',
            'job' => $this->job('SITE_HEALTH'),
        ]);
        $this->waitForFiles([$worker['config']['ready']]);
        touch($worker['config']['start_gate']);
        $this->waitForFiles([$worker['config']['result_file']]);
        $this->finishWorker($worker['process']);

        $result = json_decode((string) file_get_contents($worker['config']['result_file']), true, 8, JSON_THROW_ON_ERROR);
        $this->assertTrue($result['refused'] ?? false);
        $this->assertFileDoesNotExist($worker['config']['entered'], 'A mismatched paired site must be refused before polling the API.');
    }

    public function test_killed_controlled_runner_releases_only_by_expiry_then_redelivers_once(): void
    {
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

        $uuid = wp_generate_uuid4();
        $job = $this->job('SITE_HEALTH', [], $uuid);
        $events = $this->harness.'/controlled-kill-events.jsonl';
        $firstStart = $this->harness.'/controlled-kill-first-start';
        $firstApiGate = $this->harness.'/controlled-kill-first-api';
        $firstJobStarted = $this->harness.'/controlled-kill-first-job-started';
        $firstJobGate = $this->harness.'/controlled-kill-first-job-gate';
        $firstBase = $this->harness.'/controlled-kill-first';
        $first = $this->startWorker([
            'mode' => 'controlled_poll', 'site_uuid' => self::WEBSITE,
            'ready' => $firstBase.'.ready', 'start_gate' => $firstStart, 'api_gate' => $firstApiGate,
            'entered' => $firstBase.'.entered', 'events' => $events,
            'result_file' => $firstBase.'.result', 'job' => $job,
            'job_started' => $firstJobStarted, 'job_handler_gate' => $firstJobGate,
        ]);
        $this->waitForFiles([$first['config']['ready']]);
        touch($firstStart);
        $this->waitForFiles([$first['config']['entered']]);
        touch($firstApiGate);
        $this->waitForFiles([$firstJobStarted]);
        $firstPid = proc_get_status($first['process']['handle'])['pid'];
        proc_terminate($first['process']['handle'], 9);
        $deadline = microtime(true) + 10;
        do {
            $firstStatus = proc_get_status($first['process']['handle']);
            if (! $firstStatus['running']) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->assertFalse($firstStatus['running'], 'The isolated OS runner must be killable externally.');
        fclose($first['process']['pipes'][1]);
        fclose($first['process']['pipes'][2]);
        proc_close($first['process']['handle']);

        $expired = $this->readLockDirectly();
        $this->assertMatchesRegularExpression('/^\d{10}:[a-f0-9]{64}$/D', $expired);
        preg_match('/^\d{10}:([a-f0-9]{64})$/D', $expired, $owner);
        $this->writeLockDirectly(sprintf('%010d:%s', time() - Lock::TTL - 1, $owner[1]));

        $secondStart = $this->harness.'/controlled-kill-second-start';
        $secondApiGate = $this->harness.'/controlled-kill-second-api';
        $secondBase = $this->harness.'/controlled-kill-second';
        $second = $this->startWorker([
            'mode' => 'controlled_poll', 'site_uuid' => self::WEBSITE,
            'ready' => $secondBase.'.ready', 'start_gate' => $secondStart, 'api_gate' => $secondApiGate,
            'entered' => $secondBase.'.entered', 'events' => $events,
            'result_file' => $secondBase.'.result', 'job' => $job,
        ]);
        $this->waitForFiles([$second['config']['ready']]);
        touch($secondStart);
        $this->waitForFiles([$second['config']['entered']]);
        touch($secondApiGate);
        $this->waitForFiles([$second['config']['result_file']]);
        $this->finishWorker($second['process']);

        $lines = array_filter(explode("\n", trim((string) file_get_contents($events))));
        $eventsRead = array_map(static fn (string $line): array => json_decode($line, true, 8, JSON_THROW_ON_ERROR), $lines);
        $posts = array_values(array_filter($eventsRead, static fn (array $event): bool => ($event['method'] ?? null) === 'POST'));
        $this->assertCount(1, $posts, 'The terminated process did not report; exactly one redelivered execution reported its result.');
        $this->assertSame($uuid, $posts[0]['uuid']);
        $this->assertNotSame($firstPid, $posts[0]['pid']);
        $this->assertSame('JOB_INTERRUPTED', $posts[0]['result']['error']['code'] ?? null, 'The claimed but interrupted job reports its durable at-most-once execution state.');
    }

    /** @return list<int> Winner count for each iteration. */
    private function runLoopRace(int $iterations, int $workersCount, bool $expired): array
    {
        $run = bin2hex(random_bytes(4));
        $goPrefix = $this->harness.'/'.$run.'-go-';
        $releasePrefix = $this->harness.'/'.$run.'-release-';
        $resultPrefix = $this->harness.'/'.$run.'-result-';
        $donePrefix = $this->harness.'/'.$run.'-done-';
        $workers = [];

        for ($id = 0; $id < $workersCount; $id++) {
            $workers[] = $this->startWorker([
                'mode' => 'loop',
                'worker_id' => $id,
                'iterations' => $iterations,
                'ready' => $this->harness.'/'.$run.'-loop-ready-'.$id,
                'go_prefix' => $goPrefix,
                'release_prefix' => $releasePrefix,
                'result_prefix' => $resultPrefix,
                'done_prefix' => $donePrefix,
            ]);
        }

        $winners = [];
        try {
            $this->waitForFiles(array_column(array_column($workers, 'config'), 'ready'));
            for ($iteration = 0; $iteration < $iterations; $iteration++) {
                if ($expired) {
                    $this->writeLockDirectly(sprintf('%010d', time() - Lock::TTL - 5));
                } else {
                    $this->clearLockDirectly();
                }
                touch($goPrefix.$iteration);
                $resultPaths = [];
                for ($id = 0; $id < $workersCount; $id++) {
                    $resultPaths[] = $resultPrefix.$iteration.'-'.$id.'.json';
                }
                $this->waitForFiles($resultPaths);
                $count = 0;
                foreach ($resultPaths as $path) {
                    $result = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
                    $count += ($result['acquired'] ?? false) ? 1 : 0;
                }
                $winners[] = $count;
                touch($releasePrefix.$iteration);
                $donePaths = [];
                for ($id = 0; $id < $workersCount; $id++) {
                    $donePaths[] = $donePrefix.$iteration.'-'.$id.'.json';
                }
                $this->waitForFiles($donePaths);
            }
        } finally {
            foreach ($workers as $worker) {
                for ($iteration = 0; $iteration < $iterations; $iteration++) {
                    @touch($releasePrefix.$iteration);
                    @touch($goPrefix.$iteration);
                }
            }
            $this->clearLockDirectly();
        }

        foreach ($workers as $worker) {
            $this->finishWorker($worker['process']);
        }

        return $winners;
    }

    /** @return list<bool> */
    private function runSingleRace(int $contenders): array
    {
        $gate = $this->harness.'/single-go-'.bin2hex(random_bytes(4));
        $workers = [];
        for ($i = 0; $i < $contenders; $i++) {
            $base = $this->harness.'/single-'.$i.'-'.bin2hex(random_bytes(3));
            $workers[] = $this->startWorker([
                'mode' => 'single',
                'ready' => $base.'.ready',
                'start_gate' => $gate,
                'result_file' => $base.'.result',
            ]);
        }
        try {
            $this->waitForFiles(array_column(array_column($workers, 'config'), 'ready'));
            touch($gate);
            $paths = array_column(array_column($workers, 'config'), 'result_file');
            $this->waitForFiles($paths);
            $results = [];
            foreach ($paths as $path) {
                $result = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
                $results[] = (bool) ($result['acquired'] ?? false);
            }
        } finally {
            touch($gate);
        }
        foreach ($workers as $worker) {
            $this->finishWorker($worker['process']);
        }

        return $results;
    }

    /** @return array{process: array, config: array} */
    private function startHoldOwner(): array
    {
        $base = $this->harness.'/hold-'.bin2hex(random_bytes(4));
        $worker = $this->startWorker([
            'mode' => 'hold',
            'ready' => $base.'.ready',
            'action_file' => $base.'.action',
            'result_file' => $base.'.result',
        ]);
        $this->waitForFiles([$worker['config']['ready']]);
        $ready = json_decode((string) file_get_contents($worker['config']['ready']), true, 8, JSON_THROW_ON_ERROR);
        $this->assertTrue($ready['acquired'] ?? false);

        return $worker;
    }

    /** @return array{process: array, config: array} */
    private function startWorker(array $config): array
    {
        $configPath = $this->harness.'/config-'.bin2hex(random_bytes(5)).'.json';
        file_put_contents($configPath, wp_json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($configPath, 0600);
        $environment = [
            'APP_ENV' => 'testing',
            'WP_CAREGRID_POLL_LOCK_TEST' => '1',
            'WP_CAREGRID_TEST_ROOT' => $this->root,
            'WP_CAREGRID_TEST_MYSQL' => (string) getenv('WP_CAREGRID_TEST_MYSQL'),
        ];
        if (($config['mode'] ?? null) === 'controlled_poll') {
            $environment['WP_CAREGRID_TEST_CONTROLLED'] = '1';
            $environment['WP_CAREGRID_TEST_CONTROLLED_SITE_UUID'] = (string) ($config['site_uuid'] ?? '');
        }
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/Support/poll-lock-process-worker.php', $configPath],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $environment,
            ['bypass_shell' => true]
        );
        $this->assertIsResource($process, 'Could not start an isolated Connector lock worker.');
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return ['process' => ['handle' => $process, 'pipes' => $pipes], 'config' => $config + ['config_path' => $configPath]];
    }

    private function waitForFiles(array $paths, int $timeout = 20): void
    {
        $deadline = microtime(true) + $timeout;
        do {
            $missing = array_filter($paths, static fn (string $path): bool => ! is_file($path));
            if ($missing === []) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        $diagnostics = [];
        foreach ($missing as $path) {
            $diagnostics[] = $path;
        }
        $this->fail('Timed out waiting for test-process synchronization files: '.implode(', ', $diagnostics));
    }

    private function waitForAnyFiles(array $paths, int $timeout = 20, array $workers = []): void
    {
        $deadline = microtime(true) + $timeout;
        do {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    return;
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        $processDiagnostics = [];
        foreach ($workers as $worker) {
            $handle = $worker['process']['handle'] ?? null;
            if (! is_resource($handle)) {
                continue;
            }
            $status = proc_get_status($handle);
            $stderr = stream_get_contents($worker['process']['pipes'][2]);
            $processDiagnostics[] = [
                'pid' => $status['pid'] ?? null,
                'running' => $status['running'] ?? null,
                'exitcode' => $status['exitcode'] ?? null,
                'stderr' => substr((string) $stderr, 0, 2000),
                'result_file' => $worker['config']['result_file'] ?? null,
            ];
        }

        $this->fail('Timed out waiting for any Connector poll worker to enter the API gate. Processes: '.wp_json_encode($processDiagnostics));
    }

    private function finishWorker(array $process): void
    {
        $handle = $process['handle'];
        $deadline = microtime(true) + 20;
        do {
            $status = proc_get_status($handle);
            if (! $status['running']) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        if ($status['running']) {
            proc_terminate($handle, 15);
            $this->fail('Connector lock worker exceeded its bounded runtime.');
        }
        $stderr = stream_get_contents($process['pipes'][2]);
        fclose($process['pipes'][1]);
        fclose($process['pipes'][2]);
        $closed = proc_close($handle);
        $exit = $closed >= 0 ? $closed : (int) ($status['exitcode'] ?? -1);
        $this->assertSame(0, $exit, 'Connector lock worker failed: '.$stderr);
    }

    private function requireMysqlProcesses(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('proc_open')) {
            $this->markTestSkipped('Poll-lock process tests require POSIX processes and MySQL.');
        }
        if (! preg_match('#^[^:]+:[^@]*@[^/]+/\w+$#', (string) getenv('WP_CAREGRID_TEST_MYSQL'))) {
            $this->markTestSkipped('Poll-lock concurrency tests require a dedicated WP_CAREGRID_TEST_MYSQL database.');
        }
        global $wpdb;
        if ($wpdb->is_mysql !== true) {
            $this->markTestSkipped('Poll-lock concurrency tests require MySQL/MariaDB semantics.');
        }
    }

    private function clearLockDirectly(): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->options, ['option_name' => 'wp_caregrid_poll_lock'], ['%s']);
        $this->clearOptionCache();
    }

    private function writeLockDirectly(string $value): void
    {
        global $wpdb;
        $this->clearLockDirectly();
        $inserted = $wpdb->insert(
            $wpdb->options,
            ['option_name' => 'wp_caregrid_poll_lock', 'option_value' => $value, 'autoload' => 'no'],
            ['%s', '%s', '%s']
        );
        $this->assertSame(1, $inserted);
        $this->clearOptionCache();
    }

    private function readLockDirectly(): string
    {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            'wp_caregrid_poll_lock'
        ));

        return (string) $value;
    }

    private function clearOptionCache(): void
    {
        wp_cache_delete('wp_caregrid_poll_lock', 'options');
        wp_cache_delete('notoptions', 'options');
    }
}
