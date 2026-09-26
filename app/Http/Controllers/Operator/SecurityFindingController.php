<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\SecurityFinding;
use App\Models\SecurityQuarantine;
use App\Models\SecurityScan;
use App\Models\Website;
use App\Operator\Refusal;
use App\Security\FindingDecisions;
use App\Security\FindingStatus;
use App\Security\FindingType;
use App\Security\RemediationService;
use App\Security\RemediationType;
use App\Security\ScanType;
use App\Security\SecurityRefused;
use App\Security\SecurityScanService;
use App\Websites\Actor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A website's security in the operator console: scans, findings, finding
 * decisions, remediation and quarantine. The services decide; this only
 * passes the operator's request on.
 */
class SecurityFindingController extends Controller
{
    public function index(Request $request, Website $website): View
    {
        $status = FindingStatus::tryFrom((string) $request->query('status'));
        $type = FindingType::tryFrom((string) $request->query('type'));

        return view('operator.security.website', [
            'website' => $website->load(['activeConnector', 'customer']),
            'findings' => $website->securityFindings()
                ->when($status, fn ($q) => $q->where('status', $status), fn ($q) => $q->active())
                ->when($type, fn ($q) => $q->where('type', $type))
                ->orderByRaw("case severity when 'critical' then 4 when 'high' then 3 when 'medium' then 2 when 'low' then 1 else 0 end desc")
                ->latest('last_seen_at')->paginate(50)->withQueryString(),
            'scans' => $website->securityScans()->latest('id')->limit(10)->get(),
            'incidents' => $website->securityIncidents()->latest('id')->limit(10)->get(),
            'quarantines' => $website->securityQuarantines()->latest('id')->limit(20)->get(),
            'remediations' => $website->securityRemediations()->with('finding')->latest('id')->limit(20)->get(),
            'status' => $status,
            'type' => $type,
        ]);
    }

    public function scan(Request $request, Website $website, SecurityScanService $scans): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', Rule::enum(ScanType::class)]]);

        try {
            $scans->request($website->load(['activeConnector', 'subscription', 'customer', 'inventory']), ScanType::from($data['type']), Actor::user($request->user())->label());
        } catch (SecurityRefused $e) {
            return back()->with('operator_error', Refusal::flash($e->errorCode));
        }

        return back()->with('status', __('operator.security_actions.scan_requested'));
    }

    public function cancel(Request $request, Website $website, SecurityScan $securityScan, SecurityScanService $scans): RedirectResponse
    {
        $scans->cancel($securityScan, Actor::user($request->user())->label());

        return back()->with('status', __('operator.security_actions.scan_cancelled'));
    }

    public function decide(Request $request, Website $website, SecurityFinding $securityFinding, FindingDecisions $decisions): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_map(fn (FindingStatus $s) => $s->value, FindingStatus::settableByOperator()))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $decisions->decide($securityFinding, FindingStatus::from($data['status']), $request->user(), $data['note'] ?? null);
        } catch (SecurityRefused $e) {
            return back()->with('operator_error', Refusal::flash($e->errorCode));
        }

        return back()->with('status', __('operator.security_actions.finding_updated'));
    }

    public function remediate(Request $request, Website $website, SecurityFinding $securityFinding, RemediationService $remediations): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_map(fn (RemediationType $t) => $t->value, array_filter(RemediationType::cases(), fn ($t) => $t !== RemediationType::RestoreQuarantinedFile)))],
            'confirm' => ['accepted'],
        ]);

        try {
            $remediations->request($securityFinding, RemediationType::from($data['type']), $request->user());
        } catch (SecurityRefused $e) {
            return back()->with('operator_error', Refusal::flash($e->errorCode));
        }

        return back()->with('status', __('operator.security_actions.remediation_requested'));
    }

    public function restoreQuarantine(Request $request, Website $website, SecurityQuarantine $securityQuarantine, RemediationService $remediations): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']]);

        if (! $securityQuarantine->finding) {
            return back()->with('operator_error', Refusal::flash('NOT_QUARANTINED'));
        }

        try {
            $remediations->restoreQuarantine($securityQuarantine, $request->user());
        } catch (SecurityRefused $e) {
            return back()->with('operator_error', Refusal::flash($e->errorCode));
        }

        return back()->with('status', __('operator.security_actions.restore_requested'));
    }
}
