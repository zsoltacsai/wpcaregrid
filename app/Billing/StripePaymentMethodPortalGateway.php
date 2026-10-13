<?php

namespace App\Billing;

use RuntimeException;
use Stripe\StripeClient;

/** Uses only a Stripe portal configuration verified to permit payment method edits. */
final class StripePaymentMethodPortalGateway implements PaymentMethodPortalGateway
{
    public function __construct(
        private readonly ?string $secret,
        private readonly ?string $configurationId,
        private readonly bool $allowLiveMode = false,
    ) {}

    public function createSession(string $stripeCustomerId, string $returnUrl): string
    {
        if (! $this->secret || ! $this->configurationId || ! preg_match('/^bpc_[A-Za-z0-9]+$/', $this->configurationId)
            || ! preg_match('/^cus_[A-Za-z0-9]+$/', $stripeCustomerId)) {
            throw new RuntimeException('Stripe payment method management is not configured.');
        }
        if (str_starts_with($this->secret, 'sk_live_') && ! $this->allowLiveMode) {
            throw new RuntimeException('Stripe live mode is disabled.');
        }
        if (! str_starts_with($this->secret, 'sk_test_') && ! ($this->allowLiveMode && str_starts_with($this->secret, 'sk_live_'))) {
            throw new RuntimeException('Stripe key mode is not recognised.');
        }

        $stripe = new StripeClient($this->secret);
        $configuration = $stripe->billingPortal->configurations->retrieve($this->configurationId, []);
        $features = $configuration->features ?? null;
        $keyIsLive = str_starts_with($this->secret, 'sk_live_');
        if ((bool) ($configuration->livemode ?? false) !== $keyIsLive) {
            throw new RuntimeException('Stripe portal configuration mode does not match the API key.');
        }
        $paymentMethodUpdate = (bool) ($features->payment_method_update->enabled ?? false);
        $subscriptionUpdate = (bool) ($features->subscription_update->enabled ?? false);
        $subscriptionCancel = (bool) ($features->subscription_cancel->enabled ?? false);
        $customerUpdate = (bool) ($features->customer_update->enabled ?? false);
        $invoiceHistory = (bool) ($features->invoice_history->enabled ?? false);
        if (! $paymentMethodUpdate || $subscriptionUpdate || $subscriptionCancel || $customerUpdate || $invoiceHistory) {
            throw new RuntimeException('Stripe portal configuration exceeds the approved payment method scope.');
        }

        $session = $stripe->billingPortal->sessions->create([
            'customer' => $stripeCustomerId,
            'configuration' => $this->configurationId,
            'return_url' => $returnUrl,
        ]);
        $url = $session->url ?? null;
        if (! is_string($url) || ! str_starts_with($url, 'https://billing.stripe.com/')) {
            throw new RuntimeException('Stripe did not return a valid billing portal URL.');
        }

        return $url;
    }
}
