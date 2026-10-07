<?php

namespace App\Checkout;

use App\Models\Customer;
use App\Models\Promotion;
use App\Pricing\Exceptions\CustomerCurrencyMismatch;
use App\Pricing\Price;
use App\Pricing\PriceSelection;
use App\Pricing\PricingCatalog;

/** The read-only price and promotion checks shared by preview and checkout. */
final class CheckoutOfferResolver
{
    public function __construct(
        private readonly PricingCatalog $catalog,
        private readonly ExistingCustomerCurrencyLock $currencyLock,
    ) {}

    /** @return array{price: Price, promotion: ?Promotion} */
    public function resolve(PriceSelection $selection, string $country, string $email, ?string $currency, ?string $promotionCode, ?Customer $account, bool $preview = false): array
    {
        $price = $this->catalog->resolve($selection, $country, $currency);

        if ($account && ($locked = $this->currencyLock->forCustomer($account)) !== null
            && $locked !== $price->money->currency->code) {
            throw new CustomerCurrencyMismatch($locked);
        }

        $promotion = null;
        if ($promotionCode !== null && trim($promotionCode) !== '') {
            $promotion = Promotion::findByCode($promotionCode) ?? throw new PromotionRejected('unknown');

            if ($reason = $promotion->ineligibilityReason($price)) {
                throw new PromotionRejected($reason);
            }

            if ($preview && $promotion->remainingRedemptions() === 0) {
                throw new PromotionRejected('used_up');
            }

            if ($promotion->usedUpBy($account?->email ?? $email, $account?->id)) {
                throw new PromotionRejected('already_used');
            }

            if ($preview && $promotion->previewUsedUpBy($account?->email ?? $email, $account?->id)) {
                throw new PromotionRejected('already_used');
            }
        }

        return ['price' => $price, 'promotion' => $promotion];
    }
}
