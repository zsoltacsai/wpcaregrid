<?php

namespace App\Monitoring;

use App\Connector\HealthStatus;
use App\Models\MonitoringAlertDelivery;
use App\Models\MonitoringIncident;
use App\Models\MonitoringMaintenanceWindow;
use App\Models\MonitoringResult;
use App\Models\MonitoringTransition;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Persists bounded observations and opens/resolves incidents deterministically. */
final class MonitoringEngine
{
    public function record(MonitoringObservation $observation): MonitoringResult
    {
        if (! MonitoringReason::valid($observation->reasonCode)) {
            throw new \InvalidArgumentException('Unknown monitoring reason code.');
        }

        if ($observation->freshnessSeconds < 1 || $observation->failureThreshold < 1 || $observation->recoveryThreshold < 1) {
            throw new \InvalidArgumentException('Monitoring thresholds must be positive.');
        }
        if ($observation->nextCheckSeconds !== null && $observation->nextCheckSeconds < 1) {
            throw new \InvalidArgumentException('Monitoring check cadence must be positive.');
        }
        if ($observation->checkId === '' || strlen($observation->checkId) > 96 || $observation->subjectType === '' || strlen($observation->subjectType) > 48 || $observation->subjectId === '' || strlen($observation->subjectId) > 64 || strlen($observation->source) > 32 || $observation->correlationKey !== null && strlen($observation->correlationKey) > 128) {
            throw new \InvalidArgumentException('Monitoring identity fields exceed the supported bounds.');
        }

        MonitoringEvidence::assertSafe($observation->evidence);

        return DB::transaction(function () use ($observation): MonitoringResult {
            $identity = $this->identity($observation);
            // insertOrIgnore plus a unique identity row gives MySQL a serialization point
            // even when two queue workers evaluate a check for the first time together.
            DB::table('monitoring_results')->insertOrIgnore($identity + [
                'state' => HealthStatus::Unknown->value,
                'reason_code' => MonitoringReason::MONITORING_STALE,
                'source' => $observation->source,
                'evidence' => json_encode([]),
                'observed_at' => now(),
                'fresh_until' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $result = MonitoringResult::query()
                ->where($identity)
                ->lockForUpdate()
                ->firstOrFail();

            $was = $result->state;
            $isFailure = $observation->state === HealthStatus::Attention || $observation->state === HealthStatus::Critical;
            $failures = $isFailure ? $result->consecutive_failures + 1 : 0;
            $successes = $observation->state === HealthStatus::Healthy ? $result->consecutive_successes + 1 : 0;
            $effectiveState = $observation->state;
            $activeIncident = MonitoringIncident::query()
                ->where('incident_key', $this->incidentKey($observation))
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if ($isFailure && ! $activeIncident && $failures < $observation->failureThreshold) {
                // A single failed sample is visible as attention, but a critical
                // state and incident require the signal-specific confirmation.
                $effectiveState = HealthStatus::Attention;
            }
            if ($activeIncident && $observation->state === HealthStatus::Healthy && $successes < $observation->recoveryThreshold) {
                $effectiveState = $was === HealthStatus::Critical ? HealthStatus::Critical : HealthStatus::Attention;
            }
            if ($activeIncident && $isFailure) {
                $incidentSeverity = $activeIncident->severity;
                $incomingSeverity = $observation->severity ?? $incidentSeverity;
                $effectiveSeverityForIncident = $this->severityRank($incomingSeverity) > $this->severityRank($incidentSeverity)
                    ? $incomingSeverity
                    : $incidentSeverity;
                $effectiveState = $effectiveSeverityForIncident === MonitoringSeverity::Critical
                    ? HealthStatus::Critical
                    : HealthStatus::Attention;
            }
            $effectiveSeverity = $isFailure && ! $activeIncident && $failures < $observation->failureThreshold
                ? MonitoringSeverity::Warning
                : ($activeIncident && $isFailure ? $effectiveSeverityForIncident : $observation->severity);

            $result->forceFill([
                'state' => $effectiveState,
                'severity' => $effectiveSeverity,
                'reason_code' => $observation->reasonCode,
                'source' => $observation->source,
                'evidence' => $observation->evidence,
                'source_at' => $observation->sourceAt,
                'observed_at' => now(),
                'fresh_until' => now()->addSeconds($observation->freshnessSeconds),
                'next_check_at' => now()->addSeconds($observation->nextCheckSeconds ?? $observation->freshnessSeconds),
                'consecutive_failures' => $failures,
                'consecutive_successes' => $successes,
                'correlation_key' => $observation->correlationKey,
            ])->save();

            if ($isFailure && $failures >= $observation->failureThreshold) {
                $incident = $activeIncident;
                $event = 'observed';
                if (! $incident) {
                    $generation = ((int) MonitoringIncident::query()
                        ->where('incident_key', $this->incidentKey($observation))
                        ->max('generation')) + 1;
                    $incident = MonitoringIncident::query()->create([
                        'incident_key' => $this->incidentKey($observation),
                        'generation' => $generation,
                        'check_id' => $observation->checkId,
                        'scope' => $observation->scope,
                        'subject_type' => $observation->subjectType,
                        'subject_id' => $observation->subjectId,
                        'severity' => $observation->severity ?? MonitoringSeverity::Warning,
                        'reason_code' => $observation->reasonCode,
                        'status' => 'open',
                        'correlation_key' => $observation->correlationKey,
                        'evidence' => $observation->evidence,
                        'opened_at' => now(),
                        'last_observed_at' => now(),
                        'observation_count' => 1,
                    ]);
                    $event = 'opened';
                } else {
                    $previousSeverity = $incident->severity;
                    $incomingSeverity = $observation->severity ?? $previousSeverity;
                    $severity = $this->severityRank($incomingSeverity) > $this->severityRank($previousSeverity)
                        ? $incomingSeverity
                        : $previousSeverity;
                    $incident->forceFill([
                        'last_observed_at' => now(),
                        'observation_count' => $incident->observation_count + 1,
                        'evidence' => $observation->evidence,
                        'reason_code' => $observation->reasonCode,
                        'correlation_key' => $observation->correlationKey,
                        'severity' => $severity,
                    ])->save();
                    if ($previousSeverity !== $severity) {
                        $event = 'escalated';
                    } elseif ($incident->opened_at?->lessThanOrEqualTo(now()->subMinutes((int) config('monitoring.confirmation.repeat_after_minutes', 30)))
                        && ! $incident->transitions()->where('event_type', 'escalated')->exists()) {
                        $event = 'escalated';
                    }
                }

                if (in_array($event, ['opened', 'escalated'], true)) {
                    $suppressionReason = $this->deliverySuppressionReason($observation, $event);
                    $transition = $this->transition($incident, $result, $observation, $was, $effectiveState, $event, $suppressionReason);
                    if ($observation->notify && $suppressionReason === null) {
                        $this->queueOwnerDeliveries($incident, $transition);
                    }
                }
            } elseif ($activeIncident && $observation->state === HealthStatus::Healthy && $successes >= $observation->recoveryThreshold) {
                $activeIncident->forceFill([
                    'status' => 'resolved',
                    'resolved_at' => now(),
                    'resolution_reason' => 'monitoring.recovered',
                    'last_observed_at' => now(),
                ])->save();
                $suppressionReason = $this->deliverySuppressionReason($observation, 'resolved');
                $transition = $this->transition($activeIncident, $result, $observation, $was, HealthStatus::Healthy, 'resolved', $suppressionReason);
                if ($observation->notify && $suppressionReason === null) {
                    $this->queueOwnerDeliveries($activeIncident, $transition);
                }
            } elseif ($activeIncident) {
                $activeIncident->forceFill([
                    'last_observed_at' => now(),
                    'observation_count' => $activeIncident->observation_count + 1,
                    'evidence' => $observation->evidence,
                ])->save();
            }

            return $result->refresh();
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    private function identity(MonitoringObservation $observation): array
    {
        return [
            'check_id' => $observation->checkId,
            'scope' => $observation->scope->value,
            'subject_type' => $observation->subjectType,
            'subject_id' => $observation->subjectId,
        ];
    }

    private function incidentKey(MonitoringObservation $observation): string
    {
        return hash('sha256', implode('|', [$observation->checkId, $observation->scope->value, $observation->subjectType, $observation->subjectId]));
    }

    private function severityRank(MonitoringSeverity $severity): int
    {
        return match ($severity) {
            MonitoringSeverity::Info => 1,
            MonitoringSeverity::Warning => 2,
            MonitoringSeverity::Critical => 3,
        };
    }

    private function inMaintenance(MonitoringObservation $observation): bool
    {
        return MonitoringMaintenanceWindow::query()
            ->where('scope', $observation->scope->value)
            ->where(fn ($query) => $query->whereNull('subject_type')->orWhere('subject_type', $observation->subjectType))
            ->where(fn ($query) => $query->whereNull('subject_id')->orWhere('subject_id', $observation->subjectId))
            ->where(fn ($query) => $query->whereNull('check_family')->orWhere('check_family', str($observation->checkId)->before('.')->toString()))
            ->whereNull('ended_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->exists();
    }

    private function deliverySuppressionReason(MonitoringObservation $observation, string $event): ?string
    {
        if ($this->inMaintenance($observation)) {
            return 'maintenance';
        }
        if ($event !== 'opened') {
            return null;
        }

        $repeatAfter = max(1, (int) config('monitoring.confirmation.repeat_after_minutes', 30));
        $recentOpen = MonitoringTransition::query()
            ->where('check_id', $observation->checkId)
            ->where('scope', $observation->scope->value)
            ->where('subject_type', $observation->subjectType)
            ->where('subject_id', $observation->subjectId)
            ->where('event_type', 'opened')
            ->where('created_at', '>=', now()->subMinutes($repeatAfter))
            ->exists();

        return $recentOpen ? 'cooldown' : null;
    }

    private function transition(MonitoringIncident $incident, MonitoringResult $result, MonitoringObservation $observation, ?HealthStatus $from, HealthStatus $to, string $event, ?string $suppressionReason): MonitoringTransition
    {
        return MonitoringTransition::query()->create([
            'incident_id' => $incident->id,
            'monitoring_result_id' => $result->id,
            'event_type' => $event,
            'check_id' => $observation->checkId,
            'scope' => $observation->scope->value,
            'subject_type' => $observation->subjectType,
            'subject_id' => $observation->subjectId,
            'from_state' => $from?->value,
            'to_state' => $to->value,
            'reason_code' => $observation->reasonCode,
            'severity' => $observation->severity?->value,
            'source' => $observation->source,
            'evidence' => array_merge($observation->evidence, [
                'delivery_suppressed' => $suppressionReason !== null,
                'suppression_reason' => $suppressionReason,
            ]),
            'created_at' => now(),
        ]);
    }

    private function queueOwnerDeliveries(MonitoringIncident $incident, MonitoringTransition $transition): void
    {
        $owners = User::query()
            ->where('role', User::ROLE_OWNER)
            ->whereNotNull('email_verified_at')
            ->get(['id']);

        foreach ($owners as $owner) {
            foreach (['in_app', 'email'] as $channel) {
                $key = hash('sha256', implode('|', [$transition->id, $channel, $owner->id]));
                MonitoringAlertDelivery::query()->firstOrCreate(
                    ['idempotency_key' => $key],
                    [
                        'incident_id' => $incident->id,
                        'transition_id' => $transition->id,
                        'recipient_user_id' => $owner->id,
                        'channel' => $channel,
                        'status' => $channel === 'in_app' ? 'sent' : 'pending',
                    ],
                );
            }
        }

        if ($incident->scope === MonitoringScope::Website && $incident->severity === MonitoringSeverity::Critical) {
            $operators = User::query()
                ->where('role', User::ROLE_OPERATOR)
                ->whereNull('operator_disabled_at')
                ->whereNotNull('email_verified_at')
                ->whereJsonContains('operator_permissions', 'websites.view')
                ->get(['id']);

            foreach ($operators as $operator) {
                $key = hash('sha256', implode('|', [$transition->id, 'email', $operator->id]));
                MonitoringAlertDelivery::query()->firstOrCreate(
                    ['idempotency_key' => $key],
                    [
                        'incident_id' => $incident->id,
                        'transition_id' => $transition->id,
                        'recipient_user_id' => $operator->id,
                        'channel' => 'email',
                        'status' => 'pending',
                    ],
                );
            }
        }
    }
}
