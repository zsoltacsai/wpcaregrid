<?php

namespace App\Http\Controllers\Owner;

use App\Billing\StagingBillingLifecycleHarness;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class M11LifecycleValidationController extends Controller
{
    public function index(StagingBillingLifecycleHarness $harness): View
    {
        return view('owner.billing.m11-lifecycle-validation', [
            'available' => $harness->available(),
            'scenarios' => StagingBillingLifecycleHarness::SCENARIOS,
            'confirmation' => StagingBillingLifecycleHarness::CONFIRMATION,
            'runs' => AdminAuditEvent::query()->where('event', 'billing.m11_validation_run')->latest('id')->limit(20)->get(),
        ]);
    }

    public function run(Request $request, StagingBillingLifecycleHarness $harness): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'scenario' => ['required', 'string', 'in:'.implode(',', StagingBillingLifecycleHarness::SCENARIOS)],
            'confirmation_phrase' => ['required', 'string', 'max:80'],
        ]);

        $result = $harness->run(
            $request->user('owner') ?? abort(403),
            $data['email'],
            $data['scenario'],
            $data['confirmation_phrase'],
        );

        return to_route('owner.billing.m11-lifecycle-validation.index')
            ->with('status', __('owner.m11_lifecycle_validation.completed', ['run' => $result['run_id'], 'result' => $result['result']]));
    }
}
