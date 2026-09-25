<?php

namespace Tests\Feature\Connector;

use App\Connector\ConnectorError;
use App\Connector\JobQueue;
use App\Connector\JobStatus;
use App\Connector\JobType;
use App\Models\WebsiteJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\PairsConnectors;
use Tests\TestCase;

class JobsTest extends TestCase
{
    use PairsConnectors, RefreshDatabase;

    private function queue($website, JobType $type, array $payload = []): WebsiteJob
    {
        return app(JobQueue::class)->queue($website->load('activeConnector'), $type, $payload);
    }

    public function test_only_whitelisted_job_types_exist(): void
    {
        $this->assertSame(
            ['GET_INVENTORY', 'SITE_HEALTH', 'LICENSE_CHECK', 'UPDATE_PLUGIN', 'UPDATE_THEME', 'UPDATE_CORE'],
            array_column(JobType::cases(), 'value'),
        );

        foreach (['RUN_PHP', 'RUN_COMMAND', 'EXECUTE_CODE', 'EVAL'] as $forbidden) {
            $this->assertNull(JobType::tryFrom($forbidden));
            $this->assertSame(1, $this->artisan('connector:queue-job', ['website' => 'x', 'type' => $forbidden])->run());
        }
    }

    public function test_operators_queue_jobs_from_the_command_line(): void
    {
        $site = $this->pairedWebsite();
        $site['website']->jobs()->delete();

        $this->artisan('connector:queue-job', ['website' => $site['website']->uuid, 'type' => 'GET_INVENTORY'])->assertSuccessful();
        $this->artisan('connector:queue-job', ['website' => $site['website']->uuid, 'type' => 'UPDATE_PLUGIN', '--payload' => '{"plugin":"akismet/akismet.php"}'])->assertSuccessful();
        $this->artisan('connector:queue-job', ['website' => $site['website']->uuid, 'type' => 'UPDATE_PLUGIN', '--payload' => '{"plugin":"../../wp-config.php"}'])->assertFailed();

        $this->assertSame(['GET_INVENTORY', 'UPDATE_PLUGIN'], $site['website']->jobs()->orderBy('id')->get()->map(fn ($job) => $job->type->value)->all());
    }

    public function test_polling_delivers_queued_jobs_once_marked(): void
    {
        $site = $this->pairedWebsite();

        $jobs = $this->withHeaders($this->connectorHeaders($site))->getJson(route('api.v1.connector.jobs'))->assertOk()->json('jobs');

        $this->assertCount(3, $jobs);
        $this->assertSame(['uuid', 'type', 'payload', 'created_at', 'attempt'], array_keys($jobs[0]));
        $this->assertSame(1, $jobs[0]['attempt']);
        $this->assertSame(3, WebsiteJob::where('status', 'delivered')->count());
        $this->assertNotNull($site['website']->activeConnector->last_successful_poll_at);
    }

    /** @return array<string, array{JobType, array<string, mixed>}> */
    public static function malformedPayloads(): array
    {
        return [
            'plugin path traversal' => [JobType::UpdatePlugin, ['plugin' => '../../wp-config.php']],
            'plugin absolute path' => [JobType::UpdatePlugin, ['plugin' => '/var/www/html/evil.php']],
            'plugin download URL' => [JobType::UpdatePlugin, ['plugin' => 'akismet/akismet.php', 'package' => 'https://evil.example/x.zip']],
            'plugin missing' => [JobType::UpdatePlugin, []],
            'theme path' => [JobType::UpdateTheme, ['stylesheet' => '../twentytwentyfive']],
            'theme code' => [JobType::UpdateTheme, ['stylesheet' => 'x', 'code' => '<?php system("id");']],
            'core without policy' => [JobType::UpdateCore, []],
            'core bad version' => [JobType::UpdateCore, ['policy' => 'minor', 'target_version' => '7.1; rm -rf /']],
            'inventory extra field' => [JobType::GetInventory, ['hook' => 'init']],
        ];
    }

