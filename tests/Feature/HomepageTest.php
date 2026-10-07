<?php

namespace Tests\Feature;

use App\Content\DemoDashboard;
use App\Pricing\PricingCatalog;
use App\Pricing\ProductType;
use Tests\TestCase;

class HomepageTest extends TestCase
{
    public function test_homepage_renders_with_seo_metadata(): void
    {
        $response = $this->get('/')
            ->assertOk()
            ->assertSee('<title>WP CareGrid – WordPress Maintenance, Backups &amp; Security</title>', false)
            ->assertSee('<meta name="description" content="WordPress and WooCommerce maintenance, backups, security checks and functional verification for businesses.">', false)
            ->assertSee('WordPress maintained. Website working.');

        $this->assertSame(1, substr_count($response->getContent(), '<h1'));
    }

    /** config/marketing.php as it is loaded with this environment value. */
    private function marketingConfigWith(string $name, string $value): array
    {
        [$_ENV[$name], $_SERVER[$name]] = [$value, $value];
        putenv("{$name}={$value}");

        try {
            return require config_path('marketing.php');
        } finally {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }
    }

    public function test_login_links_to_the_customer_login_even_when_the_setting_is_empty(): void
    {
        // .env.example ships "MARKETING_LOGIN_URL=" with no value; it used to
        // leave the header Login as a button that went nowhere.
        config(['marketing' => $this->marketingConfigWith('MARKETING_LOGIN_URL', '')]);
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame('/login', config('marketing.links.login'));
        $this->assertSame(3, substr_count($html, 'href="/login"'), 'Header, mobile menu and footer.');
        $this->assertDoesNotMatchRegularExpression('#<button[^>]*>\s*Login\s*</button>#', $html);
        $this->get('/login')->assertOk();

        config(['marketing' => $this->marketingConfigWith('MARKETING_LOGIN_URL', 'https://app.example.com/login')]);
        $this->assertSame(3, substr_count($this->get('/')->getContent(), 'href="https://app.example.com/login"'));
    }

    public function test_no_translation_keys_leak_into_the_page(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertDoesNotMatchRegularExpression('/>\s*(home|pricing|dashboard|status)\.[a-z_]+(\.[a-z_]+)*\s*</', $html);
        $this->assertDoesNotMatchRegularExpression('/"(home|pricing|dashboard|status)\.[a-z_]+\.[a-z_.]+"/', $html);
    }

    public function test_sections_appear_in_the_agreed_order(): void
    {
        $this->get('/')->assertSeeInOrder([
            'WordPress maintenance, without the hassle.',
            'Functional checks',
            'WordPress doesn&#039;t maintain itself.',
            'We don&#039;t just update. We check.',
            'Plans and pricing',
            'All your websites in one place.',
            'Expired licenses don&#039;t go unnoticed.',
            'For stores, we check more.',
            'Need more than maintenance?',
            'Up and running in four steps.',
            'Everything is logged.',
            'Frequently asked questions',
            'Keep your WordPress maintained.',
            '&copy; '.now()->year.' WP CareGrid',
        ], false);
    }

    public function test_all_agreed_prices_are_rendered(): void
    {
        $this->post(route('currency.update'), ['currency' => 'HUF']);
        $response = $this->get('/');

        foreach (['8,990', '89,900', '18,990', '189,900', '28,990', '289,900', '14,900', '149,000', '24,900', '249,000', '39,900', '399,000'] as $amount) {
            $response->assertSee('>'.$amount."\u{00A0}Ft<", false);
        }

        $response
            ->assertSee('Ft')
            ->assertSee('/ month')
            ->assertSee('/ year')
            ->assertSee('2 months free')
            ->assertSee('Most popular')
            ->assertSee('These are final prices; no VAT is added.');
    }

    public function test_approved_brand_assets_are_used(): void
    {
        $this->get('/')
            ->assertOk()
            // Grid mark from design/brand/logo, with its single accent square.
            ->assertSee('fill="#5B8C72"', false)
            // Descriptor only on the large footer logo, not in the header.
            ->assertSee('WORDPRESS MAINTENANCE')
            ->assertSee('favicon.svg')
            ->assertSee('apple-touch-icon.png');
    }

