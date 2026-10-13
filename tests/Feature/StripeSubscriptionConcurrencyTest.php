<?php

namespace Tests\Feature;

use App\Checkout\CheckoutStatus;
use App\Checkout\StripeWebhookHandler;
use App\Jobs\ProcessBillingEmailOutboxJob;
use App\Mail\AccountActivation;
use App\Models\BillingEmailOutbox;
use App\Models\BillingInvoice;
use App\Models\CheckoutIntent;
use App\Models\Customer;
use App\Models\StripeEvent;
use App\Models\Subscription;
use App\Models\User;
use App\Pricing\PricingCatalog;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Stripe\Event;
use Tests\TestCase;

#[Group('billing-concurrency')]
class StripeSubscriptionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private string $stripeCustomerId;

    private string $stripeSubscriptionId;

    public function test_checkout_and_subscription_webhooks_racing_on_independent_mysql_processes_converge(): void
    {
        $this->requireIndependentMysqlProcesses();
        $this->configureTestPriceIds();
        config(['queue.default' => 'database', 'mail.default' => 'array']);
        app('queue')->setDefaultDriver('database');

        $intent = $this->intent();
        $this->raceEvents(
            $intent,
            $this->checkoutCompletedEvent($intent),
            $this->subscriptionEvent($intent),
        );

        $this->assertConvergedSubscription($intent);
        $this->assertQueuedMailableCount(AccountActivation::class, 1);
        $this->assertBillingEmailCount('subscription_activated', 1);
        $this->assertQueuedBillingOutboxJobs(1);
    }

    public function test_subscription_and_paid_invoice_webhooks_racing_converge_with_one_invoice(): void
    {
        $this->requireIndependentMysqlProcesses();
        $this->configureTestPriceIds();
        config(['queue.default' => 'database', 'mail.default' => 'array']);
        app('queue')->setDefaultDriver('database');

        $intent = $this->intent();
        $this->raceEvents(
            $intent,
            $this->subscriptionEvent($intent),
            $this->invoicePaidEvent($intent),
        );

        $this->assertConvergedSubscription($intent);
        $invoice = BillingInvoice::query()->sole();
        $this->assertSame($invoice->subscription_id, Subscription::sole()->id);
        $this->assertSame('USD', $invoice->currency);
        $this->assertSame(9900, $invoice->total);
        $this->assertSame(9900, $invoice->amount_paid);
        $this->assertSame('paid', $invoice->invoice_status);
        $this->assertNotNull($invoice->payment_confirmation_notified_at);
        $this->assertQueuedMailableCount(AccountActivation::class, 1);
        $this->assertBillingEmailCount('subscription_activated', 1);
        $this->assertBillingEmailCount('payment_received', 1);
        $this->assertQueuedBillingOutboxJobs(2);
    }

    private function requireIndependentMysqlProcesses(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl for independent-process contention.');
        }
    }

    private function configureTestPriceIds(): void
    {
        foreach (config('pricing.prices') as $index => $price) {
            $id = 'price_'.$price['product_type'].'_'.$price['plan'].'_'.$price['interval'].'_'.$price['currency'];
            config(["pricing.prices.{$index}.stripe_price_id" => $id]);
        }

        app()->forgetInstance(PricingCatalog::class);
    }

    private function intent(): CheckoutIntent
    {
        $this->stripeCustomerId = 'cus_'.Str::random(18);
        $this->stripeSubscriptionId = 'sub_'.Str::random(18);
        $price = collect(config('pricing.prices'))->first(fn (array $row) => $row['product_type'] === 'woocommerce'
            && $row['plan'] === 'business'
            && $row['interval'] === 'monthly'
            && $row['currency'] === 'USD');

        return CheckoutIntent::create([
            'status' => CheckoutStatus::CheckoutCreated,
            'email' => 'race-'.Str::uuid().'@example.test',
            'first_name' => 'Stripe',
            'last_name' => 'Race',
            'country' => 'US',
            'customer_type' => 'individual',
            'product_type' => 'woocommerce',
            'plan' => 'business',
            'interval' => 'monthly',
            'market' => 'INTERNATIONAL',
            'currency' => 'USD',
            'amount' => 9900,
            'tax_treatment' => 'undetermined',
            'stripe_price_id' => $price['stripe_price_id'],
            'stripe_checkout_session_id' => 'cs_test_'.Str::random(20),
        ]);
    }

    /**
     * Hold the intent lock on a third connection while both webhook processes
     * reach the barrier, then release it so they contend for the same parent.
     */
    private function raceEvents(CheckoutIntent $intent, array $firstEvent, array $secondEvent): void
    {
        $connectionName = DB::getDefaultConnection();
        config(['database.connections.race_barrier' => DB::connection()->getConfig()]);
        DB::purge('race_barrier');

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcg-subscription-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $children = [];
        $barrierPid = pcntl_fork();

        if ($barrierPid === -1) {
            $this->fail('Unable to fork a Stripe webhook lock holder.');
        }

        if ($barrierPid === 0) {
            DB::purge($connectionName);
            DB::purge('race_barrier');
            $barrier = DB::connection('race_barrier');
            $barrier->beginTransaction();
            $barrier->table('checkout_intents')->where('id', $intent->id)->lockForUpdate()->first();
            file_put_contents($directory.DIRECTORY_SEPARATOR.'barrier-ready', 'ready', LOCK_EX);

            $deadline = microtime(true) + 30;
            while (! is_file($directory.DIRECTORY_SEPARATOR.'release-barrier')) {
                if (microtime(true) >= $deadline) {
                    $barrier->rollBack();
                    exit(1);
                }
                usleep(10000);
            }

            $barrier->commit();
            exit(0);
        }

        try {
            $deadline = microtime(true) + 20;
            while (! is_file($directory.DIRECTORY_SEPARATOR.'barrier-ready')) {
                if (microtime(true) >= $deadline) {
                    $this->fail('The webhook lock holder did not acquire the CheckoutIntent lock.');
                }
                usleep(10000);
            }

            foreach ([$firstEvent, $secondEvent] as $index => $payload) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->fail('Unable to fork a Stripe webhook contender.');
                }
                if ($pid === 0) {
                    DB::purge($connectionName);
                    DB::connection()->getPdo();
                    file_put_contents($directory.DIRECTORY_SEPARATOR."ready-{$index}", 'ready', LOCK_EX);

                    try {
                        $handled = app(StripeWebhookHandler::class)->handle(Event::constructFrom($payload));
                        file_put_contents($directory.DIRECTORY_SEPARATOR."result-{$index}", $handled ? 'handled' : 'duplicate', LOCK_EX);
                        exit(0);
                    } catch (\Throwable $exception) {
                        file_put_contents($directory.DIRECTORY_SEPARATOR."result-{$index}", 'error:'.get_class($exception), LOCK_EX);
                        exit(1);
                    }
                }
                $children[] = $pid;
            }

            $deadline = microtime(true) + 20;
            while (! is_file($directory.DIRECTORY_SEPARATOR.'ready-0') || ! is_file($directory.DIRECTORY_SEPARATOR.'ready-1')) {
                if (microtime(true) >= $deadline) {
                    $this->fail('Stripe webhook contenders did not reach the start barrier.');
                }
                usleep(10000);
            }

            // Let each process open its own database connection and issue the
            // webhook before releasing the common CheckoutIntent row lock.
            usleep(300000);
            file_put_contents($directory.DIRECTORY_SEPARATOR.'release-barrier', 'release', LOCK_EX);

            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $index = array_search($pid, $children, true);
                $this->assertTrue(pcntl_wifexited($status), 'A webhook contender did not exit normally.');
                $this->assertSame(0, pcntl_wexitstatus($status), (string) @file_get_contents($directory.DIRECTORY_SEPARATOR.'result-'.$index));
            }

            pcntl_waitpid($barrierPid, $barrierStatus);
            $this->assertTrue(pcntl_wifexited($barrierStatus), 'The webhook lock holder did not exit normally.');
            $this->assertSame(0, pcntl_wexitstatus($barrierStatus), 'The webhook lock holder failed.');

            $results = [
                file_get_contents($directory.DIRECTORY_SEPARATOR.'result-0'),
                file_get_contents($directory.DIRECTORY_SEPARATOR.'result-1'),
            ];
            $this->assertSame('handled', $results[0]);
            $this->assertSame('handled', $results[1]);
        } finally {
            foreach ($children as $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                    posix_kill($pid, SIGTERM);
                    pcntl_waitpid($pid, $status);
                }
            }
            if (pcntl_waitpid($barrierPid, $barrierStatus, WNOHANG) === 0) {
                posix_kill($barrierPid, SIGTERM);
                pcntl_waitpid($barrierPid, $barrierStatus);
            }
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($directory);
            DB::purge('race_barrier');
        }
    }

    private function assertConvergedSubscription(CheckoutIntent $intent): void
    {
        DB::purge(DB::getDefaultConnection());
        $intent->refresh();
        $customer = Customer::query()->where('email', $intent->email)->sole();
        $user = User::query()->where('email', $intent->email)->sole();
        $subscription = Subscription::query()->sole();
        $this->assertSame(CheckoutStatus::Completed, $intent->status);
        $this->assertSame($customer->id, $intent->customer_id);
        $this->assertSame($customer->id, $user->customer?->id);
        $this->assertSame($customer->id, $subscription->customer_id);
        $this->assertSame($customer->id, $subscription->customer?->id);
        $this->assertSame($intent->id, $subscription->checkout_intent_id);
        $this->assertSame('active', $subscription->status->value);
        $this->assertSame('USD', $subscription->currency);
        $this->assertSame(9900, $subscription->current_recurring_amount);
        $this->assertSame('USD', $subscription->current_recurring_currency);
        $this->assertNotNull($subscription->billing_snapshot_confirmed_at);
        $this->assertNotNull($subscription->activated_at);
        $this->assertNotNull($subscription->activation_notified_at);
        $this->assertSame(2, StripeEvent::query()->count());
        $this->assertSame(1, Customer::query()->where('email', $intent->email)->count());
        $this->assertSame(1, User::query()->where('email', $intent->email)->count());
        $this->assertSame(1, Subscription::query()->count());
    }

    private function assertQueuedMailableCount(string $mailable, int $expected): void
    {
        $matches = DB::table('jobs')->get(['payload'])->filter(function (object $job) use ($mailable): bool {
            $payload = json_decode($job->payload, true);

            return ($payload['displayName'] ?? null) === $mailable;
        });

        $this->assertCount($expected, $matches, "Expected {$expected} queued {$mailable} message(s).");
    }

    private function assertBillingEmailCount(string $notification, int $expected): void
    {
        $rows = BillingEmailOutbox::query()->where('notification', $notification)->get();
        $this->assertCount($expected, $rows, "Expected {$expected} {$notification} outbox record(s).");
        $this->assertSame(
            $expected,
            $rows->where('status', 'pending')->count(),
            "Expected {$expected} pending {$notification} outbox record(s).",
        );
    }

    private function assertQueuedBillingOutboxJobs(int $expected): void
    {
        $queuedJobs = DB::table('jobs')->get(['payload'])->filter(function (object $job): bool {
            $payload = json_decode($job->payload, true);

            return ($payload['displayName'] ?? null) === ProcessBillingEmailOutboxJob::class;
        });

        $this->assertCount($expected, $queuedJobs, 'Expected one queued delivery job per pending billing email outbox row.');
    }

    private function checkoutCompletedEvent(CheckoutIntent $intent): array
    {
        return $this->event('checkout.session.completed', [
            'id' => $intent->stripe_checkout_session_id,
            'object' => 'checkout.session',
            'client_reference_id' => $intent->uuid,
            'payment_status' => 'paid',
            'customer' => $this->stripeCustomerId,
            'subscription' => $this->stripeSubscriptionId,
        ]);
    }

    private function subscriptionEvent(CheckoutIntent $intent): array
    {
        return $this->event('customer.subscription.created', [
            'id' => $this->stripeSubscriptionId,
            'object' => 'subscription',
            'customer' => $this->stripeCustomerId,
            'status' => 'active',
            'cancel_at_period_end' => false,
            'metadata' => ['checkout_intent' => $intent->uuid],
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_'.Str::random(18),
                'object' => 'subscription_item',
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'price' => ['id' => 'price_woocommerce_business_monthly_USD', 'object' => 'price', 'unit_amount' => 9900, 'currency' => 'usd'],
            ]]],
        ]);
    }

    private function invoicePaidEvent(CheckoutIntent $intent): array
    {
        return $this->event('invoice.paid', [
            'id' => 'in_'.Str::random(18),
            'object' => 'invoice',
            'customer' => $this->stripeCustomerId,
            'payment_intent' => 'pi_'.Str::random(18),
            'status' => 'paid',
            'currency' => 'usd',
            'subtotal' => 9900,
            'total' => 9900,
            'amount_paid' => 9900,
            'amount_due' => 0,
            'billing_reason' => 'subscription_create',
            'created' => now()->timestamp,
            'total_discount_amounts' => [],
            'total_taxes' => [],
            'status_transitions' => ['paid_at' => now()->timestamp, 'finalized_at' => now()->timestamp],
            'parent' => [
                'type' => 'subscription_details',
                'subscription_details' => [
                    'subscription' => $this->stripeSubscriptionId,
                    'metadata' => ['checkout_intent' => $intent->uuid],
                ],
            ],
        ]);
    }

    private function event(string $type, array $object, array $shared = []): array
    {
        return [
            'id' => 'evt_'.Str::random(20),
            'object' => 'event',
            'type' => $type,
            'created' => now()->timestamp,
            'data' => ['object' => array_replace($object, $shared)],
        ];
    }
}
