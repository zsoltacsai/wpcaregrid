<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\PrivacyRequest;
use App\Privacy\PrivacyProviderFactRegistry;
use App\Privacy\PrivacyRequestService;
use App\Privacy\PrivacyRequestStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Validation\Rule;

/** Read-only operational view of privacy foundation cases. */
final class PrivacyRequestController extends Controller
{
    public function index(): View
    {
        $requests = PrivacyRequest::query()
            ->with('customer:id,uuid')
            ->withCount('providerTasks')
            ->latest('requested_at')
            ->paginate(25);

        return view('owner.privacy.index', [
            'requests' => $requests,
            'providerFacts' => PrivacyProviderFactRegistry::all(),
        ]);
    }

    public function show(PrivacyRequest $privacyRequest): View
    {
        $privacyRequest->load([
            'customer:id,uuid',
            'providerTasks:id,uuid,privacy_request_id,provider,scope_code,status,attempts,error_category,last_attempted_at,reconciled_at',
            'events:id,privacy_request_id,event_type,from_status,to_status,metadata,created_at',
        ]);

        return view('owner.privacy.show', compact('privacyRequest'));
    }

    public function transition(HttpRequest $request, PrivacyRequest $privacyRequest, PrivacyRequestService $service): RedirectResponse
    {
        $safeStatuses = array_values(array_map(
            static fn (PrivacyRequestStatus $status) => $status->value,
            array_filter(PrivacyRequestStatus::cases(), static fn (PrivacyRequestStatus $status) => ! in_array($status, [PrivacyRequestStatus::Verified, PrivacyRequestStatus::Failed, PrivacyRequestStatus::Completed], true)),
        ));
        $data = $request->validate(['status' => ['required', Rule::in($safeStatuses)]]);
        $service->transition($privacyRequest, PrivacyRequestStatus::from($data['status']), $request->user('owner'));

        return redirect()->route('owner.privacy-requests.show', $privacyRequest)
            ->with('status', 'Case state recorded. No customer, provider, billing, website, or backup data was changed.');
    }

    public function restrictAccountAccess(HttpRequest $request, PrivacyRequest $privacyRequest, PrivacyRequestService $service): RedirectResponse
    {
        $actor = $request->user('owner');
        abort_unless($actor?->isOwner() && ! $actor->customer()->exists(), 403);
        $service->restrictAccountAccess($privacyRequest, $actor);

        return redirect()->route('owner.privacy-requests.show', $privacyRequest)
            ->with('status', 'Customer-area access is restricted. Billing and data are unchanged; erasure remains a separate request.');
    }

    public function restoreAccountAccess(HttpRequest $request, PrivacyRequest $privacyRequest, PrivacyRequestService $service): RedirectResponse
    {
        $actor = $request->user('owner');
        abort_unless($actor?->isOwner() && ! $actor->customer()->exists(), 403);
        $service->restoreAccountAccess($privacyRequest, $actor);

        return redirect()->route('owner.privacy-requests.show', $privacyRequest)
            ->with('status', 'Customer-area access is restored. No erasure or billing state was changed.');
    }
}
