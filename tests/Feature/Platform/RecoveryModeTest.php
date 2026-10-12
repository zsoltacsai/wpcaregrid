<?php

namespace Tests\Feature\Platform;

use App\Connector\ConnectorError;
use App\Connector\JobQueue;
use App\Connector\JobStatus;
use App\Connector\JobType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\PairsConnectors;
use Tests\TestCase;

class RecoveryModeTest extends TestCase
{
    use PairsConnectors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The tests below exercise database rows directly. Keep unrelated
        // queued notifications/jobs from fixture setup out of the jobs table.
        Queue::fake();
    }

    public function test_platform_recovery_mode_holds_site_changing_jobs_and_only_delivers_read_only_jobs(): void
    {
        $site = $this->pairedWebsite();
        $website = $site['website'];
        $update = $website->jobs()->create([
            'type' => JobType::UpdatePlugin,
            'payload' => ['plugin' => 'akismet/akismet.php'],
            'status' => JobStatus::Queued,
            'requested_by' => 'test',
        ]);

        config(['platform.recovery_mode' => true]);

        try {
            app(JobQueue::class)->queue($website->load('activeConnector'), JobType::UpdatePlugin, ['plugin' => 'hello.php']);
            $this->fail('A site-changing job was queued during platform recovery.');
        } catch (ConnectorError $error) {
            $this->assertSame('JOB_NOT_ALLOWED', $error->errorCode);
            $this->assertSame('Job not allowed: platform recovery mode is active.', $error->getMessage());
        }

        $jobs = $this->withHeaders($this->connectorHeaders($site))
            ->getJson(route('api.v1.connector.jobs'))
            ->assertOk()
            ->json('jobs');

        $this->assertEqualsCanonicalizing(['GET_INVENTORY', 'SITE_HEALTH', 'LICENSE_CHECK'], array_column($jobs, 'type'));
        $this->assertSame(JobStatus::Queued, $update->refresh()->status);
        $this->assertSame(0, $update->attempts);
    }

    public function test_queue_quarantine_requires_recovery_mode_and_preserves_payload_with_checksum(): void
    {
        config(['platform.recovery_mode' => false, 'queue.default' => 'database']);
        $existingJobs = DB::table('jobs')->count();
        $jobId = DB::table('jobs')->insertGetId([
            'queue' => 'default', 'payload' => '{"displayName":"MailJob","data":{"command":"serialized"}}',
            'attempts' => 0, 'reserved_at' => null, 'available_at' => 100, 'created_at' => 90,
        ]);

        $this->artisan('platform:recovery-quarantine-queue --confirm-workers-stopped')->assertFailed();
        $this->assertDatabaseCount('jobs', $existingJobs + 1);
        $this->assertDatabaseCount('platform_recovery_quarantined_jobs', 0);

        config(['platform.recovery_mode' => true]);
        $this->artisan('platform:recovery-quarantine-queue')->assertFailed();
        $this->assertDatabaseCount('jobs', $existingJobs + 1);
        $this->artisan('platform:recovery-quarantine-queue --confirm-workers-stopped')
            ->assertSuccessful()
            ->expectsOutputToContain('Quarantined '.($existingJobs + 1).' Laravel queue jobs and 0 destructive WebsiteJobs');

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('platform_recovery_quarantined_jobs', $existingJobs + 1);
        $quarantined = DB::table('platform_recovery_quarantined_jobs')->where('original_job_id', $jobId)->sole();
        $this->assertSame(hash('sha256', $quarantined->payload), $quarantined->payload_sha256);
        $this->assertSame('default', $quarantined->queue);

        $this->artisan('platform:recovery-quarantine-queue --confirm-workers-stopped')->assertSuccessful()->expectsOutputToContain('Quarantined 0 Laravel queue jobs');
        $this->assertDatabaseCount('platform_recovery_quarantined_jobs', $existingJobs + 1);
    }

    public function test_queue_quarantine_refuses_reserved_jobs_even_after_operator_confirmation(): void
    {
        config(['platform.recovery_mode' => true, 'queue.default' => 'database']);
        $existingJobs = DB::table('jobs')->count();
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{"displayName":"LongRunningJob"}',
            'attempts' => 1, 'reserved_at' => now()->timestamp, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
        ]);

        $this->artisan('platform:recovery-quarantine-queue --confirm-workers-stopped')->assertFailed();
        $this->assertDatabaseCount('jobs', $existingJobs + 1);
        $this->assertDatabaseCount('platform_recovery_quarantined_jobs', 0);
    }

    public function test_queue_quarantine_cancels_and_preserves_destructive_websitejobs(): void
    {
        $site = $this->pairedWebsite();
        $job = $site['website']->jobs()->create([
            'type' => JobType::Restore,
            'payload' => ['restore_uuid' => (string) str()->uuid(), 'backup_uuid' => (string) str()->uuid(), 'scope' => 'full', 'objects' => [['name' => 'files', 'size' => 1, 'sha256' => str_repeat('a', 64)]]],
            'status' => JobStatus::Queued,
            'requested_by' => 'test',
        ]);
        config(['platform.recovery_mode' => true, 'queue.default' => 'database']);
        $existingJobs = DB::table('jobs')->count();

        $this->artisan('platform:recovery-quarantine-queue --confirm-workers-stopped')->expectsOutputToContain('Quarantined '.$existingJobs.' Laravel queue jobs and 1 destructive WebsiteJobs')->assertSuccessful();
        $this->assertSame(JobStatus::Cancelled, $job->refresh()->status);
        $this->assertSame('PLATFORM_RECOVERY_QUARANTINED', $job->error['code']);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('platform_recovery_quarantined_jobs', $existingJobs);
        $quarantined = DB::table('platform_recovery_quarantined_website_jobs')->sole();
        $this->assertSame($job->uuid, $quarantined->job_uuid);
        $this->assertSame(hash('sha256', $quarantined->payload), $quarantined->payload_sha256);
    }

    public function test_queue_quarantine_refuses_non_database_queue_driver(): void
    {
        config(['platform.recovery_mode' => true, 'queue.default' => 'sync']);

        $this->artisan('platform:recovery-quarantine-queue --confirm-workers-stopped')->assertFailed();
        $this->assertDatabaseCount('platform_recovery_quarantined_jobs', 0);
    }
}
