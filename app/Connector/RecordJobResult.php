<?php

namespace App\Connector;

use App\Models\Connector;
use App\Models\Website;
use App\Models\WebsiteJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stores a job result from a connector. Reporting the same job twice is
 * harmless: the first final result wins and later ones are acknowledged
 * without changes.
 */
final class RecordJobResult
{
    public function __construct(
        private readonly InventoryIngest $inventory,
        private readonly HealthIngest $health,
    ) {}

    /**
     * @param  array<string, mixed>  $result  Validated request data.
     * @return array{job: WebsiteJob, duplicate: bool}
     */
    public function record(Connector $connector, string $jobUuid, array $result): array
    {
        return DB::transaction(function () use ($connector, $jobUuid, $result) {
            // Match pairing and delivery lock order. A result that arrives
            // after re-pair observes the terminal cancellation and cannot
            // update inventory or lifecycle state.
            $website = Website::query()->whereKey($connector->website_id)->lockForUpdate()->first();
            if (! $website) {
                throw ConnectorError::jobNotFound();
            }

            $job = WebsiteJob::where('uuid', $jobUuid)->where('website_id', $connector->website_id)->lockForUpdate()->first();

            if (! $job) {
                throw ConnectorError::jobNotFound();
            }

            if ($job->status->isFinal()) {
                return ['job' => $job, 'duplicate' => true];
            }

            if ($job->connector_id !== $connector->id) {
                throw ConnectorError::jobNotDelivered();
            }

            $job->forceFill([
                'status' => JobStatus::from($result['status']),
                'started_at' => isset($result['started_at']) ? Carbon::parse($result['started_at']) : null,
                'completed_at' => isset($result['completed_at']) ? Carbon::parse($result['completed_at']) : now(),
                'result_code' => $result['result_code'],
                'result' => $result['data'] ?? null,
                'error' => $result['error'] ?? null,
            ])->save();

            $this->apply($connector, $job, $result['data'] ?? []);
            app(JobLifecycle::class)->finished($job);

            Log::info('Connector job result recorded.', ['job' => $job->uuid, 'type' => $job->type->value, 'status' => $job->status->value, 'code' => $job->result_code]);

            return ['job' => $job, 'duplicate' => false];
        });
    }

    /** Results that carry site data update the website's current state. */
    private function apply(Connector $connector, WebsiteJob $job, array $data): void
    {
        if (isset($data['inventory']) && is_array($data['inventory'])) {
            $this->inventory->store($connector, $data['inventory']);
        }

        if (isset($data['health']) && is_array($data['health'])) {
            $this->health->store($connector->website, $data['health']);
        }

        if (isset($data['licenses']) && is_array($data['licenses'])) {
            $this->inventory->storeLicenses($connector, $data['licenses']);
        }
    }
}
