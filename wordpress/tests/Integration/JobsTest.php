<?php

namespace WPCareGrid\Connector\Tests\Integration;

use WPCareGrid\Connector\Core\Settings;
use WPCareGrid\Connector\Jobs\JobRegistry;
use WPCareGrid\Connector\Jobs\JobResult;
use WPCareGrid\Connector\Jobs\JobRunner;
use WPCareGrid\Connector\Jobs\JobStore;
use WPCareGrid\Connector\Tests\Support\CountingHandler;
use WPCareGrid\Connector\Tests\Support\FakeHttp;
use WPCareGrid\Connector\Tests\Support\TestCase;

final class JobsTest extends TestCase
{
    private CountingHandler $counter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pair();

        // SITE_HEALTH is replaced by a counter so executions can be counted.
        $this->counter = new CountingHandler('health');
        $this->use_handlers(['SITE_HEALTH' => $this->counter, 'GET_INVENTORY' => $this->plugin()->registry()->handler('GET_INVENTORY')]);
    }

    public function test_polling_runs_jobs_and_posts_results(): void
    {
        $job = $this->job('SITE_HEALTH');
        $this->serve_jobs($job);

        $summary = $this->plugin()->runner()->poll();

        $this->assertSame(['executed' => 1, 'delivered' => 1], $summary);
        $this->assertSame(1, $this->counter->runs);

        [$poll] = $this->http->api_requests('GET jobs');
        $this->assertSame('Bearer '.self::CREDENTIAL, $poll['headers']['Authorization']);
        $this->assertSame(self::WEBSITE, $poll['headers']['X-CareGrid-Website']);

        $result = $this->posted_result($job['uuid']);
        $this->assertSame(['status', 'result_code', 'data', 'error', 'started_at', 'completed_at'], array_keys($result));
        $this->assertSame('success', $result['status']);
        $this->assertSame('COUNTED', $result['result_code']);
        $this->assertNotNull(Settings::get('last_poll_at'));
        $this->assertTrue($this->plugin()->jobs()->find($job['uuid'])['delivered']);
    }

    public function test_real_site_health_job(): void
    {
        $this->plugin()->reset();
        $this->pair();
        $job = $this->job('SITE_HEALTH');
        $this->serve_jobs($job);

        $this->plugin()->runner()->poll();

        $result = $this->posted_result($job['uuid']);
        $this->assertSame('HEALTH_CHECKED', $result['result_code']);
        $health = $result['data']['health'];
        $this->assertContains($health['status'], ['healthy', 'attention'], 'A working site is never critical or unknown.');
        $this->assertSame([], array_filter($health['findings'], fn ($finding) => $finding['status'] === 'fail'));
        $ids = array_column($health['findings'], 'id');
        foreach (['homepage', 'rest_api', 'active_theme', 'plugin_loading', 'site_health', 'php_version', 'debug_display'] as $id) {
            $this->assertContains($id, $ids);
        }
    }

    public function test_site_health_reports_critical_when_the_home_page_fails(): void
    {
        $this->http->site_responds(500);

        $report = $this->plugin()->health()->run();

        $this->assertSame('critical', $report['status']);
        $homepage = array_column($report['findings'], null, 'id')['homepage'];
        $this->assertSame('fail', $homepage['status']);
    }

    public function test_unsupported_job_types_are_refused(): void
    {
        $jobs = [];
        foreach (['RUN_PHP', 'RUN_COMMAND', 'EXECUTE_CODE', 'EVAL', 'do_action', 'BACKUP_FULL', 'site_health', ''] as $type) {
            $jobs[] = $this->job($type);
        }
        $this->serve_jobs(...$jobs);

        $summary = $this->plugin()->runner()->poll();

        $this->assertSame(0, $summary['executed']);
        $this->assertSame(0, $this->counter->runs);
        foreach ($jobs as $job) {
            $result = $this->posted_result($job['uuid']);
            $this->assertSame('failed', $result['status']);
            $this->assertSame('UNSUPPORTED_JOB_TYPE', $result['result_code']);
        }
    }

    public function test_the_whitelist_cannot_be_extended(): void
    {
        // Even a handler wired in under another name is not run.
        $rogue = new CountingHandler('health');
        $this->use_handlers(['RUN_PHP' => $rogue]);
        $this->serve_jobs($this->job('RUN_PHP'));

        $this->plugin()->runner()->poll();

        $this->assertSame(0, $rogue->runs);
        $this->assertSame(['GET_INVENTORY', 'SITE_HEALTH', 'LICENSE_CHECK', 'UPDATE_PLUGIN', 'UPDATE_THEME', 'UPDATE_CORE', 'BACKUP_FILES', 'BACKUP_DATABASE', 'BACKUP_FULL', 'RESTORE'], JobRegistry::TYPES);
    }

    public function test_invalid_payloads_are_refused_before_anything_runs(): void
    {
        $this->plugin()->reset();
        $this->pair();
        $file = $this->install_plugin('caregrid-sample', '1.0.0');
        $this->offer_plugin_update($file, '1.1.0', $this->plugin_package('caregrid-sample', '1.1.0'));

        $payloads = [
            ['UPDATE_PLUGIN', ['plugin' => '../../wp-config.php']],
            ['UPDATE_PLUGIN', ['plugin' => '/etc/passwd']],
            ['UPDATE_PLUGIN', ['plugin' => 'https://evil.example/shell.zip']],
            ['UPDATE_PLUGIN', ['plugin' => $file, 'package' => 'https://evil.example/shell.zip']],
            ['UPDATE_PLUGIN', ['plugin' => $file, 'target_version' => '1.1; rm -rf /']],
            ['UPDATE_PLUGIN', ['plugin' => ['nested' => 'array']]],
            ['UPDATE_PLUGIN', []],
            ['UPDATE_THEME', ['stylesheet' => '../plugins']],
            ['UPDATE_CORE', ['policy' => 'anything']],
            ['GET_INVENTORY', ['hook' => 'shutdown']],
        ];
        $jobs = array_map(fn ($item) => $this->job($item[0], $item[1]), $payloads);
        $this->serve_jobs(...$jobs);

        $summary = $this->plugin()->runner()->poll();

        $this->assertSame(0, $summary['executed']);
        foreach ($jobs as $job) {
            $this->assertSame('INVALID_PAYLOAD', $this->posted_result($job['uuid'])['result_code'], $job['type'].' '.wp_json_encode($job['payload']));
        }
        $this->assertSame('1.0.0', get_plugins()[$file]['Version'], 'Nothing was updated.');
    }

    public function test_the_same_job_is_never_executed_twice(): void
    {
        $job = $this->job('SITE_HEALTH');
        // Delivered twice in one poll, then again in the next poll.
        $this->http->on('GET jobs', ['status' => 200, 'body' => ['jobs' => [$job, $job]]], ['status' => 200, 'body' => ['jobs' => [$job]]]);
        $this->http->on('POST jobs/*/result', ['status' => 202, 'body' => []]);

        $this->plugin()->runner()->poll();
        $this->plugin()->runner()->poll();

        $this->assertSame(1, $this->counter->runs);
        // Once per poll: the repeat in the second poll re-sends the stored result.
        $this->assertCount(2, $this->http->api_requests('POST jobs/*/result'));
        foreach ($this->http->api_requests('POST jobs/*/result') as $request) {
            $this->assertSame(1, $request['body']['data']['runs']);
        }
    }

    public function test_a_job_interrupted_mid_run_is_not_repeated(): void
    {
        $job = $this->job('SITE_HEALTH');
        // The previous process recorded the start, then died.
        $this->plugin()->jobs()->begin($job['uuid'], 'SITE_HEALTH');
        $this->serve_jobs($job);

        $this->plugin()->runner()->poll();

        $this->assertSame(0, $this->counter->runs);
        $result = $this->posted_result($job['uuid']);
        $this->assertSame('failed', $result['status']);
        $this->assertSame('JOB_INTERRUPTED', $result['result_code']);
    }

    public function test_an_exception_becomes_a_structured_failure_without_details(): void
    {
        $throwing = new CountingHandler('health', new \RuntimeException('DB_PASSWORD=hunter2 in /var/www/wp-config.php'));
        $this->use_handlers(['SITE_HEALTH' => $throwing]);
        $job = $this->job('SITE_HEALTH');
        $this->serve_jobs($job);

        $this->plugin()->runner()->poll();

        $result = $this->posted_result($job['uuid']);
        $this->assertSame('failed', $result['status']);
        $this->assertSame('JOB_EXCEPTION', $result['result_code']);
        $sent = wp_json_encode($result);
        $this->assertStringNotContainsString('hunter2', $sent);
        $this->assertStringNotContainsString('wp-config', $sent);
        $this->assertStringNotContainsString('#0 ', $sent, 'No stack trace.');
    }

    public function test_results_that_cannot_be_delivered_are_retried_without_rerunning_the_job(): void
    {
        $job = $this->job('SITE_HEALTH');
        $this->http->on('GET jobs', ['status' => 200, 'body' => ['jobs' => [$job]]], ['status' => 200, 'body' => ['jobs' => []]]);
        $this->http->on(
            'POST jobs/*/result',
            new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out'),
            new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out'),
            ['status' => 503, 'body' => []],
            ['status' => 202, 'body' => []],
        );

        $this->plugin()->runner()->poll(); // Runs the job; both delivery attempts time out.
        $this->assertFalse($this->plugin()->jobs()->find($job['uuid'])['delivered']);

        $this->plugin()->runner()->poll(); // 503, kept.
        $this->assertFalse($this->plugin()->jobs()->find($job['uuid'])['delivered']);

        $this->plugin()->runner()->poll(); // Accepted.
        $this->assertTrue($this->plugin()->jobs()->find($job['uuid'])['delivered']);

        $this->assertSame(1, $this->counter->runs);
        $this->assertCount(4, $this->http->api_requests('POST jobs/*/result'));
    }

    public function test_a_result_the_server_rejects_for_good_is_not_retried_forever(): void
    {
        $job = $this->job('SITE_HEALTH');
        $this->serve_jobs($job);
        $this->http->on('POST jobs/*/result', FakeHttp::error(409, 'JOB_NOT_DELIVERED'));

        $this->plugin()->runner()->poll();
        $this->plugin()->runner()->poll();

        $this->assertCount(1, $this->http->api_requests('POST jobs/*/result'));
        $this->assertSame([], $this->plugin()->jobs()->undelivered());
    }

    public function test_api_failure_while_polling_changes_nothing(): void
    {
        $this->http->on('GET jobs', ['status' => 500, 'body' => []]);

        $this->assertSame(['executed' => 0, 'delivered' => 0], $this->plugin()->runner()->poll());

        $this->assertTrue(Settings::is_connected());
        $this->assertSame([], $this->plugin()->jobs()->all());
        $this->assertFalse(get_option('wp_caregrid_poll_lock'), 'The lock is released.');
    }

    public function test_network_timeout_while_polling(): void
    {
        $this->http->on('GET jobs', new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out'));

        $this->assertSame(['executed' => 0, 'delivered' => 0], $this->plugin()->runner()->poll());

        $this->assertTrue(Settings::is_connected());
        $this->assertSame('NETWORK_ERROR', Settings::get('last_error'));
    }

    public function test_revocation_during_polling_stops_everything(): void
    {
        $this->http->on('GET jobs', FakeHttp::error(401, 'CONNECTOR_REVOKED'));

        $this->plugin()->runner()->poll();

        $this->assertSame(Settings::REVOKED, Settings::get('status'));
        $this->assertFalse($this->plugin()->credentials()->has());
    }

    public function test_concurrent_polls_do_not_run_jobs_in_parallel(): void
    {
        add_option('wp_caregrid_poll_lock', time(), '', false);
        $this->serve_jobs($this->job('SITE_HEALTH'));

        $this->assertSame(['executed' => 0, 'delivered' => 0], $this->plugin()->runner()->poll());
        $this->assertSame([], $this->http->requests);

        // A stale lock from a crashed process is taken over.
        update_option('wp_caregrid_poll_lock', time() - 3600, false);
        $this->assertSame(1, $this->plugin()->runner()->poll()['executed']);
    }

    public function test_malformed_jobs_are_ignored(): void
    {
        $this->http->on('GET jobs', ['status' => 200, 'body' => ['jobs' => [['uuid' => 'not-a-uuid', 'type' => 'SITE_HEALTH'], 'string', ['type' => 'SITE_HEALTH']]]]);

        $this->assertSame(0, $this->plugin()->runner()->poll()['executed']);
        $this->assertSame(0, $this->counter->runs);
        $this->assertSame([], $this->http->api_requests('POST jobs/*/result'));
    }

    public function test_local_job_history_is_bounded_but_keeps_undelivered_results(): void
    {
        $store = new JobStore;
        $pending = wp_generate_uuid4();
        $store->begin($pending, 'SITE_HEALTH');
        $store->complete($pending, JobResult::success('X'));

        for ($i = 0; $i < 320; $i++) {
            $uuid = wp_generate_uuid4();
            $store->begin($uuid, 'SITE_HEALTH');
            $store->complete($uuid, JobResult::success('X'));
            $store->mark_delivered($uuid);
        }

        $this->assertLessThanOrEqual(301, count($store->all()));
        $this->assertNotNull($store->find($pending));
    }

    private function use_handlers(array $handlers): void
    {
        $this->plugin()->set('registry', new JobRegistry($handlers));
        $this->plugin()->set('runner', new JobRunner($this->plugin()->api(), $this->plugin()->registry(), $this->plugin()->jobs(), $this->plugin()->connection()));
    }
}
