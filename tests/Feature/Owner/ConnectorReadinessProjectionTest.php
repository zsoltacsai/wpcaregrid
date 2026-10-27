<?php

namespace Tests\Feature\Owner;

use App\Connector\JobType;
use App\Models\User;
use App\Models\WebsiteJob;
use App\Support\DisplayDateTime;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsOwner;
use Tests\Concerns\PairsConnectors;
use Tests\TestCase;

final class ConnectorReadinessProjectionTest extends TestCase
{
    use ActsAsOwner, PairsConnectors, RefreshDatabase;

    public function test_owner_sees_read_only_readiness_for_only_the_requested_website(): void
    {
        $this->asOwner();
        $site = $this->pairedWebsite('https://readiness-owner-a.example');
        $other = $this->pairedWebsite('https://readiness-owner-b.example');
        $this->withHeaders($this->connectorHeaders($site))
            ->postJson(route('api.v1.connector.inventory'), $this->inventoryPayload())
            ->assertSuccessful();
        $connector = $site['website']->activeConnector;
        $heartbeatAt = now()->startOfSecond();
        $connector->update([
            'last_seen_at' => $heartbeatAt,
            'last_heartbeat_at' => $heartbeatAt,
            'last_successful_poll_at' => $heartbeatAt,
        ]);
        $other['website']->activeConnector->update(['connector_version' => 'OTHER_SITE_PRIVATE_MARKER']);
        $site['website']->jobs()->create([
            'type' => JobType::GetInventory,
            'status' => 'queued',
            'payload' => ['private_marker' => 'JOB_PAYLOAD_MUST_NOT_RENDER'],
            'requested_by' => 'system',
        ]);

        $siteJobsBefore = WebsiteJob::query()->where('website_id', $site['website']->id)->count();
        $otherJobsBefore = WebsiteJob::query()->where('website_id', $other['website']->id)->count();

        Queue::fake();
        Http::preventStrayRequests();
        $writes = [];
        DB::listen(static function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|drop|create)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $response = $this->get(route('owner.websites.show', $site['website']))->assertOk()
            ->assertSee(__('owner.websites.readiness.title'))
            ->assertSee(__('owner.websites.readiness.operation_capabilities'))
            ->assertSee(__('owner.websites.readiness.running_unknown'))
            ->assertSee(__('owner.websites.readiness.heartbeat'))
            ->assertSee(__('owner.websites.readiness.inventory_collected'))
            ->assertSee(app(DisplayDateTime::class)->format($heartbeatAt))
            ->assertSee('1');

        $this->assertStringNotContainsString('OTHER_SITE_PRIVATE_MARKER', $response->getContent());
        $this->assertStringNotContainsString('JOB_PAYLOAD_MUST_NOT_RENDER', $response->getContent());
        $this->assertSame([], $writes);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame($siteJobsBefore, WebsiteJob::query()->where('website_id', $site['website']->id)->count());
        $this->assertSame($otherJobsBefore, WebsiteJob::query()->where('website_id', $other['website']->id)->count());
    }

    public function test_non_owner_roles_cannot_read_owner_readiness_page(): void
    {
        $site = $this->pairedWebsite('https://owner-auth.example');

        $this->actingAs($site['website']->customer->user)->get(route('owner.websites.show', $site['website']))
            ->assertRedirect(route('owner.login'));

        $operator = User::factory()->operator()->create();
        $this->actingAs($operator, 'operator')->get(route('owner.websites.show', $site['website']))
            ->assertRedirect(route('owner.login'));
    }
}
