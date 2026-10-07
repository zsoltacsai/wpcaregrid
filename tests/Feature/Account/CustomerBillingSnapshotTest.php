<?php

namespace Tests\Feature\Account;

use App\Models\BillingInvoice;
use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerBillingSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_portal_shows_confirmed_recurring_amounts_and_actual_invoice_payment_in_each_currency(): void
    {
        foreach ([
            'HUF' => [899000, 674250],
            'EUR' => [4900, 3675],
            'USD' => [4900, 3675],
            'GBP' => [4900, 3675],
        ] as $currency => [$base, $current]) {
            $customer = Customer::factory()->withUser()->create();
            $subscription = Subscription::factory()->for($customer)->create([
                'currency' => $currency,
                'amount' => $base,
                'base_price_amount' => $base,
                'base_price_currency' => $currency,
                'pricing_market' => $currency === 'HUF' ? 'HU' : 'INTERNATIONAL',
                'discount_type' => 'percent',
                'discount_percent' => '25.00',
                'discount_duration' => 'forever',
                'current_recurring_amount' => $current,
                'current_recurring_currency' => $currency,
                'billing_snapshot_confirmed_at' => now(),
            ]);
            $invoice = BillingInvoice::create([
                'stripe_invoice_id' => 'in_portal_'.$currency,
                'subscription_id' => $subscription->id,
                'customer_id' => $customer->id,
                'subtotal' => $base,
                'discount_total' => $base - $current,
                'tax_total' => 0,
                'total' => $current,
                'amount_paid' => $current,
                'amount_due' => 0,
                'currency' => $currency,
                'invoice_status' => 'paid',
                'stripe_created_at' => now(),
                'paid_at' => now(),
            ]);

            $this->actingAs($customer->user)
                ->get(route('account.subscription'))
                ->assertOk()
                ->assertSee($subscription->basePriceMoney()->format())
                ->assertSee($subscription->currentRecurringMoney()->format())
                ->assertSee('25%')
                ->assertSee($invoice->money('amount_paid')->format());
        }
    }

    public function test_customer_portal_does_not_claim_an_unconfirmed_recurring_amount(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $subscription = Subscription::factory()->for($customer)->create([
            'billing_snapshot_confirmed_at' => null,
            'current_recurring_amount' => null,
        ]);

        $this->actingAs($customer->user)
            ->get(route('account.subscription'))
            ->assertOk()
            ->assertSee(__('account.subscription.amount_unknown'))
            ->assertDontSee('0 Ft');
    }

    public function test_customer_portal_keeps_unexpanded_or_stacked_stripe_discount_amount_unknown(): void
    {
        $customer = Customer::factory()->withUser()->create();
        Subscription::factory()->for($customer)->create([
            'discount_type' => 'unknown',
            'discount_percent' => null,
            'discount_amount' => null,
            'current_recurring_amount' => null,
            'billing_snapshot_confirmed_at' => now(),
        ]);

        $this->actingAs($customer->user)
            ->get(route('account.subscription'))
            ->assertOk()
            ->assertSee(__('account.subscription.amount_unknown'))
            ->assertSee(__('account.subscription.discount_unknown'));
    }
}
