<?php

namespace Tests\Feature\Account;

use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Website;
use App\Websites\WebsiteStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class WebsiteListSurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_website_list_chips_keep_visible_text_labels_and_a_tone_hook_in_both_languages(): void
    {
        foreach (['en', 'hu'] as $locale) {
            $customer = Customer::factory()->withUser()->create();
            $customer->user->forceFill(['locale' => $locale])->save();
            Subscription::factory()->for($customer)->create();

            foreach (WebsiteStatus::cases() as $status) {
                Website::factory()->for($customer)->create(['status' => $status->value]);
            }

            $html = $this->actingAs($customer->user)->get(route('account.websites.index'))->assertOk()->getContent();

            $this->assertStringContainsString('class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between m16-dash-header"', $html);
            $this->assertStringContainsString('m16-site-list__rows', $html);

            foreach (WebsiteStatus::cases() as $status) {
                $label = __("account.website_statuses.{$status->value}", [], $locale);
                $this->assertMatchesRegularExpression(
                    '/<span data-tone="'.$status->tone().'"[^>]*>\s*<span[^>]*aria-hidden="true"[^>]*><\/span>\s*'.preg_quote(e($label), '/').'\s*<\/span>/',
                    $html,
                    "Chip for {$status->value} in {$locale} must show its text label, not only a colour.",
                );
            }
        }
    }

    public function test_the_chip_tone_hook_is_only_a_data_attribute(): void
    {
        $html = Blade::render('<x-ui.status-badge tone="warning">Attention</x-ui.status-badge>');

        $this->assertStringContainsString('data-tone="warning"', $html);
        $this->assertStringContainsString('border-warning/40 bg-warning-soft text-warning-ink', $html);
    }
}
