<?php

namespace Tests\Feature\Owner;

use App\Billing\SzamlazzBillingSettings;
use App\Models\AdminAuditEvent;
use App\Models\BillingTaxConfigurationVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

final class SzamlazzBillingSettingsTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    public function test_owner_can_publish_versioned_typed_settings_and_history_is_immutable(): void
    {
        $owner = $this->asOwner();
        $first = $this->values('AAM');
        $this->put(route('owner.settings.update', 'billing_tax'), ['settings' => $first])
            ->assertRedirect(route('owner.settings.index', ['section' => 'billing_tax']));

        $versionOne = BillingTaxConfigurationVersion::query()->where('version', 1)->firstOrFail();
        $this->assertSame('AAM', $versionOne->configuration['default_tax_code']);
        $this->assertSame($owner->id, $versionOne->created_by);
        $this->assertSame('manual_review', $versionOne->configuration['tax_configuration_mode']);
        $this->assertSame(1, AdminAuditEvent::query()->where('event', 'billing_tax.configuration_published')->count());

        $second = $this->values('27');
        $this->put(route('owner.settings.update', 'billing_tax'), ['settings' => $second])->assertRedirect();
        $this->assertSame(2, BillingTaxConfigurationVersion::query()->count());
        $this->assertSame('AAM', $versionOne->fresh()->configuration['default_tax_code']);
        $this->assertSame('27', app(SzamlazzBillingSettings::class)->values()['default_tax_code']);
    }

    public function test_owner_settings_show_disabled_issuance_and_provider_tax_codes_are_not_legal_approval(): void
    {
        $this->asOwner();
        config(['szamlazz.provider_enabled' => false, 'szamlazz.dry_run' => true, 'szamlazz.issuance_enabled' => false]);

        $this->get(route('owner.settings.index', ['section' => 'billing_tax']))
            ->assertOk()
            ->assertSee(__('owner.settings.billing_tax.safety_notice'))
            ->assertSee('AAM')
            ->assertSee('EUFAD37')
            ->assertSee(__('owner.settings.billing_tax.disabled'));
    }

    public function test_non_owner_is_denied_and_unsupported_values_or_cross_group_keys_are_rejected(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer, 'owner')->get(route('owner.settings.index', ['section' => 'billing_tax']))->assertRedirect(route('owner.login'));

        $this->asOwner();
        $this->put(route('owner.settings.update', 'billing_tax'), ['settings' => $this->values('AAM') + ['issuance_enabled' => true]])
            ->assertSessionHasErrors('settings');
        $this->put(route('owner.settings.update', 'billing_tax'), ['settings' => $this->values('not-a-provider-code')])
            ->assertSessionHasErrors();
        $this->assertSame(0, BillingTaxConfigurationVersion::query()->count());
    }

    private function values(?string $taxCode): array
    {
        return [
            'seller_profile_id' => null,
            'invoice_language' => 'en',
            'default_payment_method' => 'bank_transfer',
            'payment_deadline_policy' => 'days_8',
            'fulfillment_date_policy' => 'payment_date',
            'invoice_prefix' => 'WPCG',
            'notify_customer' => '0',
            'currency_policy' => 'block_foreign_without_fx',
            'tax_configuration_mode' => 'manual_review',
            'default_tax_code' => $taxCode,
            'effective_at' => now()->toDateString(),
        ];
    }
}
