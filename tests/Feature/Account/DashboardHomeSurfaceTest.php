<?php

namespace Tests\Feature\Account;

use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardHomeSurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_home_keeps_labelled_panels_and_a_text_status_in_both_languages(): void
    {
        foreach (['en', 'hu'] as $locale) {
            $customer = Customer::factory()->withUser()->create();
            $customer->user->forceFill(['locale' => $locale])->save();
            $subscription = Subscription::factory()->for($customer)->create();
            Website::factory()->for($customer)->create(['status' => 'connected']);

            $html = $this->actingAs($customer->user)->get(route('account.dashboard'))->assertOk()->getContent();

            $this->assertStringContainsString('m16-dash-header', $html);
            $this->assertSame(2, substr_count($html, 'm16-dash-panel'), 'Subscription and websites panels.');
            $this->assertStringContainsString('m16-dash-setup', $html);
            $this->assertStringContainsString('aria-labelledby="dashboard-subscription"', $html);
            $this->assertStringContainsString('aria-labelledby="dashboard-websites"', $html);

            app()->setLocale($locale);
            $label = e($subscription->status->label());
            app()->setLocale('en');
            $this->assertMatchesRegularExpression(
                '/<span data-tone="'.$subscription->status->tone().'"[^>]*>\s*<span[^>]*aria-hidden="true"[^>]*><\/span>\s*'.preg_quote($label, '/').'\s*<\/span>/',
                $html,
                "Subscription status in {$locale} must show its text label.",
            );
        }
    }

    public function test_dashboard_home_is_not_shown_to_another_customers_session(): void
    {
        $this->get(route('account.dashboard'))->assertRedirect();
    }
}
