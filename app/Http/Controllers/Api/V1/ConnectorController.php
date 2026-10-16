<?php

namespace App\Http\Controllers\Api\V1;

use App\AdminAccess\AdminAccessService;
use App\Connector\Capability;
use App\Connector\ConnectorError;
use App\Connector\HealthIngest;
use App\Connector\InventoryIngest;
use App\Connector\JobQueue;
use App\Connector\JobStatus;
use App\Connector\PairConnector;
use App\Connector\PayloadBounds;
use App\Connector\RecordJobResult;
use App\Http\Controllers\Controller;
use App\Models\Connector;
use App\Models\Website;
use App\SyntheticFixtures\SyntheticFixturePolicy;
use App\Websites\ConnectorStatus;
use App\Websites\WebsiteStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Connector API, version 1. Every endpoint except `pair` requires a
 * connector credential and acts only on that connector's website.
 */
class ConnectorController extends Controller
{
    public function pair(Request $request, PairConnector $pairing): JsonResponse
    {
        PayloadBounds::assert($request, 16 * 1024, 8, 64, 8 * 1024);
        $data = $request->validate([
            'pairing_code' => ['required', 'string', 'max:32'],
            'installation_id' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{16,64}$/'],
            'site_url' => ['required', 'string', 'max:255', 'url:http,https'],
            'connector_version' => ['required', 'string', 'max:32', 'regex:/^\d+\.\d+\.\d+/'],
            'wp_version' => ['nullable', 'string', 'max:32'],
            'php_version' => ['nullable', 'string', 'max:32'],
            'capabilities' => ['present', 'array', 'max:20'],
            'capabilities.*' => ['string', 'max:32'],
            'admin_access_url' => ['nullable', 'string', 'max:255', 'url:http,https'],
        ]);

        [$connector, $credential] = $pairing->pair($data);
        $connector->forceFill(['admin_access_url' => $data['admin_access_url'] ?? null])->save();

        return response()->json([
            'website_uuid' => $connector->website->uuid,
            'connector_uuid' => $connector->uuid,
            // Returned exactly once; the server keeps only its hash.
            'credential' => $credential,
            'capabilities' => $connector->capabilities,
            ...$this->schedule($connector->website),
        ], 201);
    }

    public function heartbeat(Request $request, HealthIngest $health): JsonResponse
    {
        PayloadBounds::assert($request, 128 * 1024, 12, 2048, 96 * 1024);
        $connector = $this->connector($request);

        $data = $request->validate([
            'connector_version' => ['required', 'string', 'max:32', 'regex:/^\d+\.\d+\.\d+/'],
            'wp_version' => ['required', 'string', 'max:32'],
            'php_version' => ['required', 'string', 'max:32'],
            'site_url' => ['required', 'string', 'max:255', 'url:http,https'],
            'woocommerce' => ['nullable', 'array'],
            'woocommerce.installed' => ['required_with:woocommerce', 'boolean'],
            'woocommerce.active' => ['required_with:woocommerce', 'boolean'],
            'woocommerce.version' => ['nullable', 'string', 'max:32'],
            'last_successful_poll_at' => ['nullable', 'date'],
            'capabilities' => ['present', 'array', 'max:20'],
            'capabilities.*' => ['string', 'max:32'],
            'health' => ['nullable', 'array'],
            // Null until the site has run its first health check.
            ...($request->filled('health') ? HealthIngest::rules('health.') : []),
            'sent_at' => ['nullable', 'date'],
            'admin_access_url' => ['nullable', 'string', 'max:255', 'url:http,https'],
        ]);

        $connector->forceFill([
            'admin_access_url' => $data['admin_access_url'] ?? null,
            'connector_version' => $data['connector_version'],
            'site_url' => $data['site_url'],
            'capabilities' => Capability::listFrom($data['capabilities']),
            'last_heartbeat_at' => now(),
            'last_seen_at' => now(),
        ])->save();

        $website = $connector->website;
        $website->update([
            'wordpress_version' => $data['wp_version'],
            'php_version' => $data['php_version'],
            'last_seen_at' => now(),
            // A disconnected-by-silence site that reports in again is connected.
            'status' => $website->status === WebsiteStatus::Disconnected ? WebsiteStatus::Connected : $website->status,
        ]);

        if (! empty($data['health'])) {
            $health->store($website, $data['health']);
        }

        return response()->json([
            'ok' => true,
            'jobs_pending' => $website->jobs()->whereIn('status', [JobStatus::Queued, JobStatus::Delivered])->count(),
            'latest_connector_version' => config('connector.latest_version'),
            'connector_outdated' => $connector->isOutdated(),
            'admin_access' => app(AdminAccessService::class)->liveFor($website),
            ...$this->schedule($website),
        ]);
    }

