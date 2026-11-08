<?php

namespace Tests\Feature\Account;

use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Website;
use App\Websites\WebsiteStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class EmptyStatesTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $locale, bool $withSubscription = true): Customer
    {
        $customer = Customer::factory()->withUser()->create();
        $customer->user->forceFill(['locale' => $locale])->save();
        if ($withSubscription) {
            Subscription::factory()->for($customer)->create();
        }

        return $customer;
    }

    public function test_a_customer_without_websites_sees_helpful_empty_states_in_both_languages(): void
    {
        foreach (['en' => ['No websites yet', 'Add a website'], 'hu' => ['Még nincs weboldal', 'Weboldal hozzáadása']] as $locale => [$title, $action]) {
            $customer = $this->customer($locale);
            Website::factory()->create(['url' => "https://other-customer-{$locale}.example"]);

            $list = $this->actingAs($customer->user)->get(route('account.websites.index'))->assertOk()->getContent();
            $this->assertStringContainsString('m16-empty', $list);
            $this->assertMatchesRegularExpression('/<h2 class="[^"]*">'.preg_quote($title, '/').'<\/h2>/', $list, 'The empty state heading follows the h1.');
            $this->assertStringContainsString($action, $list);
            $this->assertStringContainsString(route('account.websites.create'), $list);
            $this->assertStringNotContainsString('other-customer', $list, 'Another customer\'s website is never shown.');

            $home = $this->actingAs($customer->user)->get(route('account.dashboard'))->assertOk()->getContent();
            $this->assertStringContainsString(__('account.websites.empty_text', [], $locale), $home, 'The websites panel explains what appears here.');
        }
    }

    public function test_a_customer_with_websites_sees_the_list_and_counter_not_the_empty_state(): void
    {
        $customer = $this->customer('en');
        Website::factory()->for($customer)->create(['status' => 'connected']);

        $list = $this->actingAs($customer->user)->get(route('account.websites.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('m16-empty', $list);
        $home = $this->actingAs($customer->user)->get(route('account.dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('m16-empty', $home);
        $this->assertStringContainsString('1 website added', $home);
        $this->assertStringNotContainsString(__('account.websites.empty_text'), $home);
    }

    public function test_a_customer_without_a_plan_sees_a_plan_empty_state_with_the_plans_link(): void
    {
        foreach (['en' => ['No maintenance plan yet', 'View plans'], 'hu' => ['Még nincs karbantartási csomag', 'Csomagok megtekintése']] as $locale => [$title, $action]) {
            $customer = $this->customer($locale, withSubscription: false);

            $home = $this->actingAs($customer->user)->get(route('account.dashboard'))->assertOk()->getContent();
            $this->assertStringContainsString($title, $home);
            $this->assertStringContainsString($action, $home);
            $this->assertStringContainsString('m16-empty', $home);
        }
    }

    public function test_the_status_chip_component_always_shows_a_text_label_and_a_tone_hook(): void
    {
        $fromStatus = Blade::render('<x-account.status-chip :status="$status" />', ['status' => WebsiteStatus::Attention]);
        $this->assertStringContainsString('data-tone="warning"', $fromStatus);
        $this->assertStringContainsString('m16-chip', $fromStatus);
        $this->assertStringContainsString(e(WebsiteStatus::Attention->label()), $fromStatus);

        $fromTone = Blade::render('<x-account.status-chip tone="positive">Verified</x-account.status-chip>');
        $this->assertStringContainsString('data-tone="positive"', $fromTone);
        $this->assertStringContainsString('Verified', $fromTone);
        $this->assertStringContainsString('aria-hidden="true"', $fromTone, 'The dot is decorative.');
    }
}
