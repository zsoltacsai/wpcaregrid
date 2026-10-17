<?php

namespace App\Http\Controllers\Owner;

use App\Backups\BackupEntitlementCatalog;
use App\Backups\OwnerBackupProtectionService;
use App\Http\Controllers\Controller;
use App\Models\BackupRetentionPolicyPreview;
use App\Models\Subscription;
use App\Models\User;
use App\Pricing\PlanTier;
use App\Pricing\ProductType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class BackupRetentionPolicyController extends Controller
{
    public function index(BackupEntitlementCatalog $catalog): View
    {
        $subscriptions = Subscription::query()
            ->with(['customer', 'website', 'backupEntitlements', 'backupOwnerPolicy'])
            ->whereHas('website')
            ->latest('id')
            ->paginate(25);
        $targetPolicies = [];
        foreach (ProductType::cases() as $product) {
            foreach (PlanTier::cases() as $tier) {
                $targetPolicies[] = $catalog->for($product, $tier);
            }
        }

        return view('owner.backup-policies.index', compact('subscriptions', 'targetPolicies'));
    }

    public function preview(Request $request, Subscription $subscription, OwnerBackupProtectionService $policies): RedirectResponse
    {
        $actor = $this->owner($request);
        $data = $request->validate([
            'daily_recovery_points' => ['required', 'integer', 'min:0', 'max:365'],
            'weekly_recovery_points' => ['required', 'integer', 'min:0', 'max:520'],
            'monthly_recovery_points' => ['required', 'integer', 'min:0', 'max:240'],
            'desired_post_service_days' => ['required', 'integer', 'min:0', 'max:3650'],
        ]);
        $data = array_map(static fn (string|int $value): int => (int) $value, $data);
        $preview = $policies->preview($subscription, $actor, $data);

        return to_route('owner.backup-policies.previews.show', $preview)->with('status', 'Impact preview created. It does not authorize or perform backup deletion.');
    }

    public function showPreview(Request $request, BackupRetentionPolicyPreview $preview): View
    {
        $this->owner($request);
        $preview->load('subscription.website');

        return view('owner.backup-policies.preview', compact('preview'));
    }

    public function confirm(Request $request, BackupRetentionPolicyPreview $preview, OwnerBackupProtectionService $policies): RedirectResponse
    {
        $actor = $this->owner($request);
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'preview_hash' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);
        $result = $policies->confirm($preview, $actor, $data['current_password'], $data['preview_hash']);

        return to_route('owner.backup-policies.index')->with('status', $result['stale']
            ? 'Preview is stale or expired. Generate a new preview; no policy change was applied.'
            : 'Owner protection policy saved. This is non-destructive; cleanup remains blocked by WAIT_LEGAL, WAIT_ACCOUNTING and WAIT_FACT.');
    }

    private function owner(Request $request): User
    {
        $actor = $request->user('owner');
        abort_unless($actor instanceof User && $actor->isOwner() && ! $actor->customer()->exists(), 403);

        return $actor;
    }
}
