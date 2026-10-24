<?php

namespace App\Http\Controllers\Owner;

use App\Billing\InternalPlan;
use App\Billing\InternalPlans;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Subscription;
use App\Pricing\ProductType;
use App\Support\DisplayDateTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PROBONO (docs/PROBONO.md): assigned to a customer by the owner, never
 * bought. The customer comes from the URL and the subscription is resolved
 * through that customer (scoped binding), so one customer's plan can never be
 * changed through another's page. The plan itself is fixed here: no request
 * field can name a different plan, a price or a Stripe ID.
 */
class InternalPlanController extends Controller
{
    public function store(Request $request, Customer $customer, InternalPlans $plans, DisplayDateTime $displayDateTime): RedirectResponse
    {
        $data = $request->validate([
            'product_type' => ['required', Rule::enum(ProductType::class)],
            ...$this->dateRules(),
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => __('owner.probono.confirm')]);

        $plans->assign(
            $customer,
            InternalPlan::Probono,
            ProductType::from($data['product_type']),
            $this->date($data['starts_at'] ?? null, $displayDateTime),
            $this->date($data['ends_at'] ?? null, $displayDateTime),
            filled($data['note'] ?? null) ? $data['note'] : null,
            $request->user(),
        );

        return redirect()->route('owner.customers.show', $customer)->with('status', __('owner.probono.assigned', ['name' => $customer->fullName()]));
    }

    public function update(Request $request, Customer $customer, Subscription $subscription, InternalPlans $plans, DisplayDateTime $displayDateTime): RedirectResponse
    {
        $data = $request->validate($this->dateRules());

        $updated = $plans->update($subscription, $this->date($data['starts_at'] ?? null, $displayDateTime), $this->date($data['ends_at'] ?? null, $displayDateTime), filled($data['note'] ?? null) ? $data['note'] : null, $request->user());

        return redirect()->route('owner.customers.show', $customer)->with('status', $updated->wasChanged() ? __('owner.probono.saved') : __('owner.probono.unchanged'));
    }

    public function end(Request $request, Customer $customer, Subscription $subscription, InternalPlans $plans): RedirectResponse
    {
        $plans->end($subscription, $request->user());

        return redirect()->route('owner.customers.show', $customer)->with('status', __('owner.probono.ended'));
    }

    /** @return array<string, mixed> */
    private function dateRules(): array
    {
        return [
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function date(?string $value, DisplayDateTime $displayDateTime): ?\Illuminate\Support\Carbon
    {
        return filled($value) ? $displayDateTime->parseLocal($value) : null;
    }
}
