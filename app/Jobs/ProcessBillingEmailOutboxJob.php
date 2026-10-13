<?php

namespace App\Jobs;

use App\Mail\BillingLifecycleNotice;
use App\Mail\PaymentConfirmation;
use App\Mail\PaymentFailed;
use App\Mail\SubscriptionActivated;
use App\Mail\SubscriptionCanceled;
use App\Models\BillingEmailOutbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/** Sends one outbox record once; ambiguous delivery outcomes are never blindly retried. */
class ProcessBillingEmailOutboxJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $outboxId) {}

    public function uniqueId(): string
    {
        return (string) $this->outboxId;
    }

    public function handle(): void
    {
        $claimed = DB::transaction(function (): ?BillingEmailOutbox {
            $row = BillingEmailOutbox::query()->whereKey($this->outboxId)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending' || $row->next_attempt_at?->isFuture()) {
                return null;
            }

            $row->forceFill([
                'status' => 'processing',
                'attempts' => $row->attempts + 1,
                'lease_expires_at' => now()->addMinutes(15),
            ])->save();

            return $row;
        }, attempts: 3);

        if (! $claimed) {
            return;
        }

        $originalLocale = app()->getLocale();
        try {
            app()->setLocale($claimed->locale);
            $row = $claimed->load(['customer.user', 'subscription.customer', 'billingInvoice']);
            $customer = $row->customer;
            $subscription = $row->subscription;
            if (! $customer) {
                throw new \RuntimeException('Billing email recipient is unavailable.');
            }

            $mailable = match ($row->notification) {
                'subscription_activated' => $subscription ? new SubscriptionActivated($subscription) : throw new \RuntimeException('Billing email subscription is unavailable.'),
                'payment_received' => ($subscription && $row->billingInvoice?->money('amount_paid'))
                    ? new PaymentConfirmation($subscription, $row->billingInvoice->money('amount_paid'))
                    : throw new \RuntimeException('Paid invoice details are unavailable.'),
                'payment_failed' => ($subscription && $row->billingInvoice?->money('amount_due'))
                    ? new PaymentFailed($subscription, $row->billingInvoice->money('amount_due'))
                    : throw new \RuntimeException('Failed invoice details are unavailable.'),
                'subscription_canceled' => $subscription ? new SubscriptionCanceled($subscription) : throw new \RuntimeException('Billing email subscription is unavailable.'),
                default => new BillingLifecycleNotice($customer, $row->notification, $subscription?->planName()),
            };
            Mail::to($customer->email)->send($mailable);
            BillingEmailOutbox::query()->whereKey($row->id)->where('status', 'processing')->update([
                'status' => 'sent',
                'sent_at' => now(),
                'lease_expires_at' => null,
                'failure_category' => null,
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $transientConnectFailure = $this->isPreDeliveryConnectionFailure($exception);
            $authFailure = $this->isAuthenticationFailure($exception);
            $retry = $transientConnectFailure && $claimed->attempts < 3;
            BillingEmailOutbox::query()->whereKey($claimed->id)->where('status', 'processing')->update([
                'status' => $retry ? 'pending' : ($transientConnectFailure || $authFailure ? 'failed' : 'delivery_unknown'),
                'failure_category' => $retry ? 'transient_connectivity' : ($authFailure ? 'provider_authentication' : ($transientConnectFailure ? 'connectivity_retry_exhausted' : mb_substr(class_basename($exception), 0, 80))),
                'lease_expires_at' => null,
                'next_attempt_at' => $retry ? now()->addMinutes(min(15, 2 ** $claimed->attempts)) : null,
                'updated_at' => now(),
            ]);
            Log::warning('Billing lifecycle email delivery outcome requires review.', [
                'outbox_id' => $claimed->id,
                'notification' => $claimed->notification,
                'category' => class_basename($exception),
            ]);
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    private function isPreDeliveryConnectionFailure(Throwable $exception): bool
    {
        if (! $exception instanceof TransportExceptionInterface) {
            return false;
        }

        return preg_match('/connection refused|connection timed out|failed to connect|could not connect|network is unreachable|temporary failure in name resolution/i', $exception->getMessage()) === 1;
    }

    private function isAuthenticationFailure(Throwable $exception): bool
    {
        if (! $exception instanceof TransportExceptionInterface) {
            return false;
        }

        return preg_match('/authentication failed|could not authenticate|invalid credentials|username and password not accepted/i', $exception->getMessage()) === 1;
    }
}
