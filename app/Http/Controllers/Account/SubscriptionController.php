<?php

namespace App\Http\Controllers\Account;

use App\Billing\PaymentMethodPortalGateway;
use App\Billing\SubscriptionManagement;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Pricing\BillingInterval;
use App\Pricing\PlanTier;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class SubscriptionController extends Controller
{
    public function index(Request $request): View
    {
        return view('account.subscription.index', [
            'subscriptions' => $request->attributes->get('customer')->subscriptions()->with('website')->latest('id')->get(),
        ]);
    }

    public function show(Subscription $subscription): View
    {
        $this->authorize('view', $subscription);

        return view('account.subscription.index', ['subscriptions' => collect([$subscription->load('website')])]);
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

            if ($operation->status === 'awaiting_confirmation') {
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
