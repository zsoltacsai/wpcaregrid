<?php

namespace App\Compatibility;

use App\Models\Connector;
use App\Models\Website;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CapabilitySnapshotStore
{
    public function __construct(private readonly CapabilityEvaluator $evaluator) {}

    public function refresh(Website $website, ?Connector $connector, ?array $facts, ?Carbon $evidenceCheckedAt = null): CapabilitySnapshot
    {
        $at = now();
        $capabilities = $this->evaluator->evaluate($website, $connector, $facts);
        $snapshot = DB::transaction(function () use ($website, $connector, $facts, $capabilities, $at, $evidenceCheckedAt) {
            $existing = CapabilitySnapshot::query()->where('website_id', $website->id)->lockForUpdate()->first();
            $previous = $existing?->capabilities ?? [];
            $model = CapabilitySnapshot::updateOrCreate(['website_id' => $website->id], [
                'connector_id' => $connector?->id,
                'connector_version' => $connector?->connector_version,
                'connector_capabilities_hash' => $this->capabilitiesHash($connector),
                'wordpress_version' => $website->wordpress_version,
                'php_version' => $website->php_version,
                'policy_version' => config('compatibility.policy_version'),
                'evaluator_version' => config('compatibility.evaluator_version'),
                'schema_version' => (int) ($facts['schema_version'] ?? 0),
                'facts' => $facts,
                'capabilities' => $capabilities,
                'checked_at' => $evidenceCheckedAt ?? $at,
            ]);

            if ($previous !== $capabilities) {
                $website->capabilityTransitions()->create([
                    'policy_version' => $model->policy_version,
                    'capabilities' => $capabilities,
                    'changed_at' => $at,
                ]);
                $retainedIds = CapabilityTransition::query()
                    ->where('website_id', $website->id)
                    ->latest('id')
                    ->limit(50)
                    ->select('id');
                CapabilityTransition::query()->where('website_id', $website->id)->whereNotIn('id', $retainedIds)->delete();
            }

            return $model;
        });

        return $snapshot;
    }

    private function capabilitiesHash(?Connector $connector): ?string
    {
        if (! $connector) {
            return null;
        }

        $capabilities = array_values(array_unique(array_filter($connector->capabilities ?? [], 'is_string')));
        sort($capabilities, SORT_STRING);

        return hash('sha256', json_encode($capabilities, JSON_THROW_ON_ERROR));
    }
}
