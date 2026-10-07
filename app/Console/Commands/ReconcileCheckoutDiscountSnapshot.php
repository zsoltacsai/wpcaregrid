<?php

namespace App\Console\Commands;

use App\Billing\CheckoutDiscountSnapshotSync;
use App\Models\CheckoutIntent;
use Illuminate\Console\Command;
use Stripe\StripeClient;
use Throwable;

/** Reconcile a missing checkout discount snapshot from Stripe's current read-only session state. */
class ReconcileCheckoutDiscountSnapshot extends Command
{
    protected $signature = 'billing:reconcile-checkout-discount {intent_uuid}';

    protected $description = 'Reconcile a paid checkout discount snapshot from Stripe';

    public function handle(CheckoutDiscountSnapshotSync $sync): int
    {
        $secret = (string) config('services.stripe.secret');
        $keyMode = str_starts_with($secret, 'sk_test_') ? 'test' : (str_starts_with($secret, 'sk_live_') ? 'live' : null);
        if (! $keyMode) {
            $this->error('Stripe mode cannot be verified; no billing data changed.');

            return self::FAILURE;
        }

        $intent = CheckoutIntent::query()->where('uuid', $this->argument('intent_uuid'))->first();
        if (! $intent || ! $intent->stripe_checkout_session_id) {
            $this->error('Checkout intent was not found or has no Stripe session.');

            return self::FAILURE;
        }

        try {
            $session = (new StripeClient($secret))->checkout->sessions->retrieve($intent->stripe_checkout_session_id);
        } catch (Throwable) {
            $this->error('Stripe could not confirm the checkout session; no billing data changed.');

            return self::FAILURE;
        }

        if ((bool) ($session->livemode ?? true) !== ($keyMode === 'live')) {
            $this->error('Stripe session mode does not match the configured key; no billing data changed.');

            return self::FAILURE;
        }

        if (! $sync->apply($session, $intent)) {
            $this->error('Stripe did not provide a matching paid discount snapshot; no billing data changed.');

            return self::FAILURE;
        }

        $this->info('Stripe-confirmed checkout discount snapshot reconciled.');

        return self::SUCCESS;
    }
}
