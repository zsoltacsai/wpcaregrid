<?php

namespace Tests\Feature\Account;

use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_navigation_shell_exposes_an_accessible_menu_in_both_languages(): void
    {
        foreach (['en', 'hu'] as $locale) {
            $customer = Customer::factory()->withUser()->create();
            $customer->user->forceFill(['locale' => $locale])->save();
            Subscription::factory()->for($customer)->create();

            $html = $this->actingAs($customer->user)->get(route('account.dashboard'))->assertOk()->getContent();

            $this->assertStringContainsString('m16-account-shell', $html);
            $this->assertStringContainsString('x-ref="menuButton"', $html);
            $this->assertStringContainsString('aria-controls="account-nav"', $html);
            $this->assertStringContainsString('aria-expanded="false"', $html);
            $this->assertStringContainsString('id="account-nav"', $html);
            $this->assertStringContainsString('@click.outside="open = false"', $html);
            $this->assertStringContainsString('$refs.menuButton.focus()', $html);
            $this->assertSame(1, preg_match('/<nav aria-label="[^"]+"/', $html), 'One labelled navigation landmark.');
            $this->assertSame(1, substr_count($html, 'aria-current="page"'), 'Exactly the current page is marked.');
            $this->assertStringContainsString('<a href="#main"', $html, 'The skip link stays.');
        }
    }

    public function test_the_shell_is_not_rendered_for_guests(): void
    {
        $this->get(route('account.dashboard'))->assertRedirect(route('login'));
    }
}
