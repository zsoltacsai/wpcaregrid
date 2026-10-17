<?php

namespace App\Backups;

use App\Models\BackupGfsEvaluation;
use App\Models\Website;
use App\Websites\AuditLog;
use Carbon\CarbonImmutable;

final class BackupGfsShadowStore
{
    public function __construct(private readonly GfsRetentionEvaluator $evaluator) {}

    /** Calculate and keep only the latest bounded report; never dispatches work or touches backup storage. */
    public function evaluate(Website $website, ?CarbonImmutable $at = null): BackupGfsEvaluation
    {
        $result = $this->evaluator->evaluate($website, $at);
        $previous = BackupGfsEvaluation::query()->where('website_id', $website->id)->first();
        $previousMissing = $previous?->result['missing_slots'] ?? [];
        $missing = $result['missing_slots'] ?? [];

        $evaluation = BackupGfsEvaluation::query()->updateOrCreate(
            ['website_id' => $website->id],
            [
                'subscription_backup_entitlement_id' => $result['entitlement_snapshot_id'] ?? null,
                'catalogue_version' => $result['catalogue_version'] ?? null,
                'result_status' => $result['status'],
                'source_fingerprint' => $result['evaluation_fingerprint'],
                'missing_slot_count' => count($missing),
                'result' => $result,
                'evaluated_at' => $result['evaluated_at'],
            ],
        );

        $previousGapKey = $this->gapKey($previousMissing);
        $gapKey = $this->gapKey($missing);
        if ($gapKey !== $previousGapKey) {
            if ($missing !== []) {
                AuditLog::record($website, 'backup.recovery_gap', data: [
                    'missing_slot_count' => count($missing),
                    'classes' => implode(',', array_values(array_unique(array_column($missing, 'class')))),
                    'catalogue_version' => (string) ($result['catalogue_version'] ?? 'unknown'),
                ]);
            } elseif ($previousMissing !== [] && $result['status'] === 'CURRENT') {
                AuditLog::record($website, 'backup.recovery_gap_resolved', data: [
                    'catalogue_version' => (string) ($result['catalogue_version'] ?? 'unknown'),
                ]);
            }
        }

        return $evaluation;
    }

    private function gapKey(array $missing): string
    {
        $keys = array_map(fn (array $slot) => ($slot['class'] ?? '').':'.($slot['slot'] ?? ''), $missing);
        sort($keys, SORT_STRING);

        return hash('sha256', json_encode($keys, JSON_THROW_ON_ERROR));
    }
}
