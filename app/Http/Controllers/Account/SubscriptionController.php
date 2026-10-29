<?php

namespace App\Http\Controllers\Account;

use App\Billing\PaymentMethodPortalGateway;
use App\Billing\SubscriptionManagement;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\SubscriptionOperation;
use App\Pricing\BillingInterval;
use App\Pricing\PlanTier;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class SubscriptionController extends Controller
{
    public function index(Request $request): View
    {
        /** @var Customer $customer */
        $customer = $request->attributes->get('customer');
        $subscriptions = $customer->subscriptions()->with('website')->latest('id')->get();

        return view('account.subscription.index', [
            'subscriptions' => $subscriptions,
            'cancellationStatuses' => $this->cancellationStatuses($customer, $subscriptions),
        ]);
    }

    public function show(Request $request, Subscription $subscription): View
    {
        $this->authorize('view', $subscription);
        /** @var Customer $customer */
        $customer = $request->attributes->get('customer');
        $subscriptions = new EloquentCollection([$subscription->load('website')]);

        return view('account.subscription.index', [
            'subscriptions' => $subscriptions,
            'cancellationStatuses' => $this->cancellationStatuses($customer, $subscriptions),
        ]);
    }

    /**
     * Load the newest cancellation-lifecycle operation for these customer-owned subscriptions.
     * This read path never dispatches a billing operation or contacts Stripe.
     *
     * @param  EloquentCollection<int, Subscription>  $subscriptions
     * @return Collection<int, string>
     */
    private function cancellationStatuses(Customer $customer, EloquentCollection $subscriptions): Collection
    {
        $subscriptionIds = $subscriptions->modelKeys();
        if ($subscriptionIds === []) {
            return collect();
        }

        $latestOperationIds = SubscriptionOperation::query()
            ->selectRaw('MAX(id)')
            ->where('customer_id', $customer->id)
            ->whereIn('subscription_id', $subscriptionIds)
            ->whereIn('operation', ['cancel_at_period_end', 'reactivate'])
            ->groupBy('subscription_id');

        $operations = SubscriptionOperation::query()
            ->where('customer_id', $customer->id)
            ->whereIn('subscription_id', $subscriptionIds)
            ->whereIn('id', $latestOperationIds)
            ->get()
            ->keyBy('subscription_id');

        return $subscriptions->mapWithKeys(function (Subscription $subscription) use ($operations): array {
            if ($subscription->billing_source !== 'stripe' || $subscription->isInternal()) {
                return [];
            }

            $operation = $operations->get($subscription->id);
            if ($operation === null) {
                return [];
            }

            if ($operation->status === 'reconciliation_required') {
                return [$subscription->id => 'reconciliation_required'];
            }

            $isCancellation = $operation->operation === 'cancel_at_period_end';
            $targetCancelAtPeriodEnd = $isCancellation;
            $snapshotMatchesOperation = data_get($operation->request_snapshot, 'cancel_at_period_end') === $targetCancelAtPeriodEnd;

            if (in_array($operation->status, ['requested', 'processing', 'awaiting_confirmation'], true)) {
                if (! $snapshotMatchesOperation) {
                    return [$subscription->id => 'reconciliation_required'];
                }

                return [$subscription->id => $isCancellation ? 'pending' : 'reactivation_pending'];
            }

            if ($operation->status !== 'confirmed') {
                return [$subscription->id => 'reconciliation_required'];
            }

            $confirmedForCurrentSubscription = $snapshotMatchesOperation
                && $operation->confirmed_at !== null
                && $subscription->cancel_at_period_end === $targetCancelAtPeriodEnd;

            if (! $confirmedForCurrentSubscription) {
                return [$subscription->id => 'reconciliation_required'];
            }

            return [$subscription->id => $isCancellation ? 'confirmed' : 'reactivation_confirmed'];
        });
    }

    public function cancel(Request $request, Subscription $subscription, SubscriptionManagement $management): RedirectResponse
    {
        $this->authorize('manage', $subscription);
        try {
            $management->cancelAtPeriodEnd($subscription, $request->attributes->get('customer'), $request->user()->id);

            return back()->with('status', __('account.subscription.cancel_requested'));
        } catch (Throwable $exception) {
            Log::warning('Customer subscription cancellation requires reconciliation.', ['subscription' => $subscription->uuid, 'category' => class_basename($exception)]);

            return back()->withErrors(['subscription' => __('account.subscription.operation_pending')]);
        }
    }

    public function reactivate(Request $request, Subscription $subscription, SubscriptionManagement $management): RedirectResponse
    {
        $this->authorize('manage', $subscription);
        try {
            $management->reactivate($subscription, $request->attributes->get('customer'), $request->user()->id);

            return back()->with('status', __('account.subscription.reactivation_requested'));
        } catch (Throwable $exception) {
            Log::warning('Customer subscription reactivation requires reconciliation.', ['subscription' => $subscription->uuid, 'category' => class_basename($exception)]);

            return back()->withErrors(['subscription' => __('account.subscription.operation_pending')]);
        }
    }

    public function change(Request $request, Subscription $subscription, SubscriptionManagement $management): RedirectResponse
    {
        $this->authorize('manage', $subscription);
        $validated = $request->validate([
            'plan' => ['required', 'string', 'in:'.implode(',', array_column(PlanTier::cases(), 'value'))],
            'interval' => ['required', 'string', 'in:'.implode(',', array_column(BillingInterval::cases(), 'value'))],
        ]);

        try {
            $operation = $management->changePlan(
                $subscription,
                $request->attributes->get('customer'),
                $subscription->product_type,
                PlanTier::from($validated['plan']),
                BillingInterval::from($validated['interval']),
                $request->user()->id,
            );

            if ($operation->status === 'awaiting_confirmation'
                && ($operation->operation !== 'downgrade' || is_string(data_get($operation->request_snapshot, 'provider_schedule_id')))) {
                return back()->with('status', $operation->operation === 'downgrade'
                    ? __('account.subscription.change_scheduled')
                    : __('account.subscription.change_awaiting_payment'));
            }

            return back()->withErrors(['subscription' => __('account.subscription.operation_pending')]);
        } catch (Throwable $exception) {
            Log::warning('Customer subscription plan change requires reconciliation.', ['subscription' => $subscription->uuid, 'category' => class_basename($exception)]);

            return back()->withErrors(['subscription' => __('account.subscription.operation_pending')]);
        }
    }

    public function paymentMethodPortal(Request $request, PaymentMethodPortalGateway $portal): RedirectResponse
    {
        $customer = $request->attributes->get('customer');
        if (! is_string($customer?->stripe_customer_id) || $customer->stripe_customer_id === '') {
            return back()->withErrors(['subscription' => __('account.subscription.payment_method_unavailable')]);
        }
        try {
            $url = $portal->createSession($customer->stripe_customer_id, route('account.subscription'));

            return redirect()->away($url);
        } catch (Throwable $exception) {
            Log::warning('Stripe payment method portal is unavailable.', ['customer_uuid' => $customer->uuid, 'category' => class_basename($exception)]);

            return back()->withErrors(['subscription' => __('account.subscription.payment_method_unavailable')]);
        }
    }
}
