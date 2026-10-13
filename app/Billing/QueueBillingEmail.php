<?php

namespace App\Billing;

use App\Jobs\ProcessBillingEmailOutboxJob;
use App\Models\BillingEmailOutbox;
use App\Models\BillingInvoice;
use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Database\UniqueConstraintViolationException;

/** Durable, idempotent ledger for subscription lifecycle email. */
final class QueueBillingEmail
{
    private const NOTIFICATIONS = [
        'subscription_activated', 'payment_received', 'payment_failed', 'subscription_canceled',
        'cancellation_scheduled', 'subscription_reactivated', 'upgrade_completed', 'downgrade_scheduled',
        'payment_recovered', 'grace_started', 'grace_reminder', 'invoice_available', 'billing_action_required',
    ];

    /** @param array<string, scalar|null> $payload */
    public function enqueue(
        string $eventKey,
        string $notification,
        Customer $customer,
        ?Subscription $subscription = null,
        ?BillingInvoice $invoice = null,
        array $payload = [],
    ): BillingEmailOutbox {
        if (! in_array($notification, self::NOTIFICATIONS, true)) {
            throw new \InvalidArgumentException('Unknown billing email notification.');
        }
        try {
            $outbox = BillingEmailOutbox::create([
                'event_key' => $eventKey,
                'customer_id' => $customer->id,
                'subscription_id' => $subscription?->id,
                'billing_invoice_id' => $invoice?->id,
                'notification' => $notification,
                'locale' => $customer->mailLocale(),
                'payload' => $payload ?: null,
                'status' => 'pending',
                'attempts' => 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            $outbox = BillingEmailOutbox::where('event_key', $eventKey)->firstOrFail();
        }

        if ($outbox->status === 'pending') {
            ProcessBillingEmailOutboxJob::dispatch($outbox->id)->afterCommit();
        }

        return $outbox;
    }
}
