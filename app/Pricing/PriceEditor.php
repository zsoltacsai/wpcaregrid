<?php

namespace App\Pricing;

use App\Models\PriceDefinition;
use App\Models\User;
use App\Owner\AdminAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Technical price editing in the owner area: save a draft, then publish it
 * after validation (docs/PRICING_ADMIN.md).
 *
 * - The currency is the market's; nothing else is accepted.
 * - Amounts are entered in the main unit with no more decimals than
 *   customers are shown, and stored in minor units within Stripe's limit.
 * - An active price needs a Stripe Price ID ("price_…"), used by no other
 *   price; when Stripe is configured it must match in Stripe (amount,
 *   currency, interval, active, and the Stripe Product of the price's
 *   product type, taken from configuration) before it is published.
 * - Publishing affects future checkouts only. Existing subscriptions keep
 *   their Stripe price; earlier Stripe Price IDs stay recognised.
 */
final class PriceEditor
{
    /** Stripe's largest unit_amount for most currencies. */
    public const MAX_MINOR_AMOUNT = 99_999_999;

    public function __construct(
        private readonly PricingOverrides $overrides,
        private readonly StripePriceVerifier $stripe,
    ) {}

    public function definition(PriceKey $key): ?PriceDefinition
    {
        return PriceDefinition::forKey($key)->first();
    }

