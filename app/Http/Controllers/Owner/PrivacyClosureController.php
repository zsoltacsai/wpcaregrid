<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Owner\AdminAudit;
use App\Privacy\AccountClosureImpactPlanner;
use App\Privacy\PrivacyRequestService;
use App\Privacy\PrivacyRequestSource;
use App\Privacy\PrivacyRequestType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Owner-only request recording and aggregate-only impact preview; no customer data is changed. */
final class PrivacyClosureController extends Controller
{
    public function create(): View
    {
        return view('owner.privacy.closure');
    }

    public function preview(Request $request, AccountClosureImpactPlanner $planner): View
    {
        $data = $request->validate(['customer_uuid' => ['required', 'uuid', 'exists:customers,uuid']]);
        $customer = Customer::query()->where('uuid', $data['customer_uuid'])->firstOrFail();
        $preview = $planner->preview($customer);
        $actor = $request->user('owner');

        AdminAudit::record($actor, 'privacy.closure.impact_previewed', 'customer', $customer->uuid, [
            'coverage' => $preview['coverage'],
            'technical_backup_candidates' => $preview['customer_backups']['technical_candidates'] ?? 0,
            'blocker_count' => count($preview['profile_blockers']) + count($preview['customer_backups']['blockers'] ?? []),
        ]);

        return view('owner.privacy.closure', compact('preview'));
    }

    public function store(Request $request, PrivacyRequestService $privacyRequests): RedirectResponse
    {
        $data = $request->validate([
            'customer_uuid' => ['required', 'uuid', 'exists:customers,uuid'],
            'request_type' => ['required', 'in:account_closure,erasure'],
        ]);
        $customer = Customer::query()->where('uuid', $data['customer_uuid'])->firstOrFail();
        $actor = $request->user('owner');
        abort_unless($actor?->isOwner() && ! $actor->customer()->exists(), 403);

        $type = PrivacyRequestType::from($data['request_type']);
        $case = $privacyRequests->create($customer, $type, PrivacyRequestSource::OwnerConsole, (string) Str::uuid(), $actor);

        return redirect()->route('owner.privacy-requests.show', $case)
            ->with('status', $type === PrivacyRequestType::AccountClosure
                ? 'Account closure case recorded. Review its detail page to restrict customer-area access after active subscriptions have ended; erasure remains a separate request.'
                : 'Erasure request recorded for review. Cooling-off and approval gates apply; no data was changed.');
    }
}
