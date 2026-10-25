<?php

namespace Tests\Feature;

use App\Billing\QueueBillingEmail;
use App\Billing\ProvisionCustomer;
use App\Checkout\CheckoutGateway;
use App\Checkout\CheckoutStatus;
use App\Checkout\CustomerDetails;
use App\Checkout\StartCheckout;
use App\Jobs\ProcessBillingEmailOutboxJob;
use App\Mail\AccountActivation;
use App\Mail\BillingLifecycleNotice;
use App\Mail\PaymentConfirmation;
use App\Mail\PaymentFailed;
use App\Mail\SubscriptionActivated;
use App\Mail\SubscriptionCanceled;
use App\Mail\WelcomeEmail;
use App\Models\CheckoutIntent;
use App\Models\Customer;
use App\Models\Subscription;
use App\Pricing\BillingInterval;
use App\Pricing\Currency;
use App\Pricing\Money;
use App\Pricing\PlanTier;
use App\Pricing\PriceSelection;
use App\Pricing\ProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use ReflectionProperty;
use Tests\Fakes\FakeCheckoutGateway;
use Tests\TestCase;

final class CustomerTransactionEmailLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
    }

    public function test_account_activation_and_welcome_use_the_customers_saved_language(): void
    {
        foreach ([
            [
                'locale' => 'hu', 'other' => 'en', 'greeting' => 'Kedves Anna!',
                'activation' => 'Köszönjük a megrendelését', 'welcome' => 'A fiókja aktív.',
                'activation_subject' => 'Aktiválja WP CareGrid-fiókját', 'welcome_subject' => 'Üdvözöljük a WP CareGridben',
            ],
            [
                'locale' => 'en', 'other' => 'hu', 'greeting' => 'Hello Anna,',
                'activation' => 'Thank you for your order.', 'welcome' => 'Your account is active.',
                'activation_subject' => 'Activate your WP CareGrid account', 'welcome_subject' => 'Welcome to WP CareGrid',
            ],
        ] as $case) {
            $customer = Customer::factory()->withUser()->create(['first_name' => 'Anna']);
            $customer->user->forceFill(['locale' => $case['locale']])->save();
            $this->app->setLocale($case['other']);

            $activationMail = new AccountActivation($customer, 'synthetic-token');
            $welcomeMail = new WelcomeEmail($customer);
            $activation = $activationMail->render();
            $welcome = $welcomeMail->render();

            $this->assertStringContainsString($case['greeting'], $activation);
            $this->assertStringContainsString($case['activation'], $activation);
            $this->assertStringContainsString($case['greeting'], $welcome);
            $this->assertStringContainsString($case['welcome'], $welcome);
            $this->assertSame($case['locale'], $this->mailableLocale($activationMail));
            $this->assertSame($case['locale'], $this->mailableLocale($welcomeMail));

            $this->app->setLocale($case['locale']);
            $this->assertSame($case['activation_subject'], $activationMail->envelope()->subject);
            $this->assertSame($case['welcome_subject'], $welcomeMail->envelope()->subject);
        }
    }

    public function test_billing_mailables_render_localized_subjects_and_bodies_without_changing_currency(): void
    {
        foreach ([
            [
                'locale' => 'hu', 'other' => 'en',
                'subjects' => [
                    'payment_confirmation' => 'A fizetést megkaptuk',
                    'payment_failed' => 'A fizetés nem sikerült',
                    'subscription_activated' => 'Az előfizetése aktív',
                    'subscription_canceled' => 'Az előfizetését lemondtuk',
                ],
                'payment_copy' => 'Megkaptuk a(z)',
                'failed_copy' => 'nem tudtuk levonni',
                'activated_copy' => 'Kezdje meg a beállítást',
                'canceled_copy' => 'karbantartása leállt',
            ],
            [
                'locale' => 'en', 'other' => 'hu',
                'subjects' => [
                    'payment_confirmation' => 'Payment received',
                    'payment_failed' => 'Your payment did not go through',
                    'subscription_activated' => 'Your subscription is active',
                    'subscription_canceled' => 'Your subscription has been canceled',
                ],
                'payment_copy' => 'We have received your payment',
                'failed_copy' => 'could not be collected',
                'activated_copy' => 'Add your website in your account',
                'canceled_copy' => 'Maintenance for the website on this plan has stopped',
            ],
        ] as $case) {
            $customer = Customer::factory()->withUser()->create(['first_name' => 'Anna']);
            $customer->user->forceFill(['locale' => $case['locale']])->save();
            $subscription = Subscription::factory()->for($customer)->create(['currency' => 'HUF']);
            $money = new Money(1899000, Currency::fromConfig('HUF', config('pricing.currencies.HUF'), 'en'));
            $this->app->setLocale($case['locale']);

            $mailables = [
                'payment_confirmation' => new PaymentConfirmation($subscription, $money),
                'payment_failed' => new PaymentFailed($subscription, $money),
                'subscription_activated' => new SubscriptionActivated($subscription),
                'subscription_canceled' => new SubscriptionCanceled($subscription),
            ];
            $this->app->setLocale($case['other']);

            foreach ($mailables as $key => $mail) {
                $body = $mail->render();

                $this->assertSame($case['locale'], $this->mailableLocale($mail));
                $this->app->setLocale($case['locale']);
                $this->assertSame($case['subjects'][$key], $mail->envelope()->subject);
                $this->app->setLocale($case['other']);
                $this->assertStringContainsString($case['locale'] === 'hu' ? 'Kedves Anna!' : 'Hello Anna,', $body);
                $this->assertStringContainsString(
                    match ($key) {
                        'payment_confirmation' => $case['payment_copy'],
                        'payment_failed' => $case['failed_copy'],
                        'subscription_activated' => $case['activated_copy'],
                        'subscription_canceled' => $case['canceled_copy'],
                    },
                    $body,
                );
                if (str_starts_with($key, 'payment_')) {
                    $this->assertStringContainsString('Ft', $body);
                    $this->assertStringNotContainsString('€', $body);
                    $this->assertStringNotContainsString('£', $body);
                }
            }
        }
    }

    public function test_billing_outbox_locale_snapshot_is_preserved_and_worker_locale_is_restored(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $customer->user->forceFill(['locale' => 'hu'])->save();
        $subscription = Subscription::factory()->for($customer)->create();

        $row = app(QueueBillingEmail::class)->enqueue(
            'subscription:locale-snapshot',
            'subscription_reactivated',
            $customer,
            $subscription,
        );
        $this->assertSame('hu', $row->locale);

        // A later profile change must not rewrite the already-recorded outbox language.
        $customer->user->forceFill(['locale' => 'en'])->save();
        $this->app->setLocale('en');

        (new ProcessBillingEmailOutboxJob($row->id))->handle();
        $this->assertSame('en', $this->app->getLocale());

        $englishRow = app(QueueBillingEmail::class)->enqueue(
            'subscription:locale-snapshot-en',
            'subscription_reactivated',
            $customer,
            $subscription,
        );
        $this->assertSame('en', $englishRow->locale);

        // Reuse the same worker process after changing its ambient locale.
        $this->app->setLocale('hu');
        (new ProcessBillingEmailOutboxJob($englishRow->id))->handle();

        Mail::assertSent(BillingLifecycleNotice::class, 2);
        Mail::assertSent(BillingLifecycleNotice::class, function (BillingLifecycleNotice $mail): bool {
            return $this->mailableLocale($mail) === 'hu'
                && $this->mailableSubject($mail, 'hu') === 'Az előfizetés újra aktiválva'
                && str_contains($mail->render(), 'előfizetés aktívan tartására vonatkozó kérését megerősítettük');
        });
        Mail::assertSent(BillingLifecycleNotice::class, function (BillingLifecycleNotice $mail): bool {
            return $this->mailableLocale($mail) === 'en'
                && $this->mailableSubject($mail, 'en') === 'Subscription reactivated'
                && str_contains($mail->render(), 'Your request to keep the Business plan active was confirmed.');
        });
        $this->assertSame('hu', $this->app->getLocale());
        $this->assertSame('sent', $row->fresh()->status);
        $this->assertSame('sent', $englishRow->fresh()->status);
    }

    public function test_every_billing_lifecycle_notice_renders_its_hungarian_and_english_subject_and_body(): void
    {
        $customer = Customer::factory()->create(['first_name' => 'Anna']);
        $notices = [
            'cancellation_scheduled', 'subscription_reactivated', 'upgrade_completed', 'downgrade_scheduled',
            'payment_recovered', 'grace_started', 'grace_reminder', 'invoice_available', 'billing_action_required',
        ];

        foreach (['hu', 'en'] as $locale) {
            foreach ($notices as $notification) {
                $this->app->setLocale($locale);
                $mail = new BillingLifecycleNotice($customer, $notification, 'Business', 'annual');
                $this->app->setLocale($locale === 'hu' ? 'en' : 'hu');

                $this->assertSame($locale, $this->mailableLocale($mail));
                $this->assertSame(trans('emails.lifecycle.'.$notification.'.subject', [], $locale), $this->mailableSubject($mail, $locale));
                $this->assertStringContainsString(
                    trans('emails.lifecycle.'.$notification.'.body', [
                        'plan' => 'Business',
                        'interval' => trans('emails.billing_intervals.annual', [], $locale),
                    ], $locale),
                    $mail->render(),
                );
            }
        }
    }

    public function test_localized_payment_copy_keeps_the_selected_billing_currency(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $subscription = Subscription::factory()->for($customer)->create(['currency' => 'HUF']);
        $money = new Money(1899000, Currency::fromConfig('HUF', config('pricing.currencies.HUF'), 'en'));
        $this->app->setLocale('hu');

        $body = (new PaymentConfirmation($subscription, $money))->render();

        $this->assertStringContainsString('Ft', $body);
        $this->assertStringNotContainsString('€', $body);
        $this->assertStringNotContainsString('£', $body);
        $this->assertStringContainsString('A számlát külön e-mailben küldjük.', $body);
    }

    public function test_customer_email_translation_keys_and_placeholders_match_in_hungarian_and_english(): void
    {
        $english = Arr::only(require lang_path('en/emails.php'), [
            'greeting', 'signature', 'support', 'activation', 'welcome', 'payment_confirmation',
            'payment_failed', 'subscription_activated', 'subscription_canceled', 'lifecycle', 'billing_intervals',
        ]);
        $hungarian = Arr::only(require lang_path('hu/emails.php'), array_keys($english));

        $this->assertSame($this->leafPaths($english), $this->leafPaths($hungarian));

        foreach ($this->leafPaths($english) as $path) {
            $this->assertSame(
                $this->placeholders(data_get($english, $path)),
                $this->placeholders(data_get($hungarian, $path)),
                'Translation placeholders differ at emails.'.$path,
            );
        }
    }

    public function test_checkout_records_only_a_supported_customer_language_and_keeps_billing_currency_independent(): void
    {
        foreach (config('pricing.prices') as $index => $price) {
            config(["pricing.prices.{$index}.stripe_price_id" => "price_{$price['product_type']}_{$price['plan']}_{$price['interval']}_{$price['currency']}"]);
        }
        $gateway = new FakeCheckoutGateway;
        $this->app->instance(CheckoutGateway::class, $gateway);

        foreach (['hu', 'en'] as $locale) {
            $this->app->setLocale($locale);
            $intent = app(StartCheckout::class)->handle(
                new PriceSelection(ProductType::WordPress, PlanTier::Business, BillingInterval::Monthly),
                new CustomerDetails(
                    email: "{$locale}@company.example",
                    firstName: 'Anna',
                    lastName: 'Kovács',
                    country: 'HU',
                    billingAddress: ['line1' => 'Teszt utca 1.', 'postal_code' => '1011', 'city' => 'Budapest'],
                ),
                promotionCode: null,
                browserSessionId: "synthetic-browser-{$locale}",
                currency: 'HUF',
            );

            $this->assertSame($locale, $intent->locale);
            $this->assertSame('HUF', $intent->currency);
        }

        $this->assertCount(2, $gateway->created);
    }

    public function test_provisioning_saves_new_customers_checkout_language_before_queueing_activation_email(): void
    {
        $intent = $this->checkoutIntent(['locale' => 'hu']);

        $customer = app(ProvisionCustomer::class)->forIntent($intent, null);

        $this->assertSame('hu', $customer->user->locale);
        Mail::assertQueued(AccountActivation::class, fn (AccountActivation $mail): bool => str_contains($mail->render(), 'Kedves Anna!'));
    }

    public function test_provisioning_never_overwrites_an_existing_customers_saved_language(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $customer->user->forceFill(['locale' => 'en'])->save();
        $intent = $this->checkoutIntent(['customer_id' => $customer->id, 'locale' => 'hu']);

        $resolved = app(ProvisionCustomer::class)->forIntent($intent, null);

        $this->assertSame($customer->id, $resolved->id);
        $this->assertSame('en', $resolved->user->locale);
        Mail::assertNothingQueued();
    }

    /** @param array<string, mixed> $values
     *  @return list<string>
     */
    private function leafPaths(array $values, string $prefix = ''): array
    {
        $paths = [];
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                array_push($paths, ...$this->leafPaths($value, $path));
            } else {
                $paths[] = $path;
            }
        }

        sort($paths);

        return $paths;
    }

    private function placeholders(mixed $value): array
    {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', (string) $value, $matches);
        $placeholders = $matches[0];
        sort($placeholders);

        return $placeholders;
    }

    private function mailableLocale(object $mail): ?string
    {
        return (new ReflectionProperty(Mailable::class, 'locale'))->getValue($mail);
    }

    private function mailableSubject(object $mail, string $locale): string
    {
        $originalLocale = $this->app->getLocale();
        $this->app->setLocale($locale);

        try {
            return $mail->envelope()->subject;
        } finally {
            $this->app->setLocale($originalLocale);
        }
    }

    /** @param array<string, mixed> $overrides */
    private function checkoutIntent(array $overrides = []): CheckoutIntent
    {
        return CheckoutIntent::create($overrides + [
            'status' => CheckoutStatus::CheckoutCreated,
            'email' => 'Anna.Kovacs@Company.example',
            'first_name' => 'Anna',
            'last_name' => 'Kovács',
            'customer_type' => 'individual',
            'country' => 'HU',
            'billing_address' => ['line1' => 'Teszt utca 1.', 'postal_code' => '1011', 'city' => 'Budapest'],
            'product_type' => 'wordpress',
            'plan' => 'business',
            'interval' => 'monthly',
            'market' => 'hu',
            'currency' => 'HUF',
            'amount' => 1899000,
            'tax_treatment' => 'final_price_no_vat_added',
            'stripe_price_id' => 'price_wordpress_business_monthly_HUF',
            'stripe_checkout_session_id' => 'cs_test_locale_snapshot',
        ]);
    }
}
