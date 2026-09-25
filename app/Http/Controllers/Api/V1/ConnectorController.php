<?php

namespace App\Http\Controllers\Api\V1;

use App\Connector\Capability;
use App\Connector\ConnectorError;
use App\Connector\HealthIngest;
use App\Connector\InventoryIngest;
use App\Connector\JobQueue;
use App\Connector\JobStatus;
use App\Connector\PairConnector;
use App\Connector\RecordJobResult;
use App\Http\Controllers\Controller;
use App\Models\Connector;
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
        $data = $request->validate([
            'pairing_code' => ['required', 'string', 'max:32'],
            'installation_id' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{16,64}$/'],
            'site_url' => ['required', 'string', 'max:255', 'url:http,https'],
            'connector_version' => ['required', 'string', 'max:32', 'regex:/^\d+\.\d+\.\d+/'],
            'wp_version' => ['nullable', 'string', 'max:32'],
            'php_version' => ['nullable', 'string', 'max:32'],
            'capabilities' => ['present', 'array', 'max:20'],
            'capabilities.*' => ['string', 'max:32'],
        ]);

        [$connector, $credential] = $pairing->pair($data);

        return response()->json([
            'website_uuid' => $connector->website->uuid,
            'connector_uuid' => $connector->uuid,
            // Returned exactly once; the server keeps only its hash.
            'credential' => $credential,
            'capabilities' => $connector->capabilities,
            ...$this->schedule(),
        ], 201);
    }

    public function heartbeat(Request $request, HealthIngest $health): JsonResponse
    {
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
            ...HealthIngest::rules('health.'),
            'sent_at' => ['nullable', 'date'],
        ]);

        $connector->forceFill([
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
            ...$this->schedule(),
        ]);
    }

    public function inventory(Request $request, InventoryIngest $ingest): JsonResponse
    {
        $connector = $this->connector($request);
        $ingest->store($connector, $request->all());

        return response()->json(['ok' => true], 202);
    }

    public function jobs(Request $request, JobQueue $queue): JsonResponse
    {
        $connector = $this->connector($request);
        $jobs = $queue->deliver($connector);

        $connector->forceFill(['last_successful_poll_at' => now()])->save();

        return response()->json(['jobs' => $jobs->map->toConnectorArray()->all(), ...$this->schedule()]);
    }

    public function result(Request $request, string $job, RecordJobResult $results): JsonResponse
    {
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

    /** @return array<string, int> */
    private function schedule(): array
    {
        return [
            'heartbeat_interval' => config('connector.heartbeat_interval'),
            'poll_interval' => config('connector.poll_interval'),
        ];
    }
}
