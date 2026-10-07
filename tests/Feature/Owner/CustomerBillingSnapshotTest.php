<?php

namespace Tests\Feature\Owner;

use App\Models\BillingInvoice;
use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

class CustomerBillingSnapshotTest extends TestCase
{
    use ActsAsOwner;
    use RefreshDatabase;

    public function test_owner_customer_view_distinguishes_recurring_price_initial_discount_and_paid_invoice(): void
    {
        $this->asOwner();
        $customer = Customer::factory()->withUser()->create();
        $subscription = Subscription::factory()->for($customer)->create([
            'product_type' => 'wordpress', 'plan' => 'business', 'interval' => 'annual',
            'currency' => 'EUR', 'amount' => 89000,
            'base_price_amount' => 89000, 'base_price_currency' => 'EUR',
            'initial_discount_type' => 'percent', 'initial_discount_percent' => '10.00',
            'initial_discount_duration' => 'once', 'initial_discount_snapshot_confirmed_at' => now(),
            'current_recurring_amount' => 89000, 'current_recurring_currency' => 'EUR',
            'billing_snapshot_confirmed_at' => now(),
        ]);
        $invoice = BillingInvoice::create([
            'stripe_invoice_id' => 'in_owner_once_snapshot', 'subscription_id' => $subscription->id,
            'customer_id' => $customer->id, 'stripe_subscription_id' => 'sub_owner_once_snapshot',
            'subtotal' => 89000, 'discount_total' => 8900, 'tax_total' => 0,
            'total' => 80100, 'amount_paid' => 80100, 'amount_due' => 0,
            'currency' => 'EUR', 'invoice_status' => 'paid', 'billing_reason' => 'subscription_create',
            'applied_discount_type' => 'percent', 'applied_discount_percent' => '10.00',
            'applied_discount_duration' => 'once', 'stripe_created_at' => now(), 'paid_at' => now(),
        ]);

        $this->get(route('owner.customers.show', $customer))
            ->assertOk()
            ->assertSee(__('owner.customers.current_recurring'))
            ->assertSee($subscription->currentRecurringMoney()->format())
            ->assertSee(__('owner.customers.initial_invoice_discount'))
            ->assertSee('10%')
            ->assertSee(__('owner.customers.invoice_history'))
            ->assertSee($invoice->money('amount_paid')->format())
            ->assertSee(__('account.subscription.invoice_statuses.paid'));
    }
}
