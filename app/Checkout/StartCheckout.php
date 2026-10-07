<?php

namespace App\Checkout;

use App\Models\CheckoutIntent;
use App\Models\Customer;
use App\Models\Promotion;
use App\Pricing\Exceptions\PricingException;
use App\Pricing\PriceSelection;
use App\Pricing\PricingCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Resolve the price on the server, record the intent, then create the
 * Stripe Checkout Session for it.
 */
final class StartCheckout
{
    /** How long a repeated submit may reuse an open session. */
    private const REUSE_WINDOW_MINUTES = 30;

    public function __construct(
        private readonly PricingCatalog $catalog,
        private readonly CheckoutGateway $gateway,
        private readonly PromotionReservations $reservations,
    ) {}

    /**
     * @param  Customer|null  $account  The logged-in customer, if any (from the session, never from input).
     *
     * @throws PricingException when the selection cannot be sold to this country
     * @throws PromotionRejected when the code is unknown or not valid for the price
     * @throws CheckoutUnavailable when Stripe cannot be used
     */
    public function handle(PriceSelection $selection, CustomerDetails $customer, ?string $promotionCode, string $browserSessionId, ?Customer $account = null, ?string $currency = null): CheckoutIntent
    {
        // A logged-in customer buys for their own account and email.
        if ($account) {
            $customer = new CustomerDetails(
                email: $account->email,
                firstName: $account->first_name,
                lastName: $account->last_name,
                country: $customer->country,
                customerType: $account->customer_type ?? 'individual',
                companyName: $account->company_name,
                taxId: $account->tax_id,
                billingAddress: $account->billing_address,
            );
        }
        $customer = new CustomerDetails(
            email: Customer::normalizeEmail($customer->email),
            firstName: $customer->firstName,
            lastName: $customer->lastName,
            country: $customer->country,
            customerType: $customer->customerType,
            companyName: $customer->companyName,
            taxId: $customer->taxId,
            billingAddress: $customer->billingAddress,
        );

        $price = $this->catalog->resolve($selection, $customer->country, $currency);
        $market = $this->catalog->market($price->market);

        $promotion = null;
        if ($promotionCode !== null && trim($promotionCode) !== '') {
            $promotion = Promotion::findByCode($promotionCode) ?? throw new PromotionRejected('unknown');

            if ($reason = $promotion->ineligibilityReason($price)) {
                throw new PromotionRejected($reason);
            }

            if ($promotion->usedUpBy($customer->email, $account?->id)) {
                throw new PromotionRejected('already_used');
            }
        }

        $sessionHash = CheckoutIntent::hashBrowserSession($browserSessionId);

        // A double click or a resubmitted form reuses the open Stripe session
        // instead of creating a second one.
        $existing = CheckoutIntent::query()
            ->where('browser_session_hash', $sessionHash)
            ->where('email', $customer->email)
            ->where('country', $customer->country)
            ->where('currency', $price->money->currency->code)
            ->where('product_type', $selection->productType->value)
            ->where('plan', $selection->tier->value)
            ->where('interval', $selection->interval->value)
            ->where('promotion_id', $promotion?->id)
            ->where('created_at', '>=', now()->subMinutes(self::REUSE_WINDOW_MINUTES))
            ->latest('id')
            ->first();

        if ($existing?->isReusable()) {
            return $existing;
        }

        $intent = DB::transaction(function () use ($account, $customer, $price, $market, $promotion, $sessionHash) {
            $intent = CheckoutIntent::create([
                'status' => CheckoutStatus::Pending,
                'customer_id' => $account?->id,
                'email' => $customer->email,
                'first_name' => $customer->firstName,
                'last_name' => $customer->lastName,
                'country' => $customer->country,
                'customer_type' => $customer->customerType,
                'company_name' => $customer->companyName,
                'tax_id' => $customer->taxId,
                'billing_address' => $customer->billingAddress,
                'product_type' => $price->productType,
                'plan' => $price->tier,
                'interval' => $price->interval,
                'market' => $price->marketGroup,
                'currency' => $price->money->currency->code,
                'amount' => $price->money->amount,
                'tax_treatment' => $market->taxTreatment->value,
                'stripe_price_id' => $price->stripePriceId,
                'promotion_id' => $promotion?->id,
                'promotion_code' => $promotion?->code,
                'browser_session_hash' => $sessionHash,
            ]);

            if ($promotion) {
                $this->reservations->reserve($promotion, $intent, $price, $customer->email, $account?->id);
            }

            return $intent;
        });

        try {
            $session = $this->gateway->createSession($intent, $promotion);
        } catch (CheckoutUnavailable $e) {
            $intent->update(['status' => CheckoutStatus::Failed, 'failure_reason' => $e->getMessage()]);
            $this->reservations->release($intent);

            throw $e;
        }

        $intent->update([
            'status' => CheckoutStatus::CheckoutCreated,
            'stripe_checkout_session_id' => $session->id,
            'stripe_checkout_url' => $session->url,
            'stripe_checkout_expires_at' => $session->expiresAt,
        ]);
        $this->reservations->setExpiry($intent, $session->expiresAt);

        return $intent;
    }
}