    #[DataProvider('malformedPayloads')]
    public function test_malformed_payloads_are_rejected(JobType $type, array $payload): void
    {
        $site = $this->pairedWebsite();

        $this->expectException(ValidationException::class);
        $this->queue($site['website'], $type, $payload);
    }

    public function test_valid_update_payloads_are_accepted(): void
    {
        $site = $this->pairedWebsite();

        $plugin = $this->queue($site['website'], JobType::UpdatePlugin, ['plugin' => 'akismet/akismet.php', 'target_version' => '5.4']);
        $theme = $this->queue($site['website'], JobType::UpdateTheme, ['stylesheet' => 'twentytwentyfive']);
        $core = $this->queue($site['website'], JobType::UpdateCore, ['policy' => 'minor']);

        $this->assertSame(['plugin' => 'akismet/akismet.php', 'target_version' => '5.4'], $plugin->payload);
        $this->assertSame(['stylesheet' => 'twentytwentyfive'], $theme->payload);
        $this->assertSame(['policy' => 'minor'], $core->payload);
    }

    public function test_major_core_updates_need_the_service_policy(): void
    {
        $site = $this->pairedWebsite();

        try {
            $this->queue($site['website'], JobType::UpdateCore, ['policy' => 'major']);
            $this->fail('Major update was queued.');
        } catch (ValidationException) {
        }

        config(['connector.allow_major_core_updates' => true]);
        $this->assertSame('major', $this->queue($site['website'], JobType::UpdateCore, ['policy' => 'major'])->payload['policy']);
    }

    public function test_jobs_need_a_connected_site_an_active_plan_and_the_capability(): void
    {
        $unpaired = $this->websiteWithPlan('https://unpaired.example');
        $this->expectExceptionObject(ConnectorError::jobNotAllowed('website is not connected'));
        $this->queue($unpaired, JobType::GetInventory);
    }

    public function test_jobs_are_not_delivered_once_the_plan_lapses(): void
    {
        $site = $this->pairedWebsite();
        $site['website']->subscription->update(['status' => 'past_due']);

        $jobs = $this->withHeaders($this->connectorHeaders($site))->getJson(route('api.v1.connector.jobs'))->json('jobs');

        $this->assertSame([], $jobs);
        $this->assertSame(3, WebsiteJob::where('status', 'cancelled')->count());
    }

    public function test_jobs_needing_an_unadvertised_capability_are_refused(): void
    {
        $site = $this->pairedWebsite();
        $site['website']->activeConnector->update(['capabilities' => ['inventory']]);

        $this->expectException(ConnectorError::class);
        $this->queue($site['website']->refresh(), JobType::UpdatePlugin, ['plugin' => 'akismet/akismet.php']);
    }

    public function test_result_is_recorded_once_and_repeats_are_acknowledged(): void
    {
        $site = $this->pairedWebsite();
        $job = $this->withHeaders($this->connectorHeaders($site))->getJson(route('api.v1.connector.jobs'))->json('jobs.0');

        $result = ['status' => 'success', 'result_code' => 'INVENTORY_COLLECTED', 'started_at' => now()->toIso8601String(), 'completed_at' => now()->toIso8601String(), 'data' => ['inventory' => $this->inventoryPayload()]];

        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.jobs.result', $job['uuid']), $result)
            ->assertStatus(202)->assertJson(['status' => 'success', 'duplicate' => false]);

        // A retry after a lost response, or a conflicting late report, changes nothing.
        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.jobs.result', $job['uuid']), ['status' => 'failed', 'result_code' => 'LATE'])
            ->assertOk()->assertJson(['status' => 'success', 'duplicate' => true]);

        $record = WebsiteJob::where('uuid', $job['uuid'])->sole();
        $this->assertSame(JobStatus::Success, $record->status);
        $this->assertSame('INVENTORY_COLLECTED', $record->result_code);
        $this->assertNotNull($site['website']->refresh()->inventory);
    }

