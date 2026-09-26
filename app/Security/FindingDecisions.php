<?php

namespace App\Security;

use App\Models\SecurityFinding;
use App\Models\User;
use App\Operator\Permission;
use App\Websites\Actor;
use App\Websites\AuditLog;

/**
 * Operator decisions on findings: confirm, false positive, ignore, reopen.
 * Only operators with security.manage; never "remediated" or "quarantined"
 * by hand (those come from completed remediations), and customers have no
 * way to change a finding at all.
 */
final class FindingDecisions
{
    public function __construct(private readonly SecurityPosture $posture) {}

    /** @throws SecurityRefused */
    public function decide(SecurityFinding $finding, FindingStatus $status, User $by, ?string $note = null): void
    {
        if (! $by->hasOperatorPermission(Permission::SecurityManage)) {
            throw new SecurityRefused('NOT_AUTHORIZED');
        }

        if (! $finding->status->operatorMayChangeTo($status)) {
            throw new SecurityRefused('INVALID_TRANSITION');
        }

        $from = $finding->status;
        $finding->forceFill([
            'status' => $status,
            // Confirming is the only way anything becomes "confirmed"; un-confirming falls back to high.
            'confidence' => match (true) {
                $status === FindingStatus::Confirmed => Confidence::Confirmed,
                $finding->confidence === Confidence::Confirmed => Confidence::High,
                default => $finding->confidence,
            },
            'resolved_at' => null,
            'status_changed_at' => now(),
            'status_changed_by_user_id' => $by->id,
            'status_note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
        ])->save();

        AuditLog::record($finding->website, $status === FindingStatus::Confirmed ? 'security.finding_confirmed' : 'security.finding_status', actor: Actor::user($by)->label(), data: ['finding' => $finding->uuid, 'code' => $finding->code, 'from' => $from->value, 'to' => $status->value]);
        foreach ($finding->incidents()->whereNotIn('status', [IncidentStatus::Resolved->value, IncidentStatus::Closed->value])->get() as $incident) {
            app(IncidentService::class)->log($incident, 'finding', Actor::user($by)->label(), ['finding' => $finding->uuid, 'from' => $from->value, 'to' => $status->value]);
        }

        $this->posture->refresh($finding->website);
    }
}
