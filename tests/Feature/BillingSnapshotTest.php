<?php

namespace Tests\Feature;

use App\Billing\CheckoutDiscountSnapshot;
use App\Billing\SubscriptionBillingSnapshot;
use App\Checkout\CheckoutStatus;
use App\Models\BillingInvoice;
use App\Models\CheckoutIntent;
use App\Models\Promotion;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SendsStripeWebhooks;
use Tests\TestCase;

class BillingSnapshotTest extends TestCase
{
    use RefreshDatabase, SendsStripeWebhooks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useWebhookSecret();
    }

    private function intent(array $overrides = []): CheckoutIntent
    {
        return CheckoutIntent::create($overrides + [
            'status' => CheckoutStatus::CheckoutCreated,
            'email' => 'snapshot@example.test',
            'first_name' => 'Snapshot',
            'last_name' => 'Buyer',
            'country' => 'HU',
            'product_type' => 'wordpress',
            'plan' => 'business',
            'interval' => 'monthly',
            'market' => 'HU',
            'currency' => 'HUF',
            'amount' => 1899000,
            'tax_treatment' => 'final_price_no_vat_added',
            'stripe_price_id' => 'price_wordpress_business_monthly_HUF',
            'stripe_checkout_session_id' => 'cs_snapshot',
        ]);
    }

    private function subscription(CheckoutIntent $intent, array $overrides = [], string $status = 'active', ?string $eventId = null)
    {
        return $this->stripeEvent('customer.subscription.updated', array_replace_recursive([
            'id' => 'sub_snapshot',
            'object' => 'subscription',
            'customer' => 'cus_snapshot',
            'status' => $status,
            'metadata' => ['checkout_intent' => $intent->uuid],
            'items' => ['data' => [[
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'price' => ['id' => 'price_wordpress_business_monthly_HUF', 'unit_amount' => 1899000, 'currency' => 'huf'],
            ]]],
        ], $overrides), $eventId);
    }

    public function test_subscription_webhook_persists_stripe_price_and_percent_discount_terms(): void
    {
        $intent = $this->intent();
        $this->subscription($intent, ['discounts' => [[
            'id' => 'di_snapshot',
            'start' => now()->timestamp,
            'promotion_code' => ['id' => 'promo_snapshot'],
            'source' => ['type' => 'coupon', 'coupon' => ['id' => 'coupon_snapshot', 'percent_off' => 25, 'duration' => 'forever']],
        ]]])->assertStatus(204);

        $subscription = Subscription::sole();
        $this->assertSame(1899000, $subscription->base_price_amount);
        $this->assertSame('HUF', $subscription->base_price_currency);
        $this->assertSame('HU', $subscription->pricing_market);
        $this->assertSame('coupon_snapshot', $subscription->stripe_coupon_id);
        $this->assertSame('promo_snapshot', $subscription->stripe_promotion_code_id);
        $this->assertSame('percent', $subscription->discount_type);
        $this->assertSame('25.00', $subscription->discount_percent);
        $this->assertSame(1424250, $subscription->current_recurring_amount);
        $this->assertSame('HUF', $subscription->current_recurring_currency);
        $this->assertNotNull($subscription->billing_snapshot_confirmed_at);
    }

    public function test_once_discount_is_not_misrepresented_as_a_recurring_discount(): void
    {
        $intent = $this->intent();
        $this->subscription($intent, ['discount' => [
            'id' => 'di_once',
            'coupon' => ['id' => 'coupon_once', 'percent_off' => 10, 'duration' => 'once'],
        ]])->assertStatus(204);

        $subscription = Subscription::sole();
        $this->assertSame('once', $subscription->discount_duration);
        $this->assertSame(1899000, $subscription->current_recurring_amount);
    }

    public function test_legacy_repeating_coupon_is_preserved_and_remains_recurring_until_its_stripe_end(): void
    {
        $intent = $this->intent();
        $this->subscription($intent, ['discount' => [
            'id' => 'di_repeating',
            'coupon' => ['id' => 'coupon_repeating', 'percent_off' => 12.5, 'duration' => 'repeating', 'duration_in_months' => 3],
        ]])->assertStatus(204);

        $subscription = Subscription::sole();
        $this->assertSame('repeating', $subscription->discount_duration);
        $this->assertSame(3, $subscription->discount_duration_months);
        $this->assertSame(1661625, $subscription->current_recurring_amount);
    }

    public function test_unknown_or_currency_mismatched_discount_does_not_claim_a_recurring_total(): void
    {
        $intent = $this->intent();
        $this->subscription($intent, ['discounts' => [['source' => ['coupon' => ['id' => 'coupon_unknown']]]]])->assertStatus(204);
        $this->assertNull(Subscription::sole()->current_recurring_amount);

        Subscription::query()->delete();
        $intent = $this->intent(['stripe_checkout_session_id' => 'cs_snapshot_2']);
        $this->subscription($intent, ['discounts' => [[
            'source' => ['coupon' => ['id' => 'coupon_eur', 'amount_off' => 1000, 'currency' => 'eur', 'duration' => 'forever']],
        ]]], eventId: 'evt_currency_mismatch')->assertStatus(204);
        $this->assertNull(Subscription::sole()->current_recurring_amount);
    }

    public function test_multiple_external_discounts_never_claim_a_partial_recurring_total(): void
    {
        $intent = $this->intent();
        $this->subscription($intent, ['discounts' => [
            ['source' => ['coupon' => ['id' => 'coupon_one', 'percent_off' => 10, 'duration' => 'forever']]],
            ['source' => ['coupon' => ['id' => 'coupon_two', 'percent_off' => 5, 'duration' => 'forever']]],
        ]])->assertStatus(204);

        $subscription = Subscription::sole();
        $this->assertSame('unknown', $subscription->discount_type);
        $this->assertNull($subscription->current_recurring_amount);
    }

    public function test_percent_snapshot_arithmetic_is_safe_at_integer_limit_and_rejects_overflowing_amount_strings(): void
    {
        $snapshot = app(SubscriptionBillingSnapshot::class)->fromStripe(
            (object) ['discount' => ['coupon' => ['percent_off' => 10, 'duration' => 'forever']]],
            null,
            (object) ['unit_amount' => PHP_INT_MAX, 'currency' => 'usd'],
        );

        $this->assertSame(8301034833169298226, $snapshot['current_recurring_amount']);
        $this->assertIsInt($snapshot['current_recurring_amount']);

        $overflow = app(SubscriptionBillingSnapshot::class)->fromStripe(
            (object) [],
            null,
            (object) ['unit_amount' => '999999999999999999999999999999', 'currency' => 'usd'],
        );

        $this->assertNull($overflow['base_price_amount']);
        $this->assertNull($overflow['current_recurring_amount']);
    }

    public function test_invoice_amount_overflow_is_recorded_as_unknown_instead_of_coerced_or_wrapped(): void
    {
        $this->stripeEvent('invoice.finalized', [
            'id' => 'in_overflow',
            'object' => 'invoice',
            'currency' => 'usd',
            'subtotal' => '999999999999999999999999999999',
            'total_discount_amounts' => [
                ['amount' => PHP_INT_MAX],
                ['amount' => 1],
            ],
            'total' => 1,
            'amount_due' => 1,
            'status' => 'open',
            'created' => now()->timestamp,
        ], 'evt_invoice_overflow')->assertStatus(204);

        $invoice = BillingInvoice::sole();
        $this->assertNull($invoice->subtotal);
        $this->assertNull($invoice->discount_total);
        $this->assertSame(1, $invoice->total);
    }

    public function test_invoice_webhook_persists_actual_totals_and_replay_does_not_duplicate(): void
    {
        $intent = $this->intent();
        $this->subscription($intent)->assertStatus(204);
        $invoice = [
            'id' => 'in_snapshot',
            'object' => 'invoice',
            'customer' => 'cus_snapshot',
            'currency' => 'huf',
            'status' => 'paid',
            'billing_reason' => 'subscription_cycle',
            'created' => now()->timestamp,
            'subtotal' => 1899000,
            'total_discount_amounts' => [['amount' => 474750]],
            'total_taxes' => [['amount' => 0]],
            'total' => 1424250,
            'amount_paid' => 1424250,
            'amount_due' => 0,
            'payment_intent' => 'pi_snapshot',
            'parent' => ['subscription_details' => ['subscription' => 'sub_snapshot', 'metadata' => ['checkout_intent' => $intent->uuid]]],
            'status_transitions' => ['finalized_at' => now()->timestamp, 'paid_at' => now()->timestamp],
        ];

        $this->stripeEvent('invoice.paid', $invoice, 'evt_invoice_snapshot')->assertStatus(204);
        $this->stripeEvent('invoice.paid', $invoice, 'evt_invoice_snapshot')->assertStatus(204);

        $snapshot = BillingInvoice::sole();
        $this->assertSame(Subscription::sole()->id, $snapshot->subscription_id);
        $this->assertSame('sub_snapshot', $snapshot->stripe_subscription_id);
        $this->assertSame('pi_snapshot', $snapshot->stripe_payment_intent_id);
        $this->assertSame(1899000, $snapshot->subtotal);
        $this->assertSame(474750, $snapshot->discount_total);
        $this->assertSame(0, $snapshot->tax_total);
        $this->assertSame(1424250, $snapshot->total);
        $this->assertSame(1424250, $snapshot->amount_paid);
        $this->assertSame('HUF', $snapshot->currency);
        $this->assertSame('paid', $snapshot->invoice_status);
        $this->assertNotNull($snapshot->payment_confirmation_notified_at);
        $this->assertSame(CheckoutStatus::Completed, $intent->refresh()->status);
    }

    public function test_invoice_arriving_before_subscription_is_attached_later(): void
    {
        $intent = $this->intent();
        $invoice = [
            'id' => 'in_early', 'object' => 'invoice', 'customer' => 'cus_snapshot', 'currency' => 'eur',
            'status' => 'open', 'created' => now()->timestamp, 'subtotal' => 4900, 'total' => 4900,
            'amount_due' => 4900, 'parent' => ['subscription_details' => ['subscription' => 'sub_late']],
        ];
        $this->stripeEvent('invoice.finalized', $invoice)->assertStatus(204);
        $this->subscription($intent, ['id' => 'sub_late', 'items' => ['data' => [[
            'price' => ['id' => 'price_wordpress_business_monthly_HUF', 'unit_amount' => 1899000, 'currency' => 'huf'],
        ]]]], eventId: 'evt_late_sub')->assertStatus(204);

        $this->assertSame(Subscription::sole()->id, BillingInvoice::sole()->subscription_id);
    }

    public function test_invoice_for_another_stripe_customer_cannot_attach_to_a_local_subscription(): void
    {
        $intent = $this->intent();
        $this->subscription($intent)->assertStatus(204);
        $subscription = Subscription::sole();
        $originalCustomerId = $subscription->customer_id;

        $invoice = [
            'id' => 'in_foreign', 'object' => 'invoice', 'customer' => 'cus_foreign', 'currency' => 'huf',
            'status' => 'paid', 'billing_reason' => 'subscription_cycle', 'created' => now()->timestamp,
            'subtotal' => 1, 'total' => 1, 'amount_paid' => 1, 'amount_due' => 0,
            'parent' => ['subscription_details' => ['subscription' => 'sub_snapshot']],
            'status_transitions' => ['paid_at' => now()->timestamp],
        ];
        $this->stripeEvent('invoice.paid', $invoice, 'evt_foreign_invoice')->assertStatus(204);

        $this->assertDatabaseMissing('billing_invoices', ['stripe_invoice_id' => 'in_foreign']);
        $this->assertSame($originalCustomerId, $subscription->refresh()->customer_id);
        $this->assertSame('cus_snapshot', $subscription->stripe_customer_id);
    }

    public function test_same_second_invoice_events_converge_and_paid_cannot_be_downgraded(): void
    {
        $intent = $this->intent();
        $this->subscription($intent)->assertStatus(204);
        $created = now()->timestamp;
        $invoice = [
            'id' => 'in_tie', 'object' => 'invoice', 'customer' => 'cus_snapshot', 'currency' => 'huf',
            'status' => 'open', 'created' => $created, 'subtotal' => 1899000, 'total' => 1899000,
            'amount_paid' => 0, 'amount_due' => 1899000,
            'parent' => ['subscription_details' => ['subscription' => 'sub_snapshot']],
        ];
        $this->stripeEvent('invoice.finalized', $invoice, 'evt_zz_open', $created)->assertStatus(204);
        $this->stripeEvent('invoice.paid', array_replace($invoice, ['status' => 'paid', 'amount_paid' => 1899000, 'amount_due' => 0]), 'evt_aa_paid', $created)->assertStatus(204);
        $this->stripeEvent('invoice.payment_failed', $invoice, 'evt_zzzz_failed', $created)->assertStatus(204);

        $snapshot = BillingInvoice::where('stripe_invoice_id', 'in_tie')->sole();
        $this->assertSame('paid', $snapshot->invoice_status);
        $this->assertSame('evt_aa_paid', $snapshot->last_stripe_event_id);
    }

    public function test_paid_renewal_refreshes_recurring_amount_and_clears_expired_discount(): void
    {
        $intent = $this->intent();
        $this->subscription($intent, ['discounts' => [[
            'source' => ['coupon' => ['id' => 'coupon_renewal', 'percent_off' => 10, 'duration' => 'once']],
        ]]])->assertStatus(204);

        $invoice = [
            'object' => 'invoice', 'customer' => 'cus_snapshot', 'currency' => 'huf', 'status' => 'paid',
            'billing_reason' => 'subscription_cycle', 'created' => now()->timestamp,
            'subtotal' => 1899000, 'total_discount_amounts' => [], 'total_taxes' => [['amount' => 0]],
            'total' => 1899000, 'amount_paid' => 1899000, 'amount_due' => 0,
            'parent' => ['subscription_details' => ['subscription' => 'sub_snapshot']],
            'status_transitions' => ['paid_at' => now()->timestamp],
        ];
        $this->stripeEvent('invoice.paid', ['id' => 'in_renewal', ...$invoice], 'evt_renewal')->assertStatus(204);

        $subscription = Subscription::sole();
        $this->assertSame(1899000, $subscription->current_recurring_amount);
        $this->assertNull($subscription->discount_type);
        $this->assertNull($subscription->stripe_coupon_id);
    }

    public function test_paid_checkout_session_snapshots_once_discount_without_lowering_future_recurring_amount(): void
    {
        $promotion = Promotion::create([
            'code' => 'ONCE10', 'stripe_coupon_id' => 'coupon_once_10', 'stripe_promotion_code_id' => 'promo_once_10',
            'discount_type' => 'percent', 'percent_off' => 10, 'duration' => 'once',
        ]);
        $this->setEurAnnualPriceId();
        $intent = $this->intent([
            'promotion_id' => $promotion->id,
            'stripe_checkout_session_id' => 'cs_once_snapshot',
            'country' => 'DE', 'market' => 'INTERNATIONAL', 'currency' => 'EUR', 'amount' => 89000,
            'interval' => 'annual', 'stripe_price_id' => 'price_wordpress_business_annual_EUR',
        ]);
        $created = now()->timestamp;
        $invoice = [
            'id' => 'in_once_snapshot', 'object' => 'invoice', 'customer' => 'cus_once_snapshot', 'currency' => 'eur',
            'status' => 'paid', 'billing_reason' => 'subscription_create', 'created' => $created,
            'subtotal' => 89000, 'total_discount_amounts' => [['amount' => 8900, 'discount' => 'di_once_snapshot']],
            'total_taxes' => [['amount' => 0]], 'total' => 80100, 'amount_paid' => 80100, 'amount_due' => 0,
            'payment_intent' => 'pi_once_snapshot',
            'parent' => ['subscription_details' => ['subscription' => 'sub_once_snapshot', 'metadata' => ['checkout_intent' => $intent->uuid]]],
            'status_transitions' => ['finalized_at' => $created, 'paid_at' => $created],
        ];

        // Invoice arrives before checkout completion; it records actual money, while terms are not yet known.
        $this->stripeEvent('invoice.paid', $invoice, 'evt_once_invoice')->assertStatus(204);
        $this->assertSame(8900, BillingInvoice::sole()->discount_total);
        $this->assertNull(BillingInvoice::sole()->applied_discount_type);

        $session = [
            'id' => 'cs_once_snapshot', 'object' => 'checkout.session', 'status' => 'complete',
            'payment_status' => 'paid', 'livemode' => false, 'currency' => 'eur', 'client_reference_id' => $intent->uuid,
            'customer' => 'cus_once_snapshot', 'subscription' => 'sub_once_snapshot', 'invoice' => 'in_once_snapshot',
            'created' => $created, 'discounts' => [['coupon' => null, 'promotion_code' => 'promo_once_10']],
            'total_details' => ['amount_discount' => 8900],
        ];
        $this->stripeEvent('checkout.session.completed', $session, 'evt_once_session')->assertStatus(204);

        $this->stripeEvent('customer.subscription.created', [
            'id' => 'sub_once_snapshot', 'object' => 'subscription', 'customer' => 'cus_once_snapshot', 'status' => 'active',
            'metadata' => ['checkout_intent' => $intent->uuid], 'discounts' => [],
            'items' => ['data' => [['current_period_start' => $created, 'current_period_end' => $created + 31536000,
                'price' => ['id' => 'price_wordpress_business_annual_EUR', 'unit_amount' => 89000, 'currency' => 'eur']]]],
        ], 'evt_once_subscription')->assertStatus(204);

        $subscription = Subscription::sole();
        $this->assertSame(89000, $subscription->base_price_amount);
        $this->assertSame('EUR', $subscription->base_price_currency);
        $this->assertSame('percent', $subscription->initial_discount_type);
        $this->assertSame('10.00', $subscription->initial_discount_percent);
        $this->assertSame('once', $subscription->initial_discount_duration);
        $this->assertSame(8900, $subscription->initial_invoice_discount_total);
        $this->assertSame('coupon_once_10', $subscription->initial_stripe_coupon_id);
        $this->assertSame('promo_once_10', $subscription->initial_stripe_promotion_code_id);
        $this->assertSame(89000, $subscription->current_recurring_amount);
        $this->assertSame('EUR', $subscription->current_recurring_currency);
        $this->assertNull($subscription->discount_type, 'The once coupon is not an ongoing subscription discount.');

        $snapshot = BillingInvoice::sole();
        $this->assertSame(89000, $snapshot->subtotal);
        $this->assertSame(8900, $snapshot->discount_total);
        $this->assertSame(80100, $snapshot->total);
        $this->assertSame(80100, $snapshot->amount_paid);
        $this->assertSame('percent', $snapshot->applied_discount_type);
        $this->assertSame('10.00', $snapshot->applied_discount_percent);
        $this->assertSame('once', $snapshot->applied_discount_duration);

        // Later subscription state has no ongoing discount and must preserve the historical first-invoice terms.
        $later = $this->stripeSubscription('sub_once_snapshot', 'active', [
            'metadata' => ['checkout_intent' => $intent->uuid],
            'items' => ['data' => [['price' => ['id' => 'price_wordpress_business_annual_EUR', 'unit_amount' => 89000, 'currency' => 'eur']]]],
            'discounts' => [],
        ]);
        $this->stripeEvent('customer.subscription.updated', $later, 'evt_once_subscription_later')->assertStatus(204);
        $this->assertSame('once', $subscription->refresh()->initial_discount_duration);
        $this->assertSame(89000, $subscription->current_recurring_amount);

        $this->stripeEvent('invoice.paid', $invoice, 'evt_once_invoice')->assertStatus(204);
        $this->assertSame(1, BillingInvoice::count());
        $this->assertSame(8900, BillingInvoice::sole()->discount_total);
        $this->assertSame('once', BillingInvoice::sole()->applied_discount_duration);
    }

    public function test_forever_and_full_once_snapshots_keep_their_distinct_recurring_semantics(): void
    {
        $this->setEurAnnualPriceId();
        $forever = Promotion::create([
            'code' => 'FOREVER25', 'stripe_coupon_id' => 'coupon_forever_25', 'stripe_promotion_code_id' => 'promo_forever_25',
            'discount_type' => 'percent', 'percent_off' => 25, 'duration' => 'forever',
        ]);
        $onceFree = Promotion::create([
            'code' => 'ONCE100', 'stripe_coupon_id' => 'coupon_once_100', 'stripe_promotion_code_id' => 'promo_once_100',
            'discount_type' => 'percent', 'percent_off' => 100, 'duration' => 'once',
        ]);
        $service = app(CheckoutDiscountSnapshot::class);

        $foreverSnapshot = $service->fromSession((object) [
            'discounts' => [['promotion_code' => 'promo_forever_25']], 'total_details' => ['amount_discount' => 22250],
        ], $forever);
        $this->assertSame('forever', $foreverSnapshot['initial_discount_duration']);
        $ongoing = app(SubscriptionBillingSnapshot::class)->fromStripe(
            (object) ['discounts' => [['source' => ['coupon' => ['percent_off' => 25, 'duration' => 'forever']]]]],
            null,
            (object) ['unit_amount' => 89000, 'currency' => 'eur'],
        );
        $this->assertSame(66750, $ongoing['current_recurring_amount']);

        $freeSnapshot = $service->fromSession((object) [
            'discounts' => [['promotion_code' => 'promo_once_100']], 'total_details' => ['amount_discount' => 89000],
        ], $onceFree);
        $this->assertSame('100.00', $freeSnapshot['initial_discount_percent']);
        $this->assertSame('once', $freeSnapshot['initial_discount_duration']);
        $this->assertSame(89000, app(SubscriptionBillingSnapshot::class)->fromStripe(
            (object) ['discounts' => []],
            null,
            (object) ['unit_amount' => 89000, 'currency' => 'eur'],
        )['current_recurring_amount']);
    }

    public function test_session_first_subscription_then_invoice_order_converges_and_duplicate_subscription_keeps_once_history(): void
    {
        $promotion = Promotion::create([
            'code' => 'ORDERONCE10', 'stripe_coupon_id' => 'coupon_order_once', 'stripe_promotion_code_id' => 'promo_order_once',
            'discount_type' => 'percent', 'percent_off' => 10, 'duration' => 'once',
        ]);
        $this->setEurAnnualPriceId();
        $intent = $this->intent([
            'promotion_id' => $promotion->id, 'stripe_checkout_session_id' => 'cs_order_once',
            'country' => 'DE', 'market' => 'INTERNATIONAL', 'currency' => 'EUR', 'amount' => 89000,
            'interval' => 'annual', 'stripe_price_id' => 'price_wordpress_business_annual_EUR',
        ]);
        $created = now()->timestamp;

        $this->stripeEvent('checkout.session.completed', [
            'id' => 'cs_order_once', 'object' => 'checkout.session', 'status' => 'complete', 'payment_status' => 'paid',
            'currency' => 'eur', 'client_reference_id' => $intent->uuid, 'customer' => 'cus_order_once', 'subscription' => 'sub_order_once',
            'invoice' => 'in_order_once', 'created' => $created,
            'discounts' => [['coupon' => null, 'promotion_code' => 'promo_order_once']],
            'total_details' => ['amount_discount' => 8900],
        ], 'evt_order_session', $created)->assertStatus(204);

        $subscription = [
            'id' => 'sub_order_once', 'object' => 'subscription', 'customer' => 'cus_order_once', 'status' => 'active',
            'metadata' => ['checkout_intent' => $intent->uuid], 'discounts' => [],
            'items' => ['data' => [['price' => ['id' => 'price_wordpress_business_annual_EUR', 'unit_amount' => 89000, 'currency' => 'eur']]]],
        ];
        $this->stripeEvent('customer.subscription.created', $subscription, 'evt_order_created', $created + 1)->assertStatus(204);
        $invoice = [
            'id' => 'in_order_once', 'object' => 'invoice', 'customer' => 'cus_order_once', 'currency' => 'eur',
            'status' => 'paid', 'billing_reason' => 'subscription_create', 'created' => $created + 1,
            'subtotal' => 89000, 'total_discount_amounts' => [['amount' => 8900]], 'total_taxes' => [['amount' => 0]],
            'total' => 80100, 'amount_paid' => 80100, 'amount_due' => 0,
            'parent' => ['subscription_details' => ['subscription' => 'sub_order_once', 'metadata' => ['checkout_intent' => $intent->uuid]]],
            'status_transitions' => ['paid_at' => $created + 1],
        ];
        $this->stripeEvent('invoice.paid', $invoice, 'evt_order_paid', $created + 1)->assertStatus(204);
        $this->stripeEvent('customer.subscription.updated', $subscription, 'evt_order_updated', $created + 2)->assertStatus(204);
        $this->stripeEvent('customer.subscription.updated', $subscription, 'evt_order_updated', $created + 2)->assertStatus(204);

        $subscriptionRow = Subscription::sole();
        $invoiceRow = BillingInvoice::sole();
        $this->assertSame('once', $subscriptionRow->initial_discount_duration);
        $this->assertSame(8900, $subscriptionRow->initial_invoice_discount_total);
        $this->assertSame(89000, $subscriptionRow->current_recurring_amount);
        $this->assertSame('percent', $invoiceRow->applied_discount_type);
        $this->assertSame('once', $invoiceRow->applied_discount_duration);
        $this->assertSame(8900, $invoiceRow->discount_total);
        $this->assertSame(80100, $invoiceRow->amount_paid);
        $this->assertSame(1, BillingInvoice::count());
    }

    private function setEurAnnualPriceId(): void
    {
        foreach (config('pricing.prices') as $index => $price) {
            if ($price['product_type'] === 'wordpress' && $price['plan'] === 'business' && $price['interval'] === 'annual' && $price['currency'] === 'EUR') {
                config(["pricing.prices.{$index}.stripe_price_id" => 'price_wordpress_business_annual_EUR']);
            }
        }
    }

    public function test_stale_invoice_failure_does_not_overwrite_a_paid_snapshot(): void
    {
        $intent = $this->intent();
        $this->subscription($intent)->assertStatus(204);
        $invoice = [
            'id' => 'in_stale', 'object' => 'invoice', 'customer' => 'cus_snapshot', 'currency' => 'huf',
            'status' => 'paid', 'created' => now()->timestamp, 'subtotal' => 1899000, 'total' => 1899000,
            'amount_paid' => 1899000, 'amount_due' => 0,
            'parent' => ['subscription_details' => ['subscription' => 'sub_snapshot']],
            'status_transitions' => ['paid_at' => now()->timestamp],
        ];
        $this->stripeEvent('invoice.paid', $invoice, 'evt_invoice_paid', now()->timestamp)->assertStatus(204);
        $this->stripeEvent('invoice.payment_failed', array_replace($invoice, ['status' => 'open', 'amount_paid' => 0, 'amount_due' => 1899000]), 'evt_invoice_old_failure', now()->subMinute()->timestamp)->assertStatus(204);

        $this->assertSame('paid', BillingInvoice::sole()->invoice_status);
        $this->assertSame(1899000, BillingInvoice::sole()->amount_paid);
    }
}
