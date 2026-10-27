<?php

namespace Tests\Feature\Connector;

use App\Connector\JobStatus;
use App\Connector\JobType;
use App\Connector\ReadinessProjection;
use App\Models\WebsiteJob;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\PairsConnectors;
use Tests\TestCase;

final class ReadinessProjectionReadOnlyTest extends TestCase
{
    use PairsConnectors, RefreshDatabase;

    public function test_job_aggregation_is_site_and_connector_scoped_and_delivered_is_not_running(): void
    {
        $site = $this->pairedWebsite('https://projection.example');
        $website = $site['website'];
        $connector = $website->activeConnector;
        $jobs = $website->jobs()->orderBy('id')->get();
        $jobs[0]->update(['status' => JobStatus::Waiting]);
        $jobs[1]->update(['status' => JobStatus::Delivered, 'connector_id' => $connector->id]);
        $jobs[2]->update([
            'status' => JobStatus::Delivered,
            'connector_id' => $this->pairedWebsite('https://other.example')['website']->activeConnector->id,
        ]);
        $website->jobs()->create([
            'type' => JobType::GetInventory,
            'status' => JobStatus::Delivered,
            'connector_id' => $connector->id,
            'payload' => ['private_marker' => 'must-not-be-exposed'],
            'requested_by' => 'system',
        ]);
        $website->jobs()->create([
            'type' => JobType::GetInventory,
            'status' => JobStatus::Queued,
            'payload' => [],
            'requested_by' => 'system',
        ]);

        $projection = app(ReadinessProjection::class)->project($website, $connector);

        $this->assertSame(1, $projection['jobs']['queued']);
        $this->assertSame(1, $projection['jobs']['waiting']);
        $this->assertSame(2, $projection['jobs']['delivered']);
        $this->assertSame('UNKNOWN', $projection['jobs']['running']['state']);
        $this->assertStringNotContainsString('must-not-be-exposed', json_encode($projection, JSON_THROW_ON_ERROR));
    }

    public function test_projection_performs_only_reads_and_never_dispatches_or_calls_connectors(): void
    {
        $site = $this->pairedWebsite('https://read-only.example');
        $website = $site['website'];
        $connector = $website->activeConnector;
        $websiteBefore = $website->getAttributes();
        $connectorBefore = $connector->getAttributes();
        $jobCountBefore = WebsiteJob::query()->count();
        $writes = [];

        Queue::fake();
        Http::preventStrayRequests();
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|drop|create)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        app(ReadinessProjection::class)->project($website, $connector);

        $this->assertSame([], $writes);
        $this->assertSame($websiteBefore, $website->getAttributes());
        $this->assertSame($connectorBefore, $connector->getAttributes());
        $this->assertSame($jobCountBefore, WebsiteJob::query()->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_projection_never_returns_credentials_payloads_or_customer_content(): void
    {
        $site = $this->pairedWebsite('https://private.example');
        $website = $site['website'];
        $connector = $website->activeConnector;
        $website->jobs()->create([
            'type' => JobType::GetInventory,
            'status' => JobStatus::Queued,
            'payload' => ['private_marker' => 'customer-content-secret-marker'],
            'requested_by' => 'system',
        ]);

        $json = json_encode(app(ReadinessProjection::class)->project($website, $connector), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($site['credential'], $json);
        $this->assertStringNotContainsString($connector->credential_hash, $json);
        $this->assertStringNotContainsString('customer-content-secret-marker', $json);
        $this->assertStringNotContainsString('payload', $json);
    }
}
