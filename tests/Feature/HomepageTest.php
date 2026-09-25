<?php

namespace Tests\Feature;

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
            'A WordPress doesn&#039;t maintain itself.',
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
        $response = $this->get('/');

        foreach (['8,990', '89,900', '18,990', '189,900', '28,990', '289,900', '14,900', '149,000', '24,900', '249,000', '39,900', '399,000'] as $amount) {
            $response->assertSee('>'.$amount.'<', false);
        }

        $response
            ->assertSee('Ft / month')
            ->assertSee('Ft / year')
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
}