    public function inventory(Request $request, InventoryIngest $ingest): JsonResponse
    {
        PayloadBounds::assert($request, 4 * 1024 * 1024, 12, 30000, 3 * 1024 * 1024);
        $connector = $this->connector($request);
        $ingest->store($connector, $request->all());

        return response()->json(['ok' => true], 202);
    }

    public function jobs(Request $request, JobQueue $queue): JsonResponse
    {
        $connector = $this->connector($request);
        $data = $request->validate(['execution_mode' => ['nullable', Rule::in(['controlled'])]]);
        $controlledExecution = ($data['execution_mode'] ?? null) === 'controlled';
        $jobs = $queue->deliver($connector, $controlledExecution);

        $connector->forceFill(['last_successful_poll_at' => now()])->save();

        return response()->json([
            'jobs' => $jobs->map->toConnectorArray()->all(),
            // Admin sessions the site may keep; it ends any other CareGrid session.
            'admin_access' => app(AdminAccessService::class)->liveFor($connector->website),
            ...$this->schedule($connector->website),
        ]);
    }

    public function result(Request $request, string $job, RecordJobResult $results): JsonResponse
    {
        PayloadBounds::assert($request, 4 * 1024 * 1024, 16, 20000, 3 * 1024 * 1024);
        $connector = $this->connector($request);

        if (! preg_match('/^[0-9a-f-]{36}$/i', $job)) {
            throw ConnectorError::jobNotFound();
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(array_map(fn ($s) => $s->value, JobStatus::reportable()))],
            'result_code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9_]+$/'],
            'started_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
            'data' => ['nullable', 'array'],
            'error' => ['nullable', 'array'],
            'error.code' => ['required_with:error', 'string', 'max:64', 'regex:/^[A-Z0-9_]+$/'],
            'error.message' => ['required_with:error', 'string', 'max:500'],
            'error.details' => ['nullable', 'array'],
        ]);

        ['job' => $record, 'duplicate' => $duplicate] = $results->record($connector, $job, $data);

        return response()->json([
            'job' => $record->uuid,
            'status' => $record->status->value,
            'duplicate' => $duplicate,
        ], $duplicate ? 200 : 202);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $connector = $this->connector($request);
        $connector->revoke('disconnected_by_site');

        $connector->website->update([
            'connector_status' => ConnectorStatus::Revoked,
            'status' => WebsiteStatus::Disconnected,
        ]);
        $connector->website->jobs()->whereIn('status', [JobStatus::Queued, JobStatus::Delivered])
            ->update(['status' => JobStatus::Cancelled->value]);

        Log::info('Connector disconnected by the website.', ['website' => $connector->website->uuid, 'connector' => $connector->uuid]);

        return response()->json(['ok' => true]);
    }

    private function connector(Request $request): Connector
    {
        return $request->attributes->get('connector');
    }

    /** @return array<string, int|bool|string|null> */
    private function schedule(?Website $website = null): array
    {
        $policy = $website ? app(SyntheticFixturePolicy::class) : null;

        return [
            'heartbeat_interval' => config('connector.heartbeat_interval'),
            'poll_interval' => config('connector.poll_interval'),
            'website_uuid' => $website?->uuid,
            'synthetic_site' => $website ? $website->syntheticFixture()->exists() : false,
            'controlled_execution' => $website ? $policy->controlledExecutionAllowed($website) : false,
        ];
    }
}
