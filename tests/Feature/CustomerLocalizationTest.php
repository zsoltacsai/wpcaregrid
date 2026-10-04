<?php

namespace Tests\Feature;

use App\Backups\BackupStatus;
use App\Billing\SubscriptionStatus;
use App\Checkout\CheckoutGateway;
use App\Checkout\StripeCheckoutGateway;
use App\Localization\Locales;
use App\Models\CheckoutIntent;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Pricing\DisplayCurrency;
use App\Pricing\PricingCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\ActsAsOwner;
use Tests\Concerns\CreatesBackups;
use Tests\Fakes\FakeCheckoutGateway;
use Tests\TestCase;

/**
 * Display language and currency of the public site, the customer login and
 * the customer portal (config/localization.php). Everything here is
 * presentation: prices are resolved on the server and never from the language
 * or the displayed currency.
 */
class CustomerLocalizationTest extends TestCase
{
    use ActsAsOwner, CreatesBackups, RefreshDatabase;

    private const COOKIE = 'caregrid_locale';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups();

        // One browser session across requests, as a real browser has (the display currency lives in it).
        $this->withCookie(config('session.cookie'), str_repeat('s', 40));

        // As in a configured environment: every HU price has a Stripe Price ID.
        foreach (config('pricing.prices') as $i => $price) {
            config(["pricing.prices.{$i}.stripe_price_id" => "price_{$price['product_type']}_{$price['plan']}_{$price['interval']}"]);
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownBackups();
        parent::tearDown();
    }

    /** The visitor's remembered language, as the browser would send it. */
    private function in(string $locale): static
    {
        return $this->withCookie(self::COOKIE, $locale);
    }

    /** Fixture, not shipped: an extra market with prices, to prove the selector appears only when prices exist. */
    private function withEuroPrices(): void
    {
        $euro = collect(config('pricing.prices'))->map(fn (array $price) => [
            'market' => 'eu', 'currency' => 'EUR', 'amount' => intdiv($price['amount'], 400), 'stripe_price_id' => 'price_eur_'.$price['plan'].'_'.$price['interval'],
        ] + $price)->all();

        config(['pricing.prices' => [...config('pricing.prices'), ...$euro]]);
        app()->forgetInstance(PricingCatalog::class);
    }