    /**
     * What the form shows: the draft, else the published price, else the
     * config/pricing.php row, else nothing yet (currency fixed by the market).
     *
     * @return array{currency: string, amount: ?int, stripe_price_id: ?string, active: bool}
     */
    public function editable(PriceKey $key, ?PriceDefinition $definition): array
    {
        if ($definition?->draft) {
            return $definition->draft;
        }

        if ($definition?->isPublished()) {
            return ['currency' => $definition->currency, 'amount' => $definition->amount, 'stripe_price_id' => $definition->stripe_price_id, 'active' => $definition->active];
        }

        $row = $key->configRow();

        return [
            'currency' => $key->currency(),
            'amount' => $row['amount'] ?? null,
            'stripe_price_id' => $row['stripe_price_id'] ?? null,
            'active' => (bool) ($row['active'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $input  currency, amount (main unit, e.g. "8990"), stripe_price_id, active
     *
     * @throws ValidationException
     */
    public function saveDraft(PriceKey $key, array $input, User $owner): PriceDefinition
    {
        $draft = $this->validated($key, $input);

        return DB::transaction(function () use ($key, $draft, $owner) {
            $definition = PriceDefinition::forKey($key)->lockForUpdate()->first() ?? new PriceDefinition($key->attributes());
            $created = ! $definition->exists;

            $definition->forceFill(['draft' => $draft, 'draft_updated_at' => now(), 'draft_updated_by' => $owner->id])->save();
            AdminAudit::record($owner, $created ? 'price.created' : 'price.updated', 'price', $key->value(), ['price' => $key->value()]);

            return $definition;
        });
    }

    /**
     * Publish the saved draft.
     *
     * @throws ValidationException
     */
    public function publish(PriceKey $key, User $owner): PriceDefinition
    {
        $definition = $this->definition($key);
        if (! $definition?->draft) {
            throw ValidationException::withMessages(['publish' => __('owner.pricing.errors.no_draft')]);
        }

        // Validated again: config or other prices may have changed since the draft was saved.
        $draft = $this->validated($key, [
            'currency' => $definition->draft['currency'] ?? null,
            'amount' => isset($definition->draft['amount']) ? $this->major((int) $definition->draft['amount'], (string) ($definition->draft['currency'] ?? '')) : null,
            'stripe_price_id' => $definition->draft['stripe_price_id'] ?? null,
            'active' => $definition->draft['active'] ?? false,
        ]);

        $verified = false;
        if ($draft['active'] && $this->stripe->configured()) {
            // The product type comes from the price being edited (the route), never from the form.
            $result = $this->stripe->verify($draft['stripe_price_id'], $draft['amount'], $draft['currency'], $key->interval, $key->productType);
            if ($result !== StripePriceVerifier::OK) {
                throw ValidationException::withMessages(['stripe_price_id' => __('owner.pricing.errors.stripe_mismatch', ['result' => $result])]);
            }
            $verified = true;
        }

        $definition = DB::transaction(function () use ($key, $draft, $owner, $verified) {
            $definition = PriceDefinition::forKey($key)->lockForUpdate()->firstOrFail();
            $previousId = $definition->isPublished() ? $definition->stripe_price_id : ($key->configRow()['stripe_price_id'] ?? null);
            $retired = (array) ($definition->retired_stripe_price_ids ?? []);
            if (filled($previousId) && $previousId !== $draft['stripe_price_id'] && ! in_array($previousId, $retired, true)) {
                $retired[] = $previousId;
            }

            $definition->forceFill([
                'currency' => $draft['currency'],
                'amount' => $draft['amount'],
                'stripe_price_id' => $draft['stripe_price_id'],
                'active' => $draft['active'],
                'retired_stripe_price_ids' => array_values(array_diff($retired, [$draft['stripe_price_id']])) ?: null,
                'published_at' => now(),
                'published_by' => $owner->id,
                'draft' => null,
                'draft_updated_at' => null,
                'draft_updated_by' => null,
            ])->save();

            AdminAudit::record($owner, 'price.published', 'price', $key->value(), [
                'price' => $key->value(),
                'amount' => $draft['amount'],
                'currency' => $draft['currency'],
                'active' => $draft['active'],
                'stripe_verified' => $verified,
                'stripe_price_changed' => filled($previousId) && $previousId !== $draft['stripe_price_id'],
            ]);

            return $definition;
        });

        $this->overrides->flush();

        return $definition;
    }

    public function discardDraft(PriceKey $key, User $owner): void
    {
        $definition = $this->definition($key);
        if (! $definition?->draft) {
            return;
        }

        DB::transaction(function () use ($definition, $key, $owner) {
            $definition->isPublished()
                ? $definition->forceFill(['draft' => null, 'draft_updated_at' => null, 'draft_updated_by' => null])->save()
                : $definition->delete();
            AdminAudit::record($owner, 'price.draft_discarded', 'price', $key->value(), ['price' => $key->value()]);
        });
    }

    /** The amount in the currency's main unit, as typed in the form ("8990", "89.5"). */
    public function major(int $minor, string $currency): string
    {
        $minorUnits = (int) config("pricing.currencies.{$currency}.minor_units", 2);
        $decimals = (int) config("pricing.currencies.{$currency}.display_decimals", 0);
        $value = intdiv($minor, 10 ** $minorUnits);
        $fraction = substr(str_pad((string) ($minor % (10 ** $minorUnits)), $minorUnits, '0', STR_PAD_LEFT), 0, $decimals);

        return $decimals > 0 && (int) $fraction > 0 ? $value.'.'.rtrim($fraction, '0') : (string) $value;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{currency: string, amount: int, stripe_price_id: ?string, active: bool}
     *
     * @throws ValidationException
     */
    private function validated(PriceKey $key, array $input): array
    {
        $currency = $key->currency();
        $decimals = (int) config("pricing.currencies.{$currency}.display_decimals", 0);
        $amountPattern = $decimals > 0 ? '/^\d{1,9}(\.\d{1,'.$decimals.'})?$/' : '/^\d{1,9}$/';

        $data = Validator::make($input, [
            // Only configured currencies, and only the market's own.
            'currency' => ['required', 'string', Rule::in(array_keys((array) config('pricing.currencies'))), Rule::in([$currency])],
            'amount' => ['required', 'string', 'regex:'.$amountPattern],
            'stripe_price_id' => ['nullable', 'string', 'max:100', 'regex:/^price_[A-Za-z0-9_]{3,96}$/', 'required_if_accepted:active'],
            'active' => ['nullable', 'boolean'],
        ], [
            'currency.in' => __('owner.pricing.errors.currency', ['currency' => $currency]),
            'amount.regex' => __('owner.pricing.errors.amount_format', ['decimals' => $decimals]),
            'stripe_price_id.regex' => __('owner.pricing.errors.stripe_format'),
            'stripe_price_id.required_if_accepted' => __('owner.pricing.errors.stripe_required'),
        ], [
            'amount' => __('owner.pricing.fields.amount'),
            'stripe_price_id' => __('owner.pricing.fields.stripe_price_id'),
        ])->validate();

        $amount = $this->minor((string) $data['amount'], $currency);
        if ($amount < 1 || $amount > self::MAX_MINOR_AMOUNT) {
            throw ValidationException::withMessages(['amount' => __('owner.pricing.errors.amount_range')]);
        }

        $stripeId = filled($data['stripe_price_id'] ?? null) ? (string) $data['stripe_price_id'] : null;
        if ($stripeId !== null && ($other = $this->priceUsing($stripeId, $key)) !== null) {
            throw ValidationException::withMessages(['stripe_price_id' => __('owner.pricing.errors.stripe_in_use', ['price' => $other])]);
        }

        return [
            'currency' => $currency,
            'amount' => $amount,
            'stripe_price_id' => $stripeId,
            'active' => (bool) ($data['active'] ?? false),
        ];
    }

    public function minor(string $major, string $currency): int
    {
        $minorUnits = (int) config("pricing.currencies.{$currency}.minor_units", 2);
        [$whole, $fraction] = array_pad(explode('.', $major, 2), 2, '');

        return (int) $whole * (10 ** $minorUnits) + (int) str_pad(substr($fraction, 0, $minorUnits), $minorUnits, '0');
    }

    /** Another price (in the catalogue now) already charging through this Stripe Price ID. */
    private function priceUsing(string $stripePriceId, PriceKey $key): ?string
    {
        foreach (app(PricingCatalog::class)->allPrices() as $price) {
            $other = new PriceKey($price->productType, $price->tier, $price->interval, $price->market);
            if ($other->value() !== $key->value() && ($price->stripePriceId === $stripePriceId || in_array($stripePriceId, $price->retiredStripePriceIds, true))) {
                return $other->value();
            }
        }

        return null;
    }
}
