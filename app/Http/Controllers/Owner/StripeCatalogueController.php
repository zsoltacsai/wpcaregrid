<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\StripeCatalogueOperation;
use App\Owner\AdminAudit;
use App\Pricing\StripeCatalogue\CatalogueGatewayException;
use App\Pricing\StripeCatalogue\CatalogueGuard;
use App\Pricing\StripeCatalogue\CatalogueManager;
use App\Pricing\StripeCatalogue\CatalogueRefused;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;

/**
 * Pricing → Stripe Catalogue (docs/STRIPE_CATALOGUE.md). Every action is Owner-only (owner.pricing.manage).
 * Loading the catalogue is a dry run. Nothing is created in Stripe unless the Owner approves a stored
 * plan, and the browser never supplies an amount, currency, Stripe ID or mode: only an operation and
 * the item ids it approves out of that stored plan.
 */
class StripeCatalogueController extends Controller
{
    public function index(CatalogueGuard $guard): View
    {
        $refusal = null;
        $mode = null;
        try {
            $mode = $guard->mode();
        } catch (CatalogueRefused $e) {
            $refusal = $e->reason;
        }

        return view('owner.pricing.stripe-catalogue.index', [
            'mode' => $mode,
            'refusal' => $refusal,
            'staging' => $guard->isStaging(),
            'applyEnabled' => (bool) config('services.stripe.catalogue.apply_enabled'),
            'liveApproved' => (bool) config('services.stripe.catalogue.live_apply_approved'),
            'operations' => StripeCatalogueOperation::query()->latest()->limit(15)->get(['id', 'mode', 'status', 'plan_hash', 'created_at', 'finished_at']),
        ]);
    }

    /** Dry run: reads Stripe, stores the plan. */
    public function preview(Request $request, CatalogueManager $manager): RedirectResponse
    {
        try {
            $operation = $manager->preview($request->user());
        } catch (CatalogueRefused|CatalogueGatewayException $e) {
            AdminAudit::record($request->user(), 'stripe_catalogue.preview_failed', 'stripe_catalogue', 'catalogue', ['code' => $e->reason]);

            return back()->with('owner_error', $this->refusal($e->reason));
        }

        return redirect()->route('owner.pricing.stripe-catalogue.show', $operation)->with('status', __('owner.stripe_catalogue.preview_ready'));
    }

    public function show(StripeCatalogueOperation $operation, CatalogueGuard $guard): View
    {
        $mode = null;
        try {
            $mode = $guard->mode();
        } catch (CatalogueRefused) {
            // The stored plan is still shown; only acting on it is impossible.
        }

        return view('owner.pricing.stripe-catalogue.show', [
            'operation' => $operation,
            'plan' => $operation->plan,
            'result' => $operation->result,
            'currentMode' => $mode,
            'applyEnabled' => (bool) config('services.stripe.catalogue.apply_enabled'),
            'expired' => $operation->expires_at->isPast(),
        ]);
    }

    public function apply(Request $request, StripeCatalogueOperation $operation, CatalogueManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*' => ['string', 'max:120'],
            'confirm' => ['accepted'],
            'confirm_text' => ['required', 'string'],
        ], [
            'items.required' => __('owner.stripe_catalogue.errors.nothing_approved'),
            'confirm.accepted' => __('owner.stripe_catalogue.errors.confirm'),
        ]);

        // The Owner types the mode being changed: TEST or LIVE cannot be approved by accident.
        if (trim($data['confirm_text']) !== 'APPLY '.strtoupper($operation->mode)) {
            return back()->with('owner_error', $this->refusal('confirm_text'));
        }

        try {
            $manager->apply($operation, array_values($data['items']), $request->user());
        } catch (CatalogueRefused|CatalogueGatewayException $e) {
            AdminAudit::record($request->user(), 'stripe_catalogue.apply_refused', 'stripe_catalogue', $operation->id, ['code' => $e->reason]);

            return back()->with('owner_error', $this->refusal($e->reason));
        }

        return redirect()->route('owner.pricing.stripe-catalogue.show', $operation)->with('status', __('owner.stripe_catalogue.apply_finished'));
    }

    /** Link an existing Stripe Product to a product type on the Owner's explicit decision (local change only). */
    public function mapProduct(Request $request, string $productType, CatalogueManager $manager): RedirectResponse
    {
        $data = $request->validate(['stripe_product_id' => ['required', 'string', 'regex:/^prod_[A-Za-z0-9_]{3,96}$/']]);

        try {
            $manager->mapProduct($productType, $data['stripe_product_id'], $request->user());
        } catch (CatalogueRefused|CatalogueGatewayException $e) {
            AdminAudit::record($request->user(), 'stripe_catalogue.apply_refused', 'stripe_catalogue', 'product:'.$productType, ['code' => $e->reason]);

            return back()->with('owner_error', $this->refusal($e->reason));
        }

        return back()->with('status', __('owner.stripe_catalogue.product_linked'));
    }

    /** @return array{kind: string, message: string, code: string} */
    private function refusal(string $reason): array
    {
        $key = 'owner.stripe_catalogue.errors.'.$reason;

        return [
            'kind' => 'permission',
            'message' => Lang::has($key) ? __($key) : __('owner.stripe_catalogue.errors.generic'),
            'code' => strtoupper($reason),
        ];
    }
}
