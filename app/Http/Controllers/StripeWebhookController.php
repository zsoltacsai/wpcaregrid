<?php

namespace App\Http\Controllers;

use App\Checkout\AcceptStripeWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives Stripe events. Only signed events are processed; this is how a
 * payment is confirmed, never the browser's return to the success page.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, AcceptStripeWebhook $webhook): Response
    {
        $contentLength = $request->server('CONTENT_LENGTH');
        if (is_string($contentLength) && ctype_digit($contentLength) && (int) $contentLength > AcceptStripeWebhook::MAX_PAYLOAD_BYTES) {
            return response('Webhook payload exceeds the supported limit.', 413);
        }

        $status = $webhook->accept($request->getContent(), (string) $request->header('Stripe-Signature'));

        return response(match ($status) {
            204 => '',
            400 => 'Invalid signature or event mode.',
            413 => 'Webhook payload exceeds the supported limit.',
            default => 'Webhook processing failed.',
        }, $status);
    }
}
