<?php

namespace App\Http\Controllers\Account;

use App\Backups\BackupObjectName;
use App\Backups\BackupPolicy;
use App\Backups\BackupReason;
use App\Backups\BackupRefused;
use App\Backups\BackupService;
use App\Backups\BackupStatus;
use App\Backups\BackupText;
use App\Backups\BackupType;
use App\Backups\CustomerDownloads\BackupDownloadGrants;
use App\Backups\RestoreService;
use App\Compatibility\CapabilityReadiness;
use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Models\BackupObject;
use App\Models\Website;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Backup history, "Backup now" and restore requests in the customer area. */
class BackupController extends Controller
{
    public function index(Request $request, Website $website, BackupPolicy $policy, CapabilityReadiness $readiness): View
    {
        $this->authorize('view', $website);

        $backups = $website->backups()->with(['objects', 'objectDeletions'])->latest('id')->paginate(25);
        $downloadsEnabled = app(BackupDownloadGrants::class)->enabled();
        $downloadableObjects = [];

        // This controls presentation only. The grant endpoint repeats all
        // tenant, service, deletion, integrity and storage checks.
        if ($downloadsEnabled && $website->allowsServiceOperations()) {
            foreach ($backups->getCollection() as $backup) {
                if ($backup->status !== BackupStatus::Verified || ! $backup->isRestorable()) {
                    continue;
                }

                $deletedObjects = $backup->objectDeletions->pluck('object_name')->all();
                foreach ($backup->objects as $object) {
                    $name = BackupObjectName::tryFrom((string) $object->name);
                    $isExpectedObject = $name === BackupObjectName::Manifest
                        || ($name !== null && in_array($name->value, $backup->type->objects(), true));

                    if ($isExpectedObject && $object->status === BackupObject::VERIFIED
                        && $object->verified_at !== null && $object->size > 0
                        && preg_match('/\A[a-f0-9]{64}\z/i', (string) $object->sha256) === 1
                        && ! in_array($object->name, $deletedObjects, true)) {
                        $downloadableObjects[$object->id] = true;
                    }
                }
            }
        }

        return view('account.websites.backups', [
            'website' => $website,
            'backups' => $backups,
            'downloadsEnabled' => $downloadsEnabled,
            'downloadableObjects' => $downloadableObjects,
            'restores' => $website->restores()->with('backup')->latest('id')->limit(5)->get(),
            // Backup activity only; admin access and connector events have their own places.
            'events' => $website->events()->where(fn ($q) => $q->where('event', 'like', 'backup.%')->orWhere('event', 'like', 'restore.%')->orWhere('event', 'like', 'update.%'))
                ->latest('id')->limit(15)->get(),
            'policy' => $policy->for($website),
            'restoreIsolationSupported' => $readiness->restoreIsolationSupported($website),
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
