<?php

namespace App\Security;

use App\Models\SecurityFinding;
use App\Models\SecurityScan;
use App\Websites\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Correlates reported findings with what is already known, so a repeated
 * scan never duplicates a finding. Identity is website + fingerprint
 * (type, code, component, path).
 *
 *   new                       → open (occurrence 1)
 *   seen again                → last_seen_at, occurrence_count + 1
 *   resolved, seen again      → open again
 *   false positive / ignored  → stays, unless the evidence changed (file hash)
 *   quarantined / remediated  → open again: the problem is back
 *   not seen by a complete scan of its scanner → resolved
 */
final class FindingRecorder
{
    public function record(SecurityScan $scan, ReportedFinding $reported): SecurityFinding
    {
        return DB::transaction(function () use ($scan, $reported) {
            $finding = SecurityFinding::where('website_id', $scan->website_id)->where('fingerprint', $reported->fingerprint())->lockForUpdate()->first();
            $now = now();

            if (! $finding) {
                $finding = SecurityFinding::create([
                    'website_id' => $scan->website_id,
                    'first_scan_id' => $scan->id,
                    'last_scan_id' => $scan->id,
                    'type' => $reported->type,
                    'code' => $reported->code,
                    'severity' => $reported->severity,
                    'confidence' => $reported->confidence,
                    'status' => FindingStatus::Open,
                    'title' => $reported->title,
                    'description' => $reported->description,
                    'component' => $reported->component,
                    'path' => $reported->path,
                    'fingerprint' => $reported->fingerprint(),
                    'evidence' => $reported->evidence ?: null,
                    'occurrence_count' => 1,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);
                AuditLog::record($scan->website, 'security.finding_opened', data: ['finding' => $finding->uuid, 'type' => $finding->type->value, 'code' => $finding->code, 'severity' => $finding->severity?->value, 'confidence' => $finding->confidence->value]);

                return $finding;
            }

            $sameScan = $finding->last_scan_id === $scan->id;
            $evidenceChanged = isset($reported->evidence['sha256'], $finding->evidence['sha256']) && $reported->evidence['sha256'] !== $finding->evidence['sha256'];

            $reopen = ! $sameScan && match ($finding->status) {
                FindingStatus::Resolved, FindingStatus::Quarantined, FindingStatus::Remediated => true,
                FindingStatus::FalsePositive, FindingStatus::Ignored => $evidenceChanged,
                default => false,
            };

            $finding->forceFill([
                'last_scan_id' => $scan->id,
                'code' => $reported->code,
                'component' => $reported->component,
                'severity' => $reported->severity,
                // An operator's confirmation is never overwritten by a scanner's estimate.
                'confidence' => $finding->confidence === Confidence::Confirmed ? Confidence::Confirmed : $reported->confidence,
                'title' => $reported->title,
                'description' => $reported->description,
                'evidence' => $reported->evidence ?: null,
                'last_seen_at' => $now,
                'occurrence_count' => $finding->occurrence_count + ($sameScan ? 0 : 1),
            ] + ($reopen ? ['status' => FindingStatus::Open, 'resolved_at' => null, 'status_changed_at' => $now, 'status_changed_by_user_id' => null, 'status_note' => $evidenceChanged ? 'Reopened: the file changed.' : 'Reopened: seen again.'] : []))->save();

            if ($reopen) {
                AuditLog::record($scan->website, 'security.finding_opened', data: ['finding' => $finding->uuid, 'type' => $finding->type->value, 'code' => $finding->code, 'reopened' => true]);
            }

            return $finding;
        });
    }

    /**
     * A complete run of a scanner no longer sees these: they are resolved,
     * not deleted. Operator decisions and quarantined files keep their status.
     *
     * @param  list<FindingType>  $types
     */
    public function resolveMissing(SecurityScan $scan, array $types): int
    {
        if ($types === []) {
            return 0;
        }

        return SecurityFinding::where('website_id', $scan->website_id)
            ->whereIn('type', array_map(fn (FindingType $t) => $t->value, $types))
            ->whereIn('status', [FindingStatus::Open->value, FindingStatus::Confirmed->value])
            ->where(fn ($q) => $q->whereNull('last_scan_id')->orWhere('last_scan_id', '!=', $scan->id))
            ->update(['status' => FindingStatus::Resolved->value, 'resolved_at' => now(), 'status_changed_at' => now(), 'status_changed_by_user_id' => null, 'status_note' => 'No longer found by a complete scan.', 'updated_at' => now()]);
    }
}
