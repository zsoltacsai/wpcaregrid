<?php

namespace App\Checkout;

use App\Legal\LegalDocumentCatalog;
use App\Models\CheckoutIntent;
use App\Models\Customer;
use App\Models\LegalDocumentVersion;
use App\Pricing\Exceptions\PricingException;
use App\Pricing\PriceSelection;
use App\Pricing\PricingCatalog;
use App\Tax\BillingIdentityCompleteness;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        private readonly CheckoutOfferResolver $offers,
        private readonly BillingIdentityCompleteness $billingIdentity,
    ) {}

    /**
     * @param  Customer|null  $account  The logged-in customer, if any (from the session, never from input).
     *
     * @throws PricingException when the selection cannot be sold to this country
     * @throws PromotionRejected when the code is unknown or not valid for the price
     * @throws CheckoutUnavailable when Stripe cannot be used
     */
    public function handle(PriceSelection $selection, CustomerDetails $customer, ?string $promotionCode, string $browserSessionId, ?Customer $account = null, ?string $currency = null, ?LegalDocumentVersion $legalDocument = null, bool $legalAccepted = false): CheckoutIntent
    {
        $currentTerms = app(LegalDocumentCatalog::class)->current('terms_of_service', app()->getLocale());
        $checkoutAcceptanceRequired = $currentTerms?->acceptance_mode === 'acceptance_required_before_checkout';
        if ($checkoutAcceptanceRequired && (! $legalAccepted || $legalDocument?->id !== $currentTerms?->id
            || ! hash_equals((string) $currentTerms?->content_sha256, (string) $legalDocument?->content_sha256))) {
            throw ValidationException::withMessages(['terms_accepted' => 'The current Terms version must be explicitly accepted before checkout.']);
        }
        if (! $checkoutAcceptanceRequired && $legalDocument !== null) {
            throw ValidationException::withMessages(['terms_document_uuid' => 'This Terms version is not currently required for checkout.']);
        }
        // A logged-in customer buys for their own account and email.
        if ($account) {
            $customer = new CustomerDetails(
                email: $account->email,
                firstName: $account->first_name,
                lastName: $account->last_name,
                country: $customer->country,
                customerType: $account->customer_type ?? 'individual',
                companyName: $account->company_name,
                taxId: $account->taxIdentifier(),
                billingAddress: $account->billing_address,
            );
        }
        $missingIdentity = $this->billingIdentity->missingForCheckoutDetails([
            'first_name' => $customer->firstName,
            'last_name' => $customer->lastName,
            'customer_type' => $customer->customerType,
            'company_name' => $customer->companyName,
            'country' => $customer->country,
            'address_line1' => $customer->billingAddress['line1'] ?? null,
            'postal_code' => $customer->billingAddress['postal_code'] ?? null,
            'city' => $customer->billingAddress['city'] ?? null,
        ]);
        if ($missingIdentity !== []) {
            throw new BillingIdentityIncomplete($missingIdentity);
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

        $offer = $this->offers->resolve($selection, $customer->country, $customer->email, $currency, $promotionCode, $account);
        $price = $offer['price'];
        $promotion = $offer['promotion'];
        $market = $this->catalog->market($price->market);

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
            ->where('legal_document_version_id', $legalDocument?->id)
            ->where('created_at', '>=', now()->subMinutes(self::REUSE_WINDOW_MINUTES))
            ->latest('id')
            ->first();

        if ($existing?->isReusable() && $this->sameBillingIdentity($existing, $customer)) {
            return $existing;
        }

        $intent = DB::transaction(function () use ($account, $customer, $price, $market, $promotion, $sessionHash, $legalDocument, $legalAccepted) {
            $intent = CheckoutIntent::create([
                'status' => CheckoutStatus::Pending,
                'customer_id' => $account?->id,
                'email' => $customer->email,
                'first_name' => $customer->firstName,
                'last_name' => $customer->lastName,
                'country' => $customer->country,
                'customer_type' => $customer->customerType,
                'company_name' => $customer->companyName,
                'tax_id' => null,
                'tax_identifier_encrypted' => $customer->taxId,
                'billing_address' => $customer->billingAddress,
                'product_type' => $price->productType,
                'plan' => $price->tier,
                'interval' => $price->interval,
                'market' => $price->marketGroup,
                'currency' => $price->money->currency->code,
                'amount' => $price->money->amount,
                'tax_treatment' => $market->taxTreatment->value,
                'stripe_price_id' => $price->stripePriceId,
                'pricing_version' => $price->version,
                'promotion_id' => $promotion?->id,
                'promotion_code' => $promotion?->code,
                'legal_document_version_id' => $legalAccepted ? $legalDocument?->id : null,
                'legal_document_sha256' => $legalAccepted ? $legalDocument?->content_sha256 : null,
                'legal_accepted_at' => $legalAccepted ? now('UTC') : null,
                'browser_session_hash' => $sessionHash,
            ]);

            if ($promotion) {
                $this->reservations->reserve($promotion, $intent, $price, $customer->email, $account?->id);
            }

            return $intent;
        }, attempts: 3);

        try {
            $session = $this->gateway->createSession($intent, $promotion);
        } catch (CheckoutUnavailable $e) {
            if ($e->outcomeUnknown) {
                // A timeout/5xx may have happened after Stripe created the
                // Session. Keep the intent pending and the slot reserved;
                // a signed event can still resolve it by client_reference_id.
                $intent->update(['failure_reason' => $e->getMessage()]);
            } else {
                $intent->update(['status' => CheckoutStatus::Failed, 'failure_reason' => $e->getMessage()]);
                $this->reservations->release($intent);
            }

            throw $e;
        }

        $intent = DB::transaction(function () use ($intent, $session) {
            $locked = CheckoutIntent::query()->whereKey($intent->id)->lockForUpdate()->firstOrFail();
            $locked->stripe_checkout_session_id ??= $session->id;
            $locked->stripe_checkout_url ??= $session->url;
            $locked->stripe_checkout_expires_at ??= $session->expiresAt;
            if ($locked->status === CheckoutStatus::Pending) {
                $locked->status = CheckoutStatus::CheckoutCreated;
            }
            $locked->save();

            return $locked;
        });
        $this->reservations->setExpiry($intent, $session->expiresAt);

        return $intent;
    }

    private function sameBillingIdentity(CheckoutIntent $intent, CustomerDetails $customer): bool
    {
        return Customer::normalizeEmail($intent->email) === Customer::normalizeEmail($customer->email)
            && $intent->first_name === $customer->firstName
            && $intent->last_name === $customer->lastName
            && strtoupper($intent->country) === strtoupper($customer->country)
            && $intent->customer_type === $customer->customerType
            && $intent->company_name === $customer->companyName
            && $intent->taxIdentifier() === $customer->taxId
            && $this->canonicalBillingAddress($intent->billing_address) === $this->canonicalBillingAddress($customer->billingAddress);
    }

    /** @param array<string, mixed>|null $address @return array<string, mixed> */
    private function canonicalBillingAddress(?array $address): array
    {
        $address ??= [];
        ksort($address, SORT_STRING);

        return $address;
    }
}
