<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\BackupObjectDeletion;
use App\Models\PrivacyProviderTask;
use App\Models\PrivacyRetentionPolicy;
use App\Models\PrivacyRetentionPreview;
use App\Privacy\PrivacyProviderFactRegistry;
use App\Privacy\PrivacyRestoreSafety;
use App\Privacy\RetentionPolicyDefaults;
use App\Privacy\RetentionPolicyWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PrivacyRetentionController extends Controller
{
    public function index(PrivacyRestoreSafety $restoreSafety): View
    {
        $policies = PrivacyRetentionPolicy::query()->orderBy('policy_key')->orderByDesc('version')->get()
            ->groupBy('policy_key')->map(fn ($items) => $items->first());
        $versions = PrivacyRetentionPolicy::query()->orderBy('policy_key')->orderByDesc('version')->get()->groupBy('policy_key');
        $previews = PrivacyRetentionPreview::query()->with('policy')->latest()->limit(10)->get();

        return view('owner.privacy-retention.index', [
            'policies' => $policies,
            'versions' => $versions,
            'definitions' => RetentionPolicyDefaults::all(),
            'previews' => $previews,
            'destructiveEnabled' => false,
            'backupDeletionStates' => BackupObjectDeletion::query()->selectRaw('state, count(*) as total')->groupBy('state')->orderBy('state')->get(),
            'providerTaskStates' => PrivacyProviderTask::query()->selectRaw('provider, status, count(*) as total')->groupBy('provider', 'status')->orderBy('provider')->orderBy('status')->get(),
            'providerFacts' => PrivacyProviderFactRegistry::all(),
            'restoreSafety' => $restoreSafety->status(),
        ]);
    }

    public function draft(Request $request, string $policyKey, RetentionPolicyWorkflow $workflow): RedirectResponse
    {
        abort_unless(isset(RetentionPolicyDefaults::all()[$policyKey]), 404);
        $actor = $request->user('owner');
        $policy = $workflow->createDraft($policyKey, $request->input('value'), $actor);

        return to_route('owner.privacy-retention.index')->with('status', "Draft {$policyKey} v{$policy->version} created. Generate and review its impact preview before reporting activation.");
    }

    public function preview(Request $request, PrivacyRetentionPolicy $policy, RetentionPolicyWorkflow $workflow): RedirectResponse
    {
        abort_unless($policy->approval_status?->value === 'draft' || ! $policy->enabled, 404);
        $preview = $workflow->preview($policy, $request->user('owner'));

        return to_route('owner.privacy-retention.index')->with('status', "Preview {$preview->uuid} created; actual deletions: 0.");
    }

    public function activate(Request $request, PrivacyRetentionPolicy $policy, RetentionPolicyWorkflow $workflow): RedirectResponse
    {
        abort_unless($policy->approval_status?->value === 'draft' || ! $policy->enabled, 404);
        $request->validate(['preview_uuid' => ['required', 'uuid']]);
        $workflow->activateForReporting($policy, (string) $request->input('preview_uuid'), $request->user('owner'));

        return to_route('owner.privacy-retention.index')->with('status', "{$policy->policy_key} v{$policy->version} activated for REPORTING ONLY. No records were changed.");
    }
}
