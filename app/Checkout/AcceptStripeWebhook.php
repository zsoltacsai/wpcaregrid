<?php

namespace App\Checkout;

use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/** Verifies the Stripe signature and mode before accepting any event effects. */
final class AcceptStripeWebhook
{
    public const MAX_PAYLOAD_BYTES = 2 * 1024 * 1024;

    public function __construct(private readonly ProcessStripeWebhookInbox $inbox) {}

    public function accept(string $payload, string $signature): int
    {
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            return 413;
        }

        $secret = config('services.stripe.webhook_secret');

        if (! $secret) {
            Log::error('Stripe webhook received but STRIPE_WEBHOOK_SECRET is not set.');

            return 500;
        }

        try {
            // Stripe's signed timestamp is part of freshness protection; keep
            // the SDK's documented five-minute tolerance explicit.
            $event = Webhook::constructEvent($payload, $signature, $secret, 300);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return 400;
        }

        if (! is_bool($event->livemode ?? null)) {
            return 400;
        }

        $mode = config('services.stripe.mode');
        if (! in_array($mode, ['test', 'live'], true) || ($mode === 'live' && ! config('services.stripe.allow_live_mode'))) {
            Log::error('Stripe webhook mode configuration is invalid.');

            return 500;
        }
        $secretKey = (string) config('services.stripe.secret');
        $keyMode = str_starts_with($secretKey, 'sk_live_') ? 'live' : (str_starts_with($secretKey, 'sk_test_') ? 'test' : null);
        if (($keyMode !== null && $keyMode !== $mode) || (($mode === 'live') !== (bool) $event->livemode)) {
            return 400;
        }

        $receipt = $this->inbox->receive($event, $payload);

        try {
            $this->inbox->process($receipt->id);
        } catch (\Throwable $exception) {
            Log::warning('Stripe webhook processing failed; the signed receipt is retained for retry.', [
                'event_id' => $event->id,
                'exception' => class_basename($exception),
            ]);

            return 500;
        }

        return 204;
    }
}
