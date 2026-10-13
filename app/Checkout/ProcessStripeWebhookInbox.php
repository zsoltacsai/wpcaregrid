<?php

namespace App\Checkout;

use App\Models\StripeEvent;
use App\Models\StripeWebhookInbox;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Stripe\Event;

final class ProcessStripeWebhookInbox
{
    public function __construct(private readonly StripeWebhookHandler $handler) {}

    public function process(int $id): bool
    {
        $receipt = DB::transaction(function () use ($id): ?StripeWebhookInbox {
            $receipt = StripeWebhookInbox::query()->lockForUpdate()->find($id);

            if (! $receipt || $receipt->status === StripeWebhookInbox::STATUS_PROCESSED) {
                return null;
            }

            if ($receipt->status === StripeWebhookInbox::STATUS_PROCESSING && $receipt->lease_expires_at?->isFuture()) {
                return null;
            }

            $receipt->forceFill([
                'status' => StripeWebhookInbox::STATUS_PROCESSING,
                'attempts' => $receipt->attempts + 1,
                'lease_expires_at' => now()->addMinutes(5),
                'last_error_category' => null,
            ])->save();

            return $receipt;
        });

        if (! $receipt) {
            return false;
        }

        try {
            $payload = json_decode($receipt->payload, true, 64, JSON_THROW_ON_ERROR);
            $event = Event::constructFrom($payload);

            // Existing transactional event handling remains the authority for effects.
            // If the process dies after this commit but before inbox acknowledgement,
            // StripeEvent makes the replay a harmless no-op.
            $this->handler->handle($event);

            $receipt->forceFill([
                'status' => StripeWebhookInbox::STATUS_PROCESSED,
                'processed_at' => now(),
                'lease_expires_at' => null,
                'last_error_category' => null,
            ])->save();

            return true;
        } catch (\Throwable $exception) {
            $receipt->forceFill([
                'status' => StripeWebhookInbox::STATUS_FAILED,
                'lease_expires_at' => null,
                'last_error_category' => class_basename($exception),
            ])->save();

            throw $exception;
        }
    }

    /** Persist a signed event exactly once before acknowledging or applying its effects. */
    public function receive(Event $event, string $payload): StripeWebhookInbox
    {
        $attributes = [
            'stripe_event_id' => $event->id,
            'type' => $event->type,
            'object_id' => $event->data->object->id ?? null,
            'livemode' => (bool) ($event->livemode ?? false),
            'payload' => $payload,
            'status' => StripeWebhookInbox::STATUS_RECEIVED,
            'attempts' => 0,
            'stripe_created_at' => isset($event->created) ? Carbon::createFromTimestamp($event->created) : null,
        ];

        try {
            return StripeWebhookInbox::create($attributes);
        } catch (UniqueConstraintViolationException) {
            return StripeWebhookInbox::where('stripe_event_id', $event->id)->firstOrFail();
        }
    }

    public function reconcileProcessedReceipt(int $id): void
    {
        $receipt = StripeWebhookInbox::find($id);
        if ($receipt && StripeEvent::where('stripe_event_id', $receipt->stripe_event_id)->exists()) {
            $receipt->forceFill([
                'status' => StripeWebhookInbox::STATUS_PROCESSED,
                'processed_at' => now(),
                'lease_expires_at' => null,
            ])->save();
        }
    }
}
