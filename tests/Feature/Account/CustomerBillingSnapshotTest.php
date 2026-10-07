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

    public function test_customer_portal_displays_exact_fractional_forever_discount_and_paid_invoice_amount(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $subscription = Subscription::factory()->for($customer)->create([
            'product_type' => 'wordpress', 'plan' => 'business', 'interval' => 'annual',
            'currency' => 'EUR', 'amount' => 89000,
            'base_price_amount' => 89000, 'base_price_currency' => 'EUR',
            'pricing_market' => 'INTERNATIONAL',
            'discount_type' => 'percent', 'discount_percent' => '25.00', 'discount_duration' => 'forever',
            'current_recurring_amount' => 66750, 'current_recurring_currency' => 'EUR',
            'billing_snapshot_confirmed_at' => now(),
            'initial_discount_type' => 'percent', 'initial_discount_percent' => '25.00',
            'initial_discount_duration' => 'forever', 'initial_invoice_discount_total' => 22250,
            'initial_discount_snapshot_confirmed_at' => now(),
        ]);
        $invoice = BillingInvoice::create([
            'stripe_invoice_id' => 'in_portal_forever_fractional',
            'subscription_id' => $subscription->id,
            'customer_id' => $customer->id,
            'subtotal' => 89000,
            'discount_total' => 22250,
            'tax_total' => 0,
            'total' => 66750,
            'amount_paid' => 66750,
            'amount_due' => 0,
            'currency' => 'EUR',
            'invoice_status' => 'paid',
            'applied_discount_type' => 'percent',
            'applied_discount_percent' => '25.00',
            'applied_discount_duration' => 'forever',
            'stripe_created_at' => now(),
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($customer->user)
            ->get(route('account.subscription'))
            ->assertOk()
            ->assertSee('€890')
            ->assertSee('€667.50')
            ->assertSee('25%')
            ->assertSee(__('account.subscription.discount_durations.forever'))
            ->assertSee($invoice->money('amount_paid')->format())
            ->assertDontSee('€668');

        $this->assertSame(2, substr_count($response->getContent(), '€667.50'));
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

    public function test_customer_portal_separates_a_once_initial_invoice_discount_from_future_recurring_price(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $subscription = Subscription::factory()->for($customer)->create([
            'product_type' => 'wordpress', 'plan' => 'business', 'interval' => 'annual',
            'currency' => 'EUR', 'amount' => 89000,
            'base_price_amount' => 89000, 'base_price_currency' => 'EUR',
            'initial_discount_type' => 'percent', 'initial_discount_percent' => '10.00',
            'initial_discount_duration' => 'once', 'initial_stripe_coupon_id' => 'coupon_once_snapshot',
            'initial_discount_snapshot_confirmed_at' => now(),
            'current_recurring_amount' => 89000, 'current_recurring_currency' => 'EUR',
            'billing_snapshot_confirmed_at' => now(),
        ]);
        $invoice = BillingInvoice::create([
            'stripe_invoice_id' => 'in_once_portal', 'subscription_id' => $subscription->id,
            'customer_id' => $customer->id, 'stripe_subscription_id' => 'sub_once_portal',
            'subtotal' => 89000, 'discount_total' => 8900, 'tax_total' => 0,
            'total' => 80100, 'amount_paid' => 80100, 'amount_due' => 0,
            'currency' => 'EUR', 'invoice_status' => 'paid', 'billing_reason' => 'subscription_create',
            'applied_discount_type' => 'percent', 'applied_discount_percent' => '10.00',
            'applied_discount_duration' => 'once', 'stripe_coupon_id' => 'coupon_once_snapshot',
            'stripe_created_at' => now(), 'paid_at' => now(),
        ]);

        $response = $this->actingAs($customer->user)->get(route('account.subscription'))
            ->assertOk()
            ->assertSee(__('account.subscription.initial_invoice_discount'))
            ->assertSee('10%')
            ->assertSee(__('account.subscription.discount_durations.once'))
            ->assertSee($subscription->currentRecurringMoney()->format())
            ->assertSee($invoice->money('amount_paid')->format());

        $this->assertSame(89000, $subscription->current_recurring_amount);
        $this->assertNotSame($invoice->amount_paid, $subscription->current_recurring_amount);
    }
}
