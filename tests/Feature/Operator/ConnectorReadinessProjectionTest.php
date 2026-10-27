<?php

namespace Tests\Feature\Operator;

use App\Connector\JobType;
use App\Models\User;
use App\Models\WebsiteJob;
use App\Operator\Permission;
use App\Support\DisplayDateTime;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\OperatesConsole;
use Tests\TestCase;

final class ConnectorReadinessProjectionTest extends TestCase
{
    use OperatesConsole, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups(['manual' => true, 'restore' => true, 'pre_update_backup' => false]);
    }

    protected function tearDown(): void
    {
        $this->tearDownBackups();
        parent::tearDown();
    }

    public function test_operator_with_website_view_permission_sees_scoped_persisted_readiness_only(): void
    {
        $this->asOperator([Permission::WebsitesView]);
        $site = $this->consoleSite('https://readiness-operator-a.example');
        $other = $this->consoleSite('https://readiness-operator-b.example');
        $website = $site['website'];
        $connector = $website->activeConnector;
        $heartbeatAt = now()->startOfSecond();
        $connector->update([
            'last_seen_at' => $heartbeatAt,
            'last_heartbeat_at' => $heartbeatAt,
            'last_successful_poll_at' => $heartbeatAt,
        ]);
        $other['website']->activeConnector->update(['connector_version' => 'OTHER_SITE_PRIVATE_MARKER']);
        $website->jobs()->create([
            'type' => JobType::GetInventory,
            'status' => 'waiting',
            'payload' => ['private_marker' => 'JOB_PAYLOAD_MUST_NOT_RENDER'],
            'requested_by' => 'system',
        ]);

        Queue::fake();
        Http::preventStrayRequests();
        $writes = [];
        DB::listen(static function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|drop|create)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $response = $this->get(route('operator.websites.show', $website))->assertOk()
            ->assertSee(__('operator.connector.readiness.title'))
            ->assertSee(__('operator.connector.readiness.operation_capabilities'))
            ->assertSee(__('operator.connector.readiness.running_unknown'))
            ->assertSee(__('operator.connector.readiness.heartbeat'))
            ->assertSee(app(DisplayDateTime::class)->format($heartbeatAt))
            ->assertSee('1');

        $this->assertStringNotContainsString('OTHER_SITE_PRIVATE_MARKER', $response->getContent());
        $this->assertStringNotContainsString('JOB_PAYLOAD_MUST_NOT_RENDER', $response->getContent());
        $this->assertStringNotContainsString($site['credential'], $response->getContent());
        $this->assertSame([], $writes);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame(1, WebsiteJob::query()->where('website_id', $website->id)->where('status', 'waiting')->count());
        $this->assertSame(0, WebsiteJob::query()->where('website_id', $other['website']->id)->count());
    }

    public function test_operator_without_website_view_permission_cannot_read_readiness(): void
    {
        $site = $this->consoleSite('https://readiness-denied.example');
        $this->asOperator([]);

        $this->get(route('operator.websites.show', $site['website']))
            ->assertForbidden()
            ->assertDontSee(__('operator.connector.readiness.title'));
    }

    public function test_owner_and_customer_sessions_cannot_read_operator_readiness(): void
    {
        $site = $this->consoleSite('https://operator-auth.example');

        $this->actingAs($site['website']->customer->user)->get(route('operator.websites.show', $site['website']))
            ->assertRedirect(route('operator.login'));

        $owner = User::factory()->owner()->create();
        $this->actingAs($owner, 'owner')->get(route('operator.websites.show', $site['website']))
            ->assertRedirect(route('operator.login'));
    }
}
