<?php

namespace App\Http\Controllers\Operator;

use App\AdminAccess\AccessLevel;
use App\AdminAccess\ActorType;
use App\AdminAccess\AdminAccessRefused;
use App\AdminAccess\AdminAccessService;
use App\Http\Controllers\Controller;
use App\Models\SecurityIncident;
use App\Models\Website;
use App\Operator\Refusal;
use App\Security\IncidentService;
use App\Security\IncidentStatus;
use App\Security\SecurityRefused;
use App\Security\Severity;
use App\Websites\Actor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security incidents. Admin access for an investigation goes through the
 * existing AdminAccessService (maintenance level; full admin stays
 * customer-only unless explicitly enabled); backups through BackupService.
 */
class SecurityIncidentController extends Controller
{
    public function store(Request $request, Website $website, IncidentService $incidents): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'severity' => ['nullable', Rule::enum(Severity::class)],
            'findings' => ['nullable', 'array', 'max:100'],
            'findings.*' => ['uuid'],
        ]);

        // Only findings of this website can be linked.
        $findings = $website->securityFindings()->whereIn('uuid', $data['findings'] ?? [])->get()->all();
        $incident = $incidents->open($website, $data['title'], isset($data['severity']) ? Severity::from($data['severity']) : null, Actor::user($request->user())->label(), $findings);

        return redirect()->route('operator.websites.security.incidents.show', [$website, $incident])->with('status', __('operator.security_actions.incident_opened'));
    }

    public function show(Website $website, SecurityIncident $securityIncident): View
    {
        $incident = $securityIncident->load(['findings', 'events.actorUser', 'remediations.backup', 'scans']);
        $since = $incident->created_at;

        return view('operator.security.incident', [
            'website' => $website,
            'incident' => $incident,
            // Related records by time, from their own tables (not copied into the incident).
            'backups' => $website->backups()->where('created_at', '>=', $since)->latest('id')->limit(10)->get(),
            'accessRequests' => $website->adminAccessRequests()->with('requestedBy')->where('created_at', '>=', $since)->latest('id')->limit(10)->get(),
            'transitions' => $incident->status->transitions(),
        ]);
    }

    public function status(Request $request, Website $website, SecurityIncident $securityIncident, IncidentService $incidents): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(IncidentStatus::class)],
            'note' => ['nullable', 'string', 'max:2000'],
            'functional_check' => ['nullable', 'boolean'],
        ]);

        try {
            $incidents->transition($securityIncident, IncidentStatus::from($data['status']), Actor::user($request->user())->label(), $data['note'] ?? null, (bool) ($data['functional_check'] ?? false));
        } catch (SecurityRefused $e) {
            return back()->with('operator_error', Refusal::flash($e->errorCode));
        }

        return back()->with('status', __('operator.security_actions.incident_updated'));
    }

    public function note(Request $request, Website $website, SecurityIncident $securityIncident, IncidentService $incidents): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        $incidents->note($securityIncident, Actor::user($request->user())->label(), $data['note']);

        return back()->with('status', __('operator.security_actions.note_added'));
    }

    public function backup(Request $request, Website $website, SecurityIncident $securityIncident, IncidentService $incidents): RedirectResponse
    {
        $refusal = $incidents->startBackup($securityIncident, Actor::user($request->user())->label());

        return $refusal ? back()->with('operator_error', Refusal::flash($refusal)) : back()->with('status', __('operator.backups.queued'));
    }

    /** Maintenance access for the investigation, recorded on the incident. */
    public function adminAccess(Request $request, Website $website, SecurityIncident $securityIncident, AdminAccessService $access, IncidentService $incidents): Response
    {
        try {
            [$accessRequest, $token] = $access->request($website, $request->user(), ActorType::Operator, AccessLevel::Maintenance);
        } catch (AdminAccessRefused $e) {
            return back()->with('operator_error', Refusal::flash($e->errorCode));
        }

        $incidents->log($securityIncident, 'admin_access', Actor::user($request->user())->label(), ['request' => $accessRequest->uuid, 'level' => AccessLevel::Maintenance->value]);

        return response()->view('account.websites.admin-access-opening', [
            'website' => $website,
            'action' => $access->accessUrl($website),
            'requestUuid' => $accessRequest->uuid,
            'token' => $token,
        ])->withHeaders([
            'Cache-Control' => 'no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
        ]);
    }
}
