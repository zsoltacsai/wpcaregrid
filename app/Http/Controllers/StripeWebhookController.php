<?php

namespace App\Http\Controllers;

use App\Checkout\ProcessStripeWebhookInbox;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Receives Stripe events. Only signed events are processed; this is how a
 * payment is confirmed, never the browser's return to the success page.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, ProcessStripeWebhookInbox $inbox): Response
    {
        $secret = config('services.stripe.webhook_secret');

        if (! $secret) {
            Log::error('Stripe webhook received but STRIPE_WEBHOOK_SECRET is not set.');

            return response('Webhook not configured.', 500);
        }

        try {
            $payload = $request->getContent();
            $event = Webhook::constructEvent(
                $payload,
                (string) $request->header('Stripe-Signature'),
                $secret,
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response('Invalid signature.', 400);
        }

        if (! is_bool($event->livemode ?? null)) {
            return response('Invalid event mode.', 400);
        }

        $mode = config('services.stripe.mode');
        if (! in_array($mode, ['test', 'live'], true) || ($mode === 'live' && ! config('services.stripe.allow_live_mode'))) {
            Log::error('Stripe webhook mode configuration is invalid.');

            return response('Webhook not configured.', 500);
        }
        $secretKey = (string) config('services.stripe.secret');
        $keyMode = str_starts_with($secretKey, 'sk_live_') ? 'live' : (str_starts_with($secretKey, 'sk_test_') ? 'test' : null);
        if (($keyMode !== null && $keyMode !== $mode) || (($mode === 'live') !== (bool) $event->livemode)) {
            return response('Invalid event mode.', 400);
        }

        $receipt = $inbox->receive($event, $payload);

        try {
            $inbox->process($receipt->id);
        } catch (\Throwable $exception) {
            Log::warning('Stripe webhook processing failed; the signed receipt is retained for retry.', [
                'event_id' => $event->id,
                'exception' => class_basename($exception),
            ]);

            return response('Webhook processing failed.', 500);
        }

        return response('', 204);
    }
}
