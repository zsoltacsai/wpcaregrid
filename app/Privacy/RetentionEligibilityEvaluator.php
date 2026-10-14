<?php

namespace App\Privacy;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Pure report-only calculation. It has no persistence, queue, mutation or provider dependencies. */
final class RetentionEligibilityEvaluator
{
    public const ENGINE_VERSION = 'm12-wave4.1';

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function evaluate(array $input): array
    {
        $evaluatedAt = CarbonImmutable::parse($input['evaluated_at'] ?? 'now', 'UTC')->utc();
        $triggerAt = isset($input['trigger_at']) ? CarbonImmutable::parse($input['trigger_at'], 'UTC')->utc() : null;
        $duration = $input['duration'] ?? null;
        $unit = $input['unit'] ?? null;
        if ($duration !== null && (! is_int($duration) || $duration < 0)) {
            throw new InvalidArgumentException('Retention duration must be a non-negative integer.');
        }

        $eligibleAt = null;
        if ($triggerAt !== null && $duration !== null) {
            $eligibleAt = match ($unit) {
                'seconds' => $triggerAt->addSeconds($duration),
                'hours' => $triggerAt->addHours($duration),
                'days' => $triggerAt->addDays($duration),
                'years' => $triggerAt->addYears($duration),
                default => throw new InvalidArgumentException('Unsupported retention duration unit.'),
            };
        }

        $blockers = array_values(array_unique(array_filter((array) ($input['blockers'] ?? []), 'is_string')));
        $coverage = $input['coverage_state'] ?? 'FULL';
        if (! in_array($coverage, ['FULL', 'PARTIAL', 'UNKNOWN'], true)) {
            $coverage = 'UNKNOWN';
            $blockers[] = 'BLOCKED_PROVIDER_FACT';
        } elseif ($coverage !== 'FULL') {
            $blockers[] = 'BLOCKED_PROVIDER_FACT';
        }
        if (($input['source_known'] ?? true) !== true || $triggerAt === null) {
            $blockers[] = 'UNKNOWN_SOURCE_OR_TRIGGER';
        }
        if (($input['policy_present'] ?? true) !== true || $duration === null) {
            $blockers[] = 'BLOCKED_POLICY_MISSING';
        }
        foreach ([
            'legal_approved' => 'BLOCKED_LEGAL_APPROVAL',
            'accounting_approved' => 'BLOCKED_ACCOUNTING_APPROVAL',
            'security_approved' => 'BLOCKED_SECURITY_REVIEW',
            'provider_confirmed' => 'BLOCKED_PROVIDER_STATE',
        ] as $key => $code) {
            if (($input[$key] ?? true) !== true) {
                $blockers[] = $code;
            }
        }
        $blockers = array_values(array_unique($blockers));

        $priority = [
            'UNKNOWN_SOURCE_OR_TRIGGER' => 0,
            'BLOCKED_PROVIDER_FACT' => 1,
            'BLOCKED_ACTIVE_RESTORE' => 2,
            'BLOCKED_ACTIVE_OPERATION' => 3,
            'BLOCKED_LAST_RECOVERY_POINT' => 4,
            'BLOCKED_ACCOUNTING_APPROVAL' => 5,
            'BLOCKED_LEGAL_APPROVAL' => 6,
            'BLOCKED_SECURITY_REVIEW' => 7,
            'BLOCKED_PROVIDER_STATE' => 8,
            'BLOCKED_POLICY_MISSING' => 9,
            'MANUAL_REVIEW' => 10,
        ];
        usort($blockers, fn (string $a, string $b): int => ($priority[$a] ?? 20) <=> ($priority[$b] ?? 20));

        if ($blockers !== []) {
            $primary = $blockers[0];
            $result = str_starts_with($primary, 'UNKNOWN') ? 'UNKNOWN' : ($primary === 'MANUAL_REVIEW' ? 'MANUAL_REVIEW' : $primary);
        } elseif ($eligibleAt === null) {
            $result = 'UNKNOWN';
            $blockers[] = 'UNKNOWN_SOURCE_OR_TRIGGER';
        } else {
            $result = $evaluatedAt->greaterThanOrEqualTo($eligibleAt) ? 'ELIGIBLE_DRY_RUN' : 'NOT_ELIGIBLE';
        }

        return [
            'category' => (string) ($input['category'] ?? 'UNKNOWN'),
            'object_ref' => (string) ($input['object_ref'] ?? 'opaque'),
            'policy_version' => $input['policy_version'] ?? null,
            'technical_value' => ['value' => $duration, 'unit' => $unit],
            'trigger' => $input['trigger'] ?? null,
            'eligible_at' => $eligibleAt?->toIso8601String(),
            'evaluated_at' => $evaluatedAt->toIso8601String(),
            'technical_candidate' => $eligibleAt !== null && $evaluatedAt->greaterThanOrEqualTo($eligibleAt),
            'result_code' => $result,
            'blocker_codes' => $blockers,
            'approval_dependencies' => array_values(array_filter(['legal', 'accounting', 'security'], fn (string $type): bool => in_array('BLOCKED_'.strtoupper($type === 'legal' ? 'legal_approval' : ($type === 'accounting' ? 'accounting_approval' : 'security_review')), $blockers, true))),
            'provider_state' => $input['provider_state'] ?? 'unknown',
            'coverage_state' => $coverage,
            'action' => 'REPORT_ONLY',
        ];
    }
}