    /** Visible text of a response: tags and entities removed. */
    private function text($response): string
    {
        $html = preg_replace('#<(script|style|svg)\b.*?</\1>#s', ' ', $response->getContent());

        return preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    // --- Public site ---------------------------------------------------------------------------------------------

    public function test_the_default_language_is_the_application_default_and_the_page_declares_it(): void
    {
        $en = $this->get('/')->assertOk();
        $this->assertStringContainsString('<html lang="en">', $en->getContent());
        $en->assertHeader('Content-Language', 'en');

        $hu = $this->in('hu')->get('/')->assertOk();
        $this->assertStringContainsString('<html lang="hu">', $hu->getContent());
        $hu->assertHeader('Content-Language', 'hu');
        $this->assertStringContainsString('WordPress-karbantartás, gond nélkül.', $hu->getContent());
        $this->assertStringContainsString('<title>WP CareGrid – WordPress-karbantartás, mentések és biztonság</title>', $hu->getContent());
    }

    public function test_the_language_selector_is_on_the_page_and_marks_the_active_language(): void
    {
        foreach (['en' => ['Language', 'English'], 'hu' => ['Nyelv', 'Magyar']] as $locale => [$label, $active]) {
            $html = $this->in($locale)->get('/')->getContent();

            $this->assertStringContainsString('role="group"', $html);
            $this->assertStringContainsString('aria-label="'.$label.'"', $html);
            $this->assertMatchesRegularExpression('/<button[^>]*lang="en"[^>]*>\s*English\s*<\/button>/', $html);
            $this->assertMatchesRegularExpression('/<button[^>]*lang="hu"[^>]*>\s*Magyar\s*<\/button>/', $html);
            $this->assertMatchesRegularExpression('/<button[^>]*aria-current="true"[^>]*>\s*'.$active.'\s*<\/button>/', $html);
        }
    }

    public function test_switching_the_language_remembers_it_and_returns_to_the_same_page(): void
    {
        $this->from(route('pricing'))->post(route('locale.update'), ['locale' => 'hu'])
            ->assertRedirect(route('pricing'))
            ->assertCookie(self::COOKIE, 'hu');

        $this->in('hu')->get(route('pricing'))->assertSee('Csomagok és árak');
        $this->in('en')->get(route('pricing'))->assertSee('Plans and pricing');
    }

    public function test_unsupported_languages_are_refused_and_change_nothing(): void
    {
        foreach (['de', 'HU', '', '../../etc/passwd', '<script>alert(1)</script>', ['hu']] as $bad) {
            $response = $this->from(route('home'))->post(route('locale.update'), ['locale' => $bad]);

            $response->assertSessionHasErrors('locale');
            $response->assertCookieMissing(self::COOKIE);
            // The refusal never echoes the submitted value or any path.
            $this->assertStringNotContainsString('passwd', json_encode(session('errors')->getBag('default')->all()));
            $this->assertStringNotContainsString('script', json_encode(session('errors')->getBag('default')->all()));
        }

        $this->assertSame(0, User::whereNotNull('locale')->count());
    }

    public function test_a_tampered_cookie_and_a_foreign_return_address_are_harmless(): void
    {
        $this->in('xx')->get('/')->assertOk()->assertHeader('Content-Language', 'en');
        $this->in('../../')->get('/')->assertOk()->assertHeader('Content-Language', 'en');

        $this->withHeader('Referer', 'https://evil.example/phish')->post(route('locale.update'), ['locale' => 'hu'])
            ->assertRedirect(route('home'));
    }

    public function test_a_language_query_parameter_is_not_a_way_to_switch(): void
    {
        $this->get('/?locale=hu&lang=hu')->assertOk()->assertHeader('Content-Language', 'en');
    }

    public function test_what_was_typed_comes_back_after_a_switch_but_secrets_do_not(): void
    {
        $this->from(route('checkout.show', ['product' => 'wordpress', 'plan' => 'business', 'interval' => 'monthly']))
            ->post(route('locale.update'), [
                'locale' => 'hu', 'email' => 'anna@company.example', 'first_name' => 'Anna',
                'password' => 'a-secret-password', 'password_confirmation' => 'a-secret-password', 'current_password' => 'old-secret',
            ])
            ->assertRedirect()
            ->assertSessionHas('_old_input.email', 'anna@company.example')
            ->assertSessionHas('_old_input.first_name', 'Anna')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation')
            ->assertSessionMissing('_old_input.current_password')
            ->assertSessionMissing('_old_input._method')
            ->assertSessionMissing('_old_input.locale');
    }

    public function test_the_owner_and_operator_areas_and_staff_invitations_stay_english(): void
    {
        foreach ([route('owner.login'), route('operator.login'), route('staff.activate', 'any-token')] as $url) {
            $response = $this->in('hu')->get($url);

            $this->assertStringContainsString('<html lang="en">', $response->getContent(), $url);
            $response->assertHeader('Content-Language', 'en');
        }
    }

    // --- Currency ------------------------------------------------------------------------------------------------

    public function test_hungarian_is_always_forint_and_english_offers_only_currencies_that_have_prices(): void
    {
        $catalog = app(PricingCatalog::class);
        $currency = app(DisplayCurrency::class);

        // Only the Hungarian market has prices; EU, GB and international deliberately have none.
        $this->assertSame(['HUF'], $catalog->sellableCurrencies());
        foreach (['eu', 'gb', 'international'] as $market) {
            $this->assertFalse($catalog->hasPrices($catalog->market($market)), $market);
        }

        $this->assertSame(['HUF'], $currency->available('hu'));
        $this->assertSame(['HUF'], $currency->available('en'));
        $this->assertFalse($currency->canChoose());

        foreach (['en', 'hu'] as $locale) {
            $html = $this->in($locale)->get(route('pricing'))->getContent();
            $this->assertStringContainsString('HUF (Ft)', $html);
            $this->assertStringNotContainsString('name="currency"', $html, 'Nothing to choose: no selector, only a label.');
        }
        $this->assertStringContainsString('Prices are currently in HUF only', $this->in('en')->get(route('pricing'))->getContent());
        $this->assertStringContainsString('Az árak jelenleg csak HUF pénznemben érhetők el', $this->in('hu')->get(route('pricing'))->getContent());
    }

    public function test_a_second_currency_is_offered_only_once_real_prices_exist_and_never_in_hungarian(): void
    {
        $this->withEuroPrices();
        $currency = app(DisplayCurrency::class);

        $this->assertSame(['HUF', 'EUR'], $currency->available('en'));
        $this->assertSame(['HUF'], $currency->available('hu'), 'Hungarian is HUF whatever else exists.');

        $en = $this->in('en')->get(route('pricing'))->getContent();
        $this->assertStringContainsString('name="currency"', $en);
        $this->assertStringContainsString('<option value="EUR"', $en);
        $this->assertStringNotContainsString('name="currency"', $this->in('hu')->get(route('pricing'))->getContent());

        // Choosing EUR changes the display only; Hungarian goes back to forint; English returns to the default.
        $this->in('en')->from(route('pricing'))->post(route('currency.update'), ['currency' => 'EUR'])->assertRedirect(route('pricing'));
        $this->assertSame('EUR', session('display_currency'));
        $eur = $this->in('en')->get(route('pricing'))->getContent();
        $this->assertStringContainsString('<option value="EUR" selected', $eur);
        $this->assertMatchesRegularExpression('#>\s*22\s*</span>#', $eur, 'Basic monthly in the fixture euro price list.');
        $this->assertStringNotContainsString('8,990', $eur);

        $this->in('hu')->from(route('pricing'))->post(route('locale.update'), ['locale' => 'hu']);
        $this->assertNull(session('display_currency'), 'A language switch resets the currency to the default.');
        $this->assertStringContainsString('8 990', str_replace("\u{202F}", ' ', $this->in('hu')->get(route('pricing'))->getContent()));
        $this->assertDoesNotMatchRegularExpression('#€|EUR#', $this->in('hu')->get(route('pricing'))->getContent());
    }

    public function test_the_browser_cannot_force_a_currency(): void
    {
        $this->withEuroPrices();

        // Not offered (no prices), or not allowed in this language: refused, nothing stored.
        $this->in('en')->post(route('currency.update'), ['currency' => 'USD'])->assertSessionHasErrors('currency');
        $this->in('en')->post(route('currency.update'), ['currency' => 'GBP'])->assertSessionHasErrors('currency');
        $this->in('hu')->post(route('currency.update'), ['currency' => 'EUR'])->assertSessionHasErrors('currency');
        $this->assertNull(session('display_currency'));

        // A currency, market or amount in the address does nothing.
        $html = $this->in('en')->get(route('pricing', ['currency' => 'EUR', 'market' => 'eu', 'amount' => 1]))->getContent();
        $this->assertStringContainsString('8,990', $html);
        $this->assertStringContainsString('<option value="HUF" selected', $html);
        $this->assertStringNotContainsString('<option value="EUR" selected', $html);
    }

    public function test_checkout_resolves_the_price_on_the_server_whatever_language_or_currency_is_displayed(): void
    {
        $this->withEuroPrices();
        $gateway = new FakeCheckoutGateway;
        $this->app->instance(CheckoutGateway::class, $gateway);

        $this->in('en')->post(route('currency.update'), ['currency' => 'EUR']);
        $this->in('hu')->post(route('checkout.store'), [
            'product' => 'wordpress', 'plan' => 'business', 'interval' => 'monthly',
            'email' => 'anna@company.example', 'first_name' => 'Anna', 'last_name' => 'Kovács', 'country' => 'HU',
            // Forged presentation fields: none of them is read.
            'currency' => 'EUR', 'amount' => 1, 'stripe_price_id' => 'price_evil', 'market' => 'eu', 'locale' => 'en',
        ])->assertStatus(303);

        $intent = CheckoutIntent::sole();
        $this->assertSame(['HUF', 1899000, 'price_wordpress_business_monthly', 'hu'], [$intent->currency, $intent->amount, $intent->stripe_price_id, $intent->market]);
    }

    public function test_stripe_checkout_is_shown_in_the_display_language(): void
    {
        $intent = new CheckoutIntent([
            'uuid' => 'u-1', 'email' => 'a@b.example', 'product_type' => 'wordpress', 'plan' => 'business', 'interval' => 'monthly', 'market' => 'hu',
            'stripe_price_id' => 'price_wordpress_business_monthly',
        ]);
        $gateway = new StripeCheckoutGateway('sk_test_unit');

        foreach (['hu', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame($locale, $gateway->sessionParameters($intent, null)['locale']);
        }
    }

    public function test_existing_subscription_amounts_never_change_with_language_or_currency(): void
    {
        $this->withEuroPrices();
        $customer = Customer::factory()->withUser()->create();
        $subscription = Subscription::factory()->for($customer)->create(['amount' => 1899000, 'currency' => 'HUF', 'stripe_price_id' => 'price_wordpress_business_monthly']);
        $before = $subscription->only(['amount', 'currency', 'stripe_price_id', 'interval', 'plan', 'product_type', 'market']);

        $page = fn (string $locale) => $this->actingAs($customer->user)->in($locale)->get(route('account.subscription'))->assertOk()->getContent();

        $this->assertStringContainsString('18,990', $page('en'));
        $this->assertStringContainsString('18'."\u{202F}".'990', $page('hu'));

        $this->actingAs($customer->user)->in('en')->post(route('currency.update'), ['currency' => 'EUR']);
        $this->actingAs($customer->user)->post(route('locale.update'), ['locale' => 'hu']);
        $this->actingAs($customer->user)->post(route('locale.update'), ['locale' => 'en']);

        $this->assertSame($before, $subscription->refresh()->only(array_keys($before)));
        $this->assertStringContainsString('18,990', $page('en'), 'Still forint, whatever was displayed.');
    }

    // --- Pricing ---------------------------------------------------------------------------------------------------

    public function test_prices_are_the_configured_forint_prices_in_both_languages(): void
    {
        $amounts = [8990, 18990, 28990, 14900, 24900, 39900, 89900, 189900, 289900, 149000, 249000, 399000];

        foreach (['en' => [',', 'Monthly', 'Annual', '2 months free'], 'hu' => ["\u{202F}", 'Havi', 'Éves', '2 hónap ingyenes']] as $locale => [$separator, $monthly, $annual, $free]) {
            $html = $this->in($locale)->get(route('pricing'))->getContent();

            foreach ($amounts as $amount) {
                $this->assertStringContainsString($amount >= 1000 ? number_format($amount, 0, '', '__') !== '' ? substr((string) $amount, 0, -3).$separator.substr((string) $amount, -3) : '' : (string) $amount, $html, "{$amount} in {$locale}");
            }
            $this->assertDoesNotMatchRegularExpression('/\b(899000|1899000|2899000|8990000|24900000)\b/', $html);
            foreach ([$monthly, $annual, $free] as $text) {
                $this->assertStringContainsString($text, $html, "{$text} in {$locale}");
            }
        }
    }

    // --- Login and authentication ------------------------------------------------------------------------------------

    public function test_the_login_page_is_complete_in_both_languages_and_has_the_selector(): void
    {
        $en = $this->get(route('login'))->assertOk()->getContent();
        $this->assertStringContainsString('<html lang="en">', $en);
        $this->assertStringContainsString('Log in to follow your subscription and websites.', $en);
        $this->assertStringContainsString('Forgot password?', $en);
        $this->assertMatchesRegularExpression('/<button[^>]*>\s*Magyar\s*<\/button>/', $en);

        $hu = $this->in('hu')->get(route('login'))->assertOk()->getContent();
        $this->assertStringContainsString('<html lang="hu">', $hu);
        $this->assertStringContainsString('Lépjen be az előfizetése és a weboldalai követéséhez.', $hu);
        $this->assertStringContainsString('Elfelejtette a jelszavát?', $hu);
        $this->assertStringContainsString('Jelszó', $hu);
        $this->assertStringContainsString('<title>Belépés – WP CareGrid</title>', $hu);
        $this->assertStringNotContainsString('Forgot password', $hu);
    }

    public function test_login_errors_and_messages_are_in_the_visitors_language(): void
    {
        $customer = Customer::factory()->withUser()->create();

        foreach (['en', 'hu'] as $locale) {
            $this->in($locale)->from(route('login'))->post(route('login'), ['email' => $customer->email, 'password' => 'wrong-password-1'])
                ->assertSessionHasErrors(['email' => trans('account.auth.failed', [], $locale)]);
        }
        $this->assertSame('Ezek az adatok nem egyeznek a nyilvántartásunkkal.', trans('account.auth.failed', [], 'hu'));

        // Signed in, then out: the confirmation is in the language in use.
        $this->actingAs($customer->user)->in('hu')->post(route('logout'))->assertSessionHas('status', 'Kilépett a fiókból.');
        $this->actingAs($customer->user)->in('en')->post(route('logout'))->assertSessionHas('status', 'You have been logged out.');
    }

    public function test_password_reset_messages_and_pages_are_bilingual(): void
    {
        $customer = Customer::factory()->withUser()->create();

        $this->in('hu')->get(route('password.request'))->assertOk()->assertSee('Jelszó visszaállítása');
        $this->in('hu')->from(route('password.request'))->post(route('password.email'), ['email' => $customer->email])
            ->assertSessionHas('status', trans('account.auth.link_sent', [], 'hu'));
        $this->in('en')->from(route('password.request'))->post(route('password.email'), ['email' => $customer->email])
            ->assertSessionHas('status', trans('account.auth.link_sent', [], 'en'));

        $this->in('hu')->get(route('password.reset', ['token' => 't', 'email' => $customer->email]))->assertOk()->assertSee('Új jelszó beállítása');
        $this->in('hu')->from(route('password.reset', 't'))->post(route('password.update'), [
            'token' => 'invalid', 'email' => $customer->email, 'password' => 'a-strong-pass-1', 'password_confirmation' => 'a-strong-pass-1',
        ])->assertSessionHasErrors(['email' => trans('account.auth.invalid_link', [], 'hu')]);
    }

    public function test_activation_is_bilingual_including_expired_links_and_validation_errors(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $user = $customer->user;
        $user->forceFill(['email_verified_at' => null])->save();
        $token = Password::broker('activations')->createToken($user);

        $this->in('hu')->get(route('account.activate', ['token' => $token, 'email' => $user->email]))->assertOk()
            ->assertSee('Fiók aktiválása')->assertSee('Legalább 12 karakter.')->assertSee('Jelszó megerősítése');
        $this->in('en')->get(route('account.activate', ['token' => $token, 'email' => $user->email]))->assertOk()
            ->assertSee('Activate your account')->assertSee('At least 12 characters.');

        // Validation errors are Hungarian, with the Hungarian field name.
        $response = $this->in('hu')->from(route('account.activate', $token))->post(route('account.activate.store'), [
            'token' => $token, 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'other',
        ]);
        $response->assertSessionHasErrors('password');
        $messages = implode(' ', session('errors')->get('password'));
        $this->assertStringContainsString('jelszó', $messages);
        $this->assertStringNotContainsString('field', $messages);

        // An invalid or used link leads to a Hungarian explanation and a way forward.
        $this->in('hu')->from(route('account.activate', 'nope'))->post(route('account.activate.store'), [
            'token' => 'nope', 'email' => $user->email, 'password' => 'a-strong-pass-1', 'password_confirmation' => 'a-strong-pass-1',
        ])->assertRedirect(route('password.request'))->assertSessionHasErrors(['email' => trans('account.auth.invalid_link', [], 'hu')]);
        $this->in('hu')->get(route('password.request'))->assertSee('Jelszó visszaállítása');
    }

    public function test_a_login_without_a_customer_gets_the_message_in_its_language(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->in('hu')->get(route('account.dashboard'))
            ->assertRedirect(route('login'))->assertSessionHasErrors(['email' => trans('account.auth.no_customer', [], 'hu')]);
    }

    public function test_error_pages_are_translated(): void
    {
        Route::middleware('web')->group(function () {
            Route::get('/_locale-test/forbidden', fn () => abort(403));
            Route::get('/_locale-test/expired', fn () => abort(419));
        });

        $this->in('hu')->get('/does-not-exist')->assertNotFound()->assertSee('Az oldal nem található');
        $this->in('en')->get('/does-not-exist')->assertNotFound()->assertSee('Not Found');
        $this->in('hu')->get('/_locale-test/forbidden')->assertForbidden()->assertSee('Hozzáférés megtagadva');
        $this->in('hu')->get('/_locale-test/expired')->assertStatus(419)->assertSee('Az oldal lejárt');
        $this->in('en')->get('/_locale-test/expired')->assertStatus(419)->assertSee('Page Expired');
    }

    // --- Customer portal -------------------------------------------------------------------------------------------------

    /** @return list<string> English sentences of the customer-visible translation files (placeholders excluded). */
    private function englishPhrases(): array
    {
        $phrases = [];
        foreach (['account', 'backups', 'admin_access', 'security', 'site_health', 'connector', 'checkout', 'pricing', 'home', 'dashboard', 'status'] as $file) {
            $hungarian = Arr::dot(trans($file, [], 'hu'));
            foreach (Arr::dot(trans($file, [], 'en')) as $key => $text) {
                if (is_string($text) && mb_strlen($text) >= 12 && ! str_contains($text, ':') && ($hungarian[$key] ?? null) !== $text) {
                    $phrases[] = $text;
                }
            }
        }

        return array_values(array_unique($phrases));
    }

    /** A customer with everything the portal can show: connected site, backups (good and failed), a past one. */
    private function richCustomer(): array
    {
        $site = $this->backupReadySite();
        $website = $site['website'];
        $this->verifiedBackup($website);
        $failed = $this->verifiedBackup($website);
        $failed->forceFill(['status' => BackupStatus::Failed, 'error_code' => 'STORAGE_UNAVAILABLE', 'failed_at' => now()])->save();

        return ['user' => $website->customer->user, 'website' => $website, 'backup' => $website->backups()->where('status', BackupStatus::Verified)->first()];
    }

    private function portalPages(array $c): array
    {
        return [
            'dashboard' => route('account.dashboard'),
            'websites' => route('account.websites.index'),
            'add website' => route('account.websites.create'),
            'website' => route('account.websites.show', $c['website']),
            'backups' => route('account.websites.backups.index', $c['website']),
            'restore' => route('account.websites.backups.restore', [$c['website'], $c['backup']]),
            'full admin' => route('account.websites.admin-access.full-admin', $c['website']),
            'subscription' => route('account.subscription'),
            'billing' => route('account.billing'),
            'profile' => route('account.profile'),
        ];
    }

    public function test_every_portal_page_renders_in_both_languages_and_shows_no_untranslated_english(): void
    {
        $c = $this->richCustomer();
        $phrases = $this->englishPhrases();
        $this->assertGreaterThan(300, count($phrases), 'The scan has real material.');

        $fresh = Customer::factory()->withUser()->create();
        Subscription::factory()->for($fresh)->create();

        foreach ($this->portalPages($c) as $name => $url) {
            $user = $name === 'add website' ? $fresh->user : $c['user'];
            $en = $this->actingAs($user)->in('en')->get($url);
            $this->assertSame(200, $en->status(), "{$name} (en) status");
            $this->assertStringContainsString('<html lang="en">', $en->getContent(), "{$name} (en)");

            $hu = $this->actingAs($user)->in('hu')->get($url);
            $this->assertSame(200, $hu->status(), "{$name} (hu) status");
            $this->assertStringContainsString('<html lang="hu">', $hu->getContent(), "{$name} (hu)");
            $this->assertNotSame($this->text($en), $this->text($hu), "{$name} differs between languages");

            $text = $this->text($hu);
            foreach ($phrases as $phrase) {
                $this->assertStringNotContainsString($phrase, $text, "English text on the Hungarian {$name} page: {$phrase}");
            }
        }
    }

    public function test_portal_navigation_and_dashboard_are_bilingual(): void
    {
        $customer = Customer::factory()->withUser()->create(['first_name' => 'Anna']);
        Subscription::factory()->for($customer)->create();

        $en = $this->actingAs($customer->user)->in('en')->get(route('account.dashboard'));
        foreach (['Welcome back, Anna', 'Dashboard', 'Websites', 'Subscription', 'Billing', 'Profile', 'Log out', 'Connect your first website'] as $text) {
            $en->assertSee($text);
        }

        $hu = $this->actingAs($customer->user)->in('hu')->get(route('account.dashboard'));
        foreach (['Üdv újra, Anna', 'Vezérlőpult', 'Weboldalak', 'Előfizetés', 'Számlázás', 'Profil', 'Kilépés', 'Csatlakoztassa az első weboldalát', 'Aktív'] as $text) {
            $hu->assertSee($text);
        }
        $hu->assertDontSee('Welcome back')->assertDontSee('Log out');
    }

    public function test_every_subscription_state_is_shown_in_hungarian(): void
    {
        foreach ([
            [SubscriptionStatus::Pending, 'confirming', 'Megerősítés alatt'],
            [SubscriptionStatus::PastDue, 'payment_problem', 'Fizetési késedelem'],
            [SubscriptionStatus::Canceled, 'ended', 'Lemondva'],
        ] as [$status, $notice, $label]) {
            $customer = Customer::factory()->withUser()->create();
            Subscription::factory()->for($customer)->status($status)->create();

            $this->actingAs($customer->user)->in('hu')->get(route('account.dashboard'))
                ->assertOk()->assertSee(trans("account.dashboard.{$notice}", [], 'hu'))->assertSee($label);
        }
    }

    public function test_forms_and_messages_of_the_portal_are_hungarian(): void
    {
        $site = $this->websiteWithPlan('https://company.example');
        $user = $site->customer->user;

        // Profile: success message and Hungarian validation error with the field's Hungarian name.
        $payload = ['first_name' => 'Anna', 'last_name' => 'Kovács', 'customer_type' => 'individual', 'country_code' => 'HU', 'country' => 'HU'];
        $this->actingAs($user)->in('hu')->from(route('account.profile'))->put(route('account.profile.update'), $payload)
            ->assertSessionHas('status', 'Az adatait elmentettük.');
        $this->actingAs($user)->in('hu')->from(route('account.profile'))->put(route('account.profile.update'), ['first_name' => ''] + $payload)
            ->assertSessionHasErrors('first_name');
        $this->assertStringContainsString('keresztnév', implode(' ', session('errors')->get('first_name')));

        // Add website (a customer with a free place): invalid address in Hungarian, and the page itself.
        $fresh = Customer::factory()->withUser()->create();
        Subscription::factory()->for($fresh)->create();
        $this->actingAs($fresh->user)->in('hu')->from(route('account.websites.create'))->post(route('account.websites.store'), ['url' => 'not a url', 'type' => 'wordpress'])
            ->assertSessionHasErrors('url');
        $this->actingAs($fresh->user)->in('hu')->get(route('account.websites.create'))->assertSee('Weboldal hozzáadása')->assertSee('Négy lépés a weboldala karbantartásához.');
    }

    public function test_the_connection_panel_and_pairing_texts_are_hungarian(): void
    {
        $website = $this->websiteWithPlan();
        $user = $website->customer->user;

        $this->actingAs($user)->in('hu')->get(route('account.websites.show', $website))->assertOk()
            ->assertSee('Csatlakozás a WP CareGrid Connectorral')->assertSee('Párosítókód létrehozása')->assertDontSee('Generate pairing code');
        $this->actingAs($user)->in('hu')->post(route('account.websites.pairing-code', $website))->assertRedirect();
        $this->actingAs($user)->in('hu')->get(route('account.websites.show', $website))->assertSee('Párosítókód');
    }

    // --- Saved preference, tenant isolation ----------------------------------------------------------------------------------

    public function test_a_customers_choice_is_saved_on_their_own_login_and_follows_them(): void
    {
        $a = Customer::factory()->withUser()->create();
        $b = Customer::factory()->withUser()->create();

        $this->actingAs($a->user)->post(route('locale.update'), ['locale' => 'hu'])->assertRedirect();
        $this->assertSame('hu', $a->user->refresh()->locale);
        $this->assertNull($b->user->refresh()->locale, 'Another customer is never touched.');

        // A new device: no cookie, but the saved preference applies.
        $this->flushSession();
        $this->actingAs($a->user)->get(route('account.dashboard'))->assertOk()->assertHeader('Content-Language', 'hu');
        $this->actingAs($b->user)->get(route('account.dashboard'))->assertOk()->assertHeader('Content-Language', 'en');
    }

    public function test_no_request_can_name_another_login_to_change(): void
    {
        $a = Customer::factory()->withUser()->create();
        $b = Customer::factory()->withUser()->create();

        $this->actingAs($a->user)->post(route('locale.update'), ['locale' => 'hu', 'user_id' => $b->user->id, 'id' => $b->user->id, 'email' => $b->email, 'user' => $b->user->id])->assertRedirect();

        $this->assertSame('hu', $a->user->refresh()->locale);
        $this->assertNull($b->user->refresh()->locale);
    }

    public function test_a_choice_made_before_signing_in_carries_into_the_session_and_the_saved_preference(): void
    {
        $customer = Customer::factory()->withUser()->create();

        $this->actingAs($customer->user)->get(route('account.dashboard'))->assertHeader('Content-Language', 'en');
        $this->assertNull($customer->user->refresh()->locale, 'Nothing was chosen: nothing is saved.');

        // Chosen on the public site (the cookie), then signed in: the portal uses it and remembers it.
        $this->actingAs($customer->user)->in('hu')->get(route('account.dashboard'))->assertHeader('Content-Language', 'hu');
        $this->assertSame('hu', $customer->user->refresh()->locale);

        // The latest choice on this device wins and is saved.
        $this->actingAs($customer->user)->in('en')->get(route('account.dashboard'))->assertHeader('Content-Language', 'en');
        $this->assertSame('en', $customer->user->refresh()->locale);
    }

    public function test_a_stored_value_that_is_not_supported_is_ignored(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $customer->user->forceFill(['locale' => 'xx'])->save();

        $this->actingAs($customer->user)->get(route('account.dashboard'))->assertOk()->assertHeader('Content-Language', 'en');
        $this->assertFalse(Locales::isSupported('xx'));
    }

    public function test_a_visitor_who_is_not_signed_in_writes_nothing_to_the_database(): void
    {
        Customer::factory()->withUser()->create();
        $before = User::all()->map->getAttributes()->all();

        $this->post(route('locale.update'), ['locale' => 'hu'])->assertRedirect();

        $this->assertSame($before, User::all()->map->getAttributes()->all());
    }

    public function test_an_owner_switching_language_stores_nothing_on_the_owner(): void
    {
        $owner = $this->asOwner();

        $this->post(route('locale.update'), ['locale' => 'hu'])->assertRedirect();

        $this->assertNull($owner->refresh()->locale);
    }

    public function test_a_hungarian_request_does_not_make_the_next_request_hungarian(): void
    {
        // App::setLocale() rewrites config('app.locale'); the default must not follow it (queue workers, Octane).
        $this->in('hu')->get('/')->assertHeader('Content-Language', 'hu');
        $this->withCookie(self::COOKIE, 'xx')->get('/')->assertHeader('Content-Language', 'en');
    }

    public function test_the_language_is_no_way_to_reach_another_customers_data(): void
    {
        $mine = $this->websiteWithPlan('https://mine.example');
        $theirs = $this->websiteWithPlan('https://theirs.example');

        foreach (['en', 'hu'] as $locale) {
            // The same refusal as without any language (AuthorizationTest), whatever the language says.
            $this->actingAs($mine->customer->user)->in($locale)->get(route('account.websites.show', $theirs))->assertForbidden();
            $this->actingAs($mine->customer->user)->in($locale)->get(route('account.websites.show', $theirs).'?locale=hu')->assertForbidden();
        }
        $this->actingAs($mine->customer->user)->in('hu')->get(route('account.websites.show', $mine))->assertOk();
    }

    public function test_users_have_a_nullable_locale_column(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'locale'));
        $this->assertNull(Customer::factory()->withUser()->create()->user->refresh()->locale);
        $this->assertInstanceOf(Website::class, Website::factory()->create());
    }
}