    public function test_key_service_messages_are_present(): void
    {
        $this->get('/')
            ->assertSee('Our maintenance process includes a backup, update, technical check and functional verification.')
            ->assertSeeInOrder(['Step 01', 'Backup', 'Step 02', 'Update', 'Step 03', 'Check', 'Step 04', 'Functional test', 'Step 05', 'Done'])
            ->assertSee('WP CareGrid records it and notifies the website owner')
            ->assertSee('Not every plugin license can be checked automatically')
            ->assertSee('Custom development is a separate service')
            ->assertSeeInOrder(['Product', 'Cart', 'Checkout', 'Payment', 'Order', 'Email']);
    }

    public function test_dashboard_previews_are_labelled_as_demo_data(): void
    {
        $this->get('/')
            ->assertSee('Demo data')
            ->assertSee('Illustrative figures, not real customer data.')
            ->assertSeeInOrder(['Last backup', 'Updates', 'Security', 'License', 'Status'])
            ->assertSee('Issue found')
            ->assertSee('3 pending')
            ->assertSee("Third-party plugin and theme licenses remain the customer's responsibility.");
    }

    public function test_interactive_controls_expose_their_state(): void
    {
        $this->get('/')
            ->assertSee('role="tablist"', false)
            ->assertSee('aria-controls="mobile-menu"', false)
            ->assertSee('aria-controls="faq-1-panel"', false)
            ->assertSee('aria-pressed="true"', false);
    }

    public function test_public_demo_ui_uses_only_reserved_example_domains(): void
    {
        $html = $this->get('/')->getContent();

        foreach (['fndeco.com', 'petplaza.hu', 'A WordPress doesn'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }

        // Every domain-looking value in the demo data is an RFC 2606/6761 example name.
        $demo = json_encode([
            DemoDashboard::sites(),
            DemoDashboard::latestUpdateRun(),
            DemoDashboard::pendingUpdates(),
            DemoDashboard::licenses(),
            DemoDashboard::expiredLicense(),
            DemoDashboard::activity(),
        ]);
        preg_match_all('/"(?:domain|site)":"([^"]+)"/', $demo, $matches);

        $this->assertNotEmpty($matches[1]);
        foreach (array_unique($matches[1]) as $domain) {
            $this->assertMatchesRegularExpression('/(^|\.)example(\.(com|org|net))?$/', $domain, "{$domain} is not a reserved example domain.");
        }
    }

    public function test_full_dashboard_count_matches_the_sites_shown(): void
    {
        $sites = DemoDashboard::sites();

        $this->get('/')->assertSee(count($sites).' sites');
        $this->assertCount(6, $sites);
        $this->assertSame(7, array_sum(array_column($sites, 'updates')));
        $this->assertSame(4, count(array_filter($sites, fn ($site) => $site['updates'] > 0)));
        $this->assertSame(1, count(array_filter($sites, fn ($site) => $site['security'] === 'issue')));
        $this->assertSame(2, count(array_filter($sites, fn ($site) => $site['license'] === 'expired')));
    }

    public function test_pricing_cards_render_exactly_the_catalogue_features(): void
    {
        $catalog = app(PricingCatalog::class);
        $html = $this->get('/')->getContent();

        foreach (ProductType::cases() as $type) {
            foreach ($catalog->offers($type) as $offer) {
                // Isolate this plan's card.
                $this->assertSame(1, preg_match('#<article\s+aria-labelledby="plan-'.$offer->plan->id().'".*?</article>#s', $html, $card));
                preg_match_all('#<li class="flex gap-3">.*?<span>(.*?)</span>#s', $card[0], $items);
                $rendered = array_map(fn ($item) => html_entity_decode(trim($item)), $items[1]);

                $expected = $offer->highlightedFeatures();
                if ($offer->previousPlan) {
                    array_unshift($expected, "Everything in {$offer->previousPlan->name}");
                }

                $this->assertSame($expected, $rendered, "Card for {$offer->plan->id()} differs from the pricing data.");

                // Every listed feature (apart from the summary line) is in the plan's full list.
                foreach ($offer->highlightedFeatures() as $feature) {
                    $this->assertContains($feature, $offer->plan->featureLabels());
                }
                $this->assertStringContainsString(ucfirst($offer->plan->backupFrequency).' remote backups', $card[0]);
            }
        }
    }
}