    public function test_undelivered_or_unknown_jobs_cannot_be_reported(): void
    {
        $site = $this->pairedWebsite();
        $queued = $site['website']->jobs()->first();

        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.jobs.result', $queued->uuid), ['status' => 'success', 'result_code' => 'OK'])
            ->assertStatus(409)->assertJsonPath('error.code', 'JOB_NOT_DELIVERED');
        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.jobs.result', '00000000-0000-0000-0000-000000000000'), ['status' => 'success', 'result_code' => 'OK'])
            ->assertStatus(404);
        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.jobs.result', 'not-a-uuid'), ['status' => 'success', 'result_code' => 'OK'])
            ->assertStatus(404);
    }

    public function test_malformed_results_are_rejected(): void
    {
        $site = $this->pairedWebsite();
        $job = $this->withHeaders($this->connectorHeaders($site))->getJson(route('api.v1.connector.jobs'))->json('jobs.0');
        $url = route('api.v1.connector.jobs.result', $job['uuid']);

        $this->withHeaders($this->connectorHeaders($site))->postJson($url, ['status' => 'running', 'result_code' => 'OK'])->assertStatus(422);
        $this->withHeaders($this->connectorHeaders($site))->postJson($url, ['status' => 'success', 'result_code' => 'lower case'])->assertStatus(422);
        $this->withHeaders($this->connectorHeaders($site))->postJson($url, ['status' => 'failed', 'result_code' => 'X', 'error' => ['code' => 'X']])->assertStatus(422);
    }

    public function test_health_and_license_results_update_the_website(): void
    {
        $site = $this->pairedWebsite();
        $headers = $this->connectorHeaders($site);
        $jobs = collect($this->withHeaders($headers)->getJson(route('api.v1.connector.jobs'))->json('jobs'))->keyBy('type');

        $this->withHeaders($headers)->postJson(route('api.v1.connector.jobs.result', $jobs['GET_INVENTORY']['uuid']), ['status' => 'success', 'result_code' => 'INVENTORY_COLLECTED', 'data' => ['inventory' => $this->inventoryPayload()]])->assertStatus(202);
        $this->withHeaders($headers)->postJson(route('api.v1.connector.jobs.result', $jobs['SITE_HEALTH']['uuid']), ['status' => 'success', 'result_code' => 'HEALTH_CHECKED', 'data' => ['health' => ['status' => 'attention', 'findings' => [['id' => 'debug_mode', 'status' => 'warning', 'label' => 'Debug mode']]]]])->assertStatus(202);
        $this->withHeaders($headers)->postJson(route('api.v1.connector.jobs.result', $jobs['LICENSE_CHECK']['uuid']), ['status' => 'success', 'result_code' => 'LICENSES_CHECKED', 'data' => ['licenses' => [['file' => 'wp-rocket/wp-rocket.php', 'status' => 'expired', 'expires_at' => '2026-09-01', 'source' => 'plugin']]]])->assertStatus(202);

        $website = $site['website']->refresh();
        $this->assertSame('attention', $website->health_status->value);
        $this->assertSame('attention', $website->status->value);
        $this->assertSame('expired', collect($website->inventory->plugins)->firstWhere('file', 'wp-rocket/wp-rocket.php')['license']['status']);
        $this->assertSame('not_available', collect($website->inventory->plugins)->firstWhere('file', 'akismet/akismet.php')['license']['status']);
        $this->assertSame(['connect' => 'done', 'connection' => 'done', 'initial_check' => 'done', 'ready' => 'done'], $website->onboardingStates());
    }

    public function test_jobs_not_reported_after_max_attempts_expire(): void
    {
        $site = $this->pairedWebsite();

        for ($i = 0; $i < config('connector.max_job_attempts'); $i++) {
            $this->withHeaders($this->connectorHeaders($site))->getJson(route('api.v1.connector.jobs'));
        }

        $this->assertSame([], $this->withHeaders($this->connectorHeaders($site))->getJson(route('api.v1.connector.jobs'))->json('jobs'));
        $this->assertSame(3, WebsiteJob::where('status', 'expired')->count());
    }
}
