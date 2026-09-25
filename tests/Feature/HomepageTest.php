<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomepageTest extends TestCase
{
    public function test_homepage_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<h1', false)
            ->assertSee('WordPress maintenance, without the hassle.');
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

        foreach (['8 990', '89 900', '18 990', '189 900', '28 990', '289 900', '14 900', '149 000', '24 900', '249 000', '39 900', '399 000'] as $amount) {
            $response->assertSee(str_replace(' ', "\u{202F}", $amount));
        }

        $response->assertSee('Ft/month')->assertSee('Ft/year')->assertSee('2 months free')->assertSee('Most popular');
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
            ->assertSeeInOrder(['Backup', 'Update', 'Check', 'Functional test'])
            ->assertSee('notified when a license is detected as expired or invalid')
            ->assertSee('separate paid service');
    }

    public function test_dashboard_previews_are_labelled_as_demo_data(): void
    {
        $this->get('/')
            ->assertSee('Demo data')
            ->assertSee('Illustrative figures, not real customer data.')
            ->assertSee('Third-party plugin and theme licenses remain the customer\'s responsibility.', false);
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
