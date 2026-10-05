<?php

namespace App\Http\Controllers\Owner;

use App\Checkout\Coupons;
use App\Checkout\PromotionGatewayException;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditEvent;
use App\Models\CheckoutIntent;
use App\Models\Promotion;
use App\Pricing\BillingInterval;
use App\Pricing\PlanTier;
use App\Pricing\ProductType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Coupons (docs/COUPONS.md). Thin: validation here, everything else in
 * App\Checkout\Coupons. The browser never supplies an amount, a currency or a
 * Stripe ID; the discount is a percentage the owner types, checked server-side.
 */
class CouponController extends Controller
{
    public function index(Request $request): View
    {
        $filter = in_array($request->query('state'), ['active', 'inactive'], true) ? $request->query('state') : null;

        return view('owner.coupons.index', [
            'coupons' => Promotion::query()
                ->when($filter === 'active', fn ($q) => $q->where('active', true))
                ->when($filter === 'inactive', fn ($q) => $q->where('active', false))
                ->orderByDesc('active')->latest('id')->paginate(25)->withQueryString(),
            'filter' => $filter,
        ]);
    }

    public function create(): View
    {
        return view('owner.coupons.create', $this->formData());
    }

    public function store(Request $request, Coupons $coupons): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{3,32}$/'],
            'percent_off' => ['required', 'string', fn ($attribute, $value, $fail) => Promotion::parsePercent($value) === null ? $fail(__('owner.coupons.errors.percent')) : null],
            'duration' => ['required', Rule::in(['once', 'repeating', 'forever'])],
            'duration_months' => ['nullable', 'required_if:duration,repeating', 'integer', 'between:1,36'],
            ...$this->editableRules(),
        ], ['code.regex' => __('owner.coupons.errors.code_format')]);

        $data['percent_off'] = Promotion::parsePercent($data['percent_off']);

        try {
            $promotion = $coupons->create($this->normalise($data), $request->user());
        } catch (PromotionGatewayException $e) {
            return back()->withInput()->with('owner_error', ['kind' => 'permission', 'message' => $e->ownerMessage(), 'code' => strtoupper($e->reason)]);
        }

        return redirect()->route('owner.coupons.show', $promotion)->with('status', __('owner.coupons.created', ['code' => $promotion->code]));
    }

    public function show(Promotion $coupon): View
    {
        return view('owner.coupons.show', $this->formData() + [
            'coupon' => $coupon,
            'stripeConfigured' => filled(config('services.stripe.secret')),
            'recent' => CheckoutIntent::where('promotion_id', $coupon->id)->latest('id')->limit(10)->get(['uuid', 'status', 'product_type', 'plan', 'interval', 'completed_at', 'created_at']),
            'audit' => AdminAuditEvent::with('actor')->where('subject_type', 'coupon')->where('subject_key', $coupon->code)->latest('id')->limit(15)->get(),
        ]);
    }

    public function update(Request $request, Promotion $coupon, Coupons $coupons): RedirectResponse
    {
        $data = $request->validate($this->editableRules());

        $coupons->update($coupon, $this->normalise($data), $request->user());

        return redirect()->route('owner.coupons.show', $coupon)->with('status', $coupon->wasChanged() ? __('owner.coupons.saved') : __('owner.coupons.unchanged'));
    }

    public function activate(Request $request, Promotion $coupon, Coupons $coupons): RedirectResponse
    {
        return $this->switch($request, $coupon, $coupons, true);
    }

    public function deactivate(Request $request, Promotion $coupon, Coupons $coupons): RedirectResponse
    {
        return $this->switch($request, $coupon, $coupons, false);
    }

    public function destroy(Request $request, Promotion $coupon, Coupons $coupons): RedirectResponse
    {
        $code = $coupon->code;
        $coupons->delete($coupon, $request->user());

        return redirect()->route('owner.coupons.index')->with('status', __('owner.coupons.deleted', ['code' => $code]));
    }

    private function switch(Request $request, Promotion $coupon, Coupons $coupons, bool $active): RedirectResponse
    {
        try {
            $coupons->setActive($coupon, $active, $request->user());
        } catch (PromotionGatewayException $e) {
            return back()->with('owner_error', ['kind' => 'permission', 'message' => $e->ownerMessage(), 'code' => strtoupper($e->reason)]);
        }

        return back()->with('status', __($active ? 'owner.coupons.activated' : 'owner.coupons.deactivated', ['code' => $coupon->code]));
    }

    /** @return array<string, mixed> */
    private function editableRules(): array
    {
        return [
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'max_redemptions_per_customer' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'product_types' => ['nullable', 'array'],
            'product_types.*' => ['string', Rule::enum(ProductType::class)],
            'plans' => ['nullable', 'array'],
            'plans.*' => ['string', Rule::enum(PlanTier::class)],
            'intervals' => ['nullable', 'array'],
            'intervals.*' => ['string', Rule::enum(BillingInterval::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        foreach (['starts_at', 'expires_at'] as $field) {
            $data[$field] = filled($data[$field] ?? null) ? Carbon::parse($data[$field]) : null;
        }
        foreach (['max_redemptions', 'max_redemptions_per_customer', 'duration_months'] as $field) {
            $data[$field] = filled($data[$field] ?? null) ? (int) $data[$field] : null;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'productTypes' => ProductType::cases(),
            'tiers' => PlanTier::cases(),
            'intervals' => BillingInterval::cases(),
            'timezone' => config('app.timezone'),
        ];
    }
}
