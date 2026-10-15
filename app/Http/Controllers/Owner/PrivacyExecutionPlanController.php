<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\PrivacyErasureExecutionPlan;
use App\Models\PrivacyRequest;
use App\Privacy\ErasureExecutionPlanService;
use App\Privacy\PrivacyErasureCircuitBreakerService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PrivacyExecutionPlanController extends Controller
{
    public function show(PrivacyRequest $privacyRequest, PrivacyErasureExecutionPlan $plan, PrivacyErasureCircuitBreakerService $breaker): View
    {
        abort_unless($plan->privacy_request_id === $privacyRequest->id, 404);
        $plan->load(['actions', 'approvedBy:id,name']);

        $circuitStatus = $breaker->status();

        return view('owner.privacy.execution-plan', compact('privacyRequest', 'plan', 'circuitStatus'));
    }

    public function create(Request $request, PrivacyRequest $privacyRequest, ErasureExecutionPlanService $plans): RedirectResponse
    {
        $actor = $request->user('owner');
        abort_unless($actor?->isOwner() && ! $actor->customer()->exists(), 403);
        $plan = $plans->createPreview($privacyRequest, $actor);

        return to_route('owner.privacy-execution-plans.show', [$privacyRequest, $plan])->with('status', 'A versioned, report-only execution preview was created. No data or provider state changed.');
    }

    public function approve(Request $request, PrivacyRequest $privacyRequest, PrivacyErasureExecutionPlan $plan, ErasureExecutionPlanService $plans): RedirectResponse
    {
        abort_unless($plan->privacy_request_id === $privacyRequest->id, 404);
        $actor = $request->user('owner');
        abort_unless($actor?->isOwner() && ! $actor->customer()->exists(), 403);
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'typed_confirmation' => ['required', 'string', 'max:240'],
            'preview_hash' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);
        $result = $plans->approve($plan, $actor, $data['current_password'], $data['typed_confirmation'], $data['preview_hash']);

        return to_route('owner.privacy-execution-plans.show', [$privacyRequest, $result])->with('status', $result->state?->value === 'invalidated'
            ? 'Preview invalidated because a bound source changed. Generate a new preview.'
            : 'Owner review recorded for this exact preview. Legal/accounting/provider blockers still prohibit execution.');
    }

    public function cancel(Request $request, PrivacyRequest $privacyRequest, PrivacyErasureExecutionPlan $plan, ErasureExecutionPlanService $plans): RedirectResponse
    {
        abort_unless($plan->privacy_request_id === $privacyRequest->id, 404);
        $actor = $request->user('owner');
        abort_unless($actor?->isOwner() && ! $actor->customer()->exists(), 403);
        $result = $plans->cancel($plan, $actor);

        return to_route('owner.privacy-execution-plans.show', [$privacyRequest, $result])->with('status', 'Plan cancelled before any irreversible action.');
    }
}
