<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\PrivacyRequest;
use App\Privacy\PrivacyProviderFactRegistry;
use Illuminate\Contracts\View\View;

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
}
