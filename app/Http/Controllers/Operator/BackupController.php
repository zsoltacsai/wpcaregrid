<?php

namespace App\Http\Controllers\Operator;

use App\Backups\BackupReason;
use App\Backups\BackupRefused;
use App\Backups\BackupService;
use App\Backups\BackupStatus;
use App\Backups\BackupType;
use App\Backups\RestoreService;
use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Models\Website;
use App\Operator\ConnectorState;
use App\Operator\Refusal;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Backups in the operator console. Requests and restores go through
 * BackupService and RestoreService, with the same checks and safety
 * backup as in the customer area; nothing here stores or moves data.
 */
class BackupController extends Controller
{
    public function index(Request $request): View
    {
        $status = BackupStatus::tryFrom((string) $request->query('status'));
        $reason = BackupReason::tryFrom((string) $request->query('reason'));

        return view('operator.backups.index', [
            'backups' => Backup::with('website')
                ->when($status, fn ($q) => $q->where('status', $status))
                ->when($reason, fn ($q) => $q->where('reason', $reason))
                ->latest('id')->paginate(30)->withQueryString(),
            'status' => $status,
            'reason' => $reason,
        ]);
    }

    public function store(Request $request, Website $website, BackupService $backups): RedirectResponse
    {
        try {
            $backups->request($website, BackupReason::Manual, 'operator:'.$request->user()->id);
        } catch (BackupRefused $e) {
            return back()->with('operator_error', Refusal::flash($e->errorCode));
        }

        return back()->with('status', __('operator.backups.queued'));
    }

    public function restoreForm(Website $website, Backup $backup, RestoreService $restores): View
    {
        $scopes = array_values(array_filter(BackupType::cases(), fn (BackupType $scope) => $backup->type->canRestore($scope)));
        $refusal = $restores->refusal($website, $backup, $scopes[0]);

        return view('operator.websites.restore', [
            'website' => $website,
            'backup' => $backup,
            'scopes' => $scopes,
            // The restore form is only offered while the connector is reporting in.
            'refusal' => $refusal ?? (ConnectorState::of($website) === ConnectorState::Connected ? null : 'NOT_CONNECTED'),
        ]);
    }

    public function restore(Request $request, Website $website, Backup $backup, RestoreService $restores): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', Rule::enum(BackupType::class)],
            'confirmation' => ['required', 'string', 'max:255'],
            'understood' => ['accepted'],
        ]);

        try {
            $restores->request($website, $backup, BackupType::from($data['scope']), 'operator:'.$request->user()->id, $data['confirmation']);
        } catch (BackupRefused $e) {
            return back()->withInput($request->except('confirmation'))->with('operator_error', Refusal::flash($e->errorCode));
        }

        return redirect()->to(route('operator.websites.show', $website).'#backups')->with('status', __('operator.backups.restore_requested'));
    }
}
