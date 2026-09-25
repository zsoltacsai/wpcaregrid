<?php

namespace App\Http\Controllers\Account;

use App\Backups\BackupPolicy;
use App\Backups\BackupReason;
use App\Backups\BackupRefused;
use App\Backups\BackupService;
use App\Backups\BackupText;
use App\Backups\BackupType;
use App\Backups\RestoreService;
use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Models\Website;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Backup history, "Backup now" and restore requests in the customer area. */
class BackupController extends Controller
{
    public function index(Request $request, Website $website, BackupPolicy $policy): View
    {
        $this->authorize('view', $website);

        return view('account.websites.backups', [
            'website' => $website,
            'backups' => $website->backups()->latest('id')->paginate(25),
            'restores' => $website->restores()->with('backup')->latest('id')->limit(5)->get(),
            'events' => $website->events()->latest('id')->limit(15)->get(),
            'policy' => $policy->for($website),
            'canBackup' => $request->user()->can('backup', $website),
        ]);
    }

    /** "Backup now": queued, never run inside this request. */
    public function store(Request $request, Website $website, BackupService $backups): RedirectResponse
    {
        $this->authorize('backup', $website);

        try {
            $backups->request($website, BackupReason::Manual, 'customer:'.$request->user()->id);
        } catch (BackupRefused $e) {
            return back()->with('backup_error', BackupText::refusal($e->errorCode));
        }

        return back()->with('status', __('backups.account.queued'));
    }

    public function restoreForm(Request $request, Website $website, Backup $backup, RestoreService $restores): View
    {
        $this->authorize('restore', $website);

        $scopes = array_values(array_filter(BackupType::cases(), fn (BackupType $scope) => $backup->type->canRestore($scope)));

        return view('account.websites.restore', [
            'website' => $website,
            'backup' => $backup,
            'scopes' => $scopes,
            'refusal' => $restores->refusal($website, $backup, $scopes[0]),
        ]);
    }

    public function restore(Request $request, Website $website, Backup $backup, RestoreService $restores): RedirectResponse
    {
        $this->authorize('restore', $website);

        $data = $request->validate([
            'scope' => ['required', Rule::enum(BackupType::class)],
            'confirmation' => ['required', 'string', 'max:255'],
            'understood' => ['accepted'],
        ]);

        try {
            $restores->request($website, $backup, BackupType::from($data['scope']), 'customer:'.$request->user()->id, $data['confirmation']);
        } catch (BackupRefused $e) {
            return back()->withInput()->with('backup_error', BackupText::refusal($e->errorCode));
        }

        return redirect()->route('account.websites.backups.index', $website)->with('status', __('backups.account.restore_requested'));
    }
}
