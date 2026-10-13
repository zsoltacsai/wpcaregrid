<?php

namespace App\Billing;

use RuntimeException;
use Stripe\StripeClient;

final class StripeApiSubscriptionReader implements StripeSubscriptionReader
{
    public function __construct(private readonly ?string $secret, private readonly bool $allowLiveMode = false) {}

    public function retrieve(string $stripeSubscriptionId): object
    {
        if (! $this->secret) {
            throw new RuntimeException('Stripe is not configured.');
        }
        if (str_starts_with($this->secret, 'sk_live_') && ! $this->allowLiveMode) {
            throw new RuntimeException('Stripe live mode is disabled.');
        }
        if (! str_starts_with($this->secret, 'sk_test_') && ! ($this->allowLiveMode && str_starts_with($this->secret, 'sk_live_'))) {
            throw new RuntimeException('Stripe key mode is not recognised.');
        }
        if (! preg_match('/^sub_[A-Za-z0-9]+$/', $stripeSubscriptionId)) {
            throw new RuntimeException('Stripe subscription identity is invalid.');
        }

        return (new StripeClient($this->secret))->subscriptions->retrieve($stripeSubscriptionId, [
            'expand' => ['latest_invoice', 'latest_invoice.lines.data.price', 'schedule'],
        ]);
    }
}
