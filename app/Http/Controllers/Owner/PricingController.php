<?php

namespace App\Http\Controllers\Owner;

use App\Billing\SubscriptionStatus;
use App\Content\ContentEditor;
use App\Content\ContentState;
use App\Content\Schema\ContentSchema;
use App\Http\Controllers\Controller;
use App\Models\PriceDefinition;
use App\Models\Subscription;
use App\Owner\AdminAudit;
use App\Owner\SystemStatus;
use App\Pricing\PriceEditor;
use App\Pricing\PriceKey;
use App\Pricing\PricingCatalog;
use App\Pricing\StripePriceVerifier;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Pricing: technical prices (draft, validate, publish) and links to the
 * plan wording (content sections "pricing.*"). Stripe keys stay in the
 * environment; this page only shows whether prices and Price IDs are valid.
 */
class PricingController extends Controller
{
    public function index(Request $request, PricingCatalog $catalog, PriceEditor $editor, ContentEditor $content): View
    {
        $markets = array_keys((array) config('pricing.markets'));
        $market = in_array($request->query('market'), $markets, true) ? $request->query('market') : (string) config('pricing.display_market');
        $definitions = PriceDefinition::where('market', $market)->get()->keyBy(fn (PriceDefinition $d) => $d->key()?->value());

        $rows = array_map(function (PriceKey $key) use ($catalog, $definitions) {
            $price = $catalog->price($key->productType, $key->tier, $key->interval, $catalog->market($key->market));

            return ['key' => $key, 'price' => $price, 'definition' => $definitions[$key->value()] ?? null];
        }, PriceKey::forMarket($market));

        $plans = [];
        foreach (ContentSchema::group('pricing') as $section) {
            $plans[$section->key] = collect(ContentSchema::locales())->mapWithKeys(fn (string $locale) => [$locale => ContentState::of($section, $locale, $content->entry($section, $locale))]);
        }

        return view('owner.pricing.index', [
            'market' => $market,
            'markets' => $markets,
            'rows' => $rows,
            'plans' => $plans,
            'stripeConfigured' => app(StripePriceVerifier::class)->configured(),
            'verification' => Cache::get(SystemStatus::STRIPE_VERIFICATION),
        ]);
    }

    public function edit(string $price, PriceEditor $editor): View
    {
        $key = PriceKey::tryFrom($price) ?? abort(404);
        $definition = $editor->definition($key);
        $values = $editor->editable($key, $definition);

        return view('owner.pricing.edit', [
            'key' => $key,
            'definition' => $definition,
            'values' => $values,
            'amount' => isset($values['amount']) ? $editor->major((int) $values['amount'], $values['currency']) : '',
            'decimals' => (int) config("pricing.currencies.{$key->currency()}.display_decimals", 0),
            'configRow' => $key->configRow(),
            'subscriptions' => Subscription::where([
                'product_type' => $key->productType->value,
                'plan' => $key->tier->value,
                'interval' => $key->interval->value,
                'market' => $key->market,
            ])->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trialing->value, SubscriptionStatus::PastDue->value])->count(),
            'stripeConfigured' => app(StripePriceVerifier::class)->configured(),
        ]);
    }

    public function update(Request $request, string $price, PriceEditor $editor): RedirectResponse
    {
        $key = PriceKey::tryFrom($price) ?? abort(404);
        $editor->saveDraft($key, $request->only(['currency', 'amount', 'stripe_price_id', 'active']), $request->user());

        return redirect()->route('owner.pricing.prices.edit', $key->value())->with('status', __('owner.pricing.draft_saved'));
    }

    public function publish(Request $request, string $price, PriceEditor $editor): RedirectResponse
    {
        $key = PriceKey::tryFrom($price) ?? abort(404);
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => __('owner.pricing.errors.confirm')]);
        $editor->publish($key, $request->user());

        return redirect()->route('owner.pricing.prices.edit', $key->value())->with('status', __('owner.pricing.published'));
    }

    public function discard(Request $request, string $price, PriceEditor $editor): RedirectResponse
    {
        $key = PriceKey::tryFrom($price) ?? abort(404);
        $editor->discardDraft($key, $request->user());

        return redirect()->route('owner.pricing.prices.edit', $key->value())->with('status', __('owner.pricing.draft_discarded'));
    }

    /** The pricing:verify-stripe check, read-only against Stripe; only the outcome is kept. */
    public function verifyStripe(Request $request, PricingCatalog $catalog, StripePriceVerifier $verifier): RedirectResponse
    {
        if (! $verifier->configured()) {
            return back()->with('owner_error', ['kind' => 'permission', 'message' => __('owner.pricing.errors.stripe_not_configured'), 'code' => 'STRIPE_NOT_CONFIGURED']);
        }

        $problems = [];
        foreach ($catalog->allPrices() as $price) {
            if (! $price->active) {
                continue;
            }

            $result = $verifier->verify($price->stripePriceId, $price->money->amount, $price->money->currency->code, $price->interval, $price->productType);
            if ($result !== StripePriceVerifier::OK) {
                $problems[] = (new PriceKey($price->productType, $price->tier, $price->interval, $price->market))->value().': '.$result;
            }
        }

        Cache::forever(SystemStatus::STRIPE_VERIFICATION, ['at' => now()->format('Y-m-d H:i'), 'ok' => $problems === [], 'problems' => count($problems), 'details' => array_slice($problems, 0, 50)]);
        AdminAudit::record($request->user(), 'stripe.verified', 'price', 'catalog', ['ok' => $problems === [], 'problems' => count($problems)]);

        return back()->with('status', $problems === [] ? __('owner.pricing.verified_ok') : __('owner.pricing.verified_problems', ['count' => count($problems)]));
    }
}
