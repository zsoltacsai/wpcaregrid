<?php

namespace Tests\Feature\Owner;

use App\Models\AdminAuditEvent;
use App\Models\SellerTaxProfile;
use App\Models\User;
use App\Tax\SellerTaxProfileCompleteness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

final class SellerTaxProfileOwnerManagementTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    public function test_owner_can_publish_a_versioned_profile_and_audit_contains_no_seller_identity_values(): void
    {
        $owner = $this->asOwner();
        $this->post(route('owner.settings.seller-profiles.store'), $this->profileValues())
            ->assertRedirect(route('owner.settings.index', ['section' => 'billing_tax']))
            ->assertSessionHas('status');

        $profile = SellerTaxProfile::query()->sole();
        $this->assertSame(1, $profile->version);
        $this->assertSame($owner->id, $profile->created_by);
        $this->assertSame('Example Synthetic Seller Ltd', $profile->legal_name);
        $this->assertSame('Budapest', $profile->seller_address['city']);
        $this->assertSame('verified', $profile->verification_status->value);
        $this->assertSame([], app(SellerTaxProfileCompleteness::class)->missingFields($profile));

        $audit = AdminAuditEvent::query()->where('event', 'billing_tax.seller_profile_published')->sole();
        $this->assertSame($owner->id, $audit->actor_user_id);
        $this->assertContains('legal_name', $audit->data['field_names']);
        $this->assertStringNotContainsString('Example Synthetic Seller Ltd', json_encode($audit->data, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('OWNER-APPROVED-TEST-EVIDENCE', json_encode($audit->data, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Synthetic Street 1', json_encode($audit->data, JSON_THROW_ON_ERROR));

        $this->get(route('owner.settings.index', ['section' => 'billing_tax']))
            ->assertOk()
            ->assertSee(__('owner.settings.billing_tax.seller_profile_management.complete'));
    }

    public function test_owner_can_publish_a_non_overlapping_successor_without_mutating_historical_profile(): void
    {
        $this->asOwner();
        $firstValues = $this->profileValues();
        $firstValues['effective_to'] = now()->toDateString();
        $this->post(route('owner.settings.seller-profiles.store'), $firstValues)->assertRedirect();
        $first = SellerTaxProfile::query()->sole();
        $originalAddress = $first->seller_address;

        $successorValues = $this->profileValues();
        $successorValues['effective_from'] = now()->addDay()->toDateString();
        $successorValues['legal_name'] = 'Example Synthetic Seller Successor Ltd';
        $this->post(route('owner.settings.seller-profiles.store'), $successorValues)->assertRedirect();

        $this->assertSame(2, SellerTaxProfile::query()->count());
        $this->assertSame($originalAddress, $first->fresh()->seller_address);
        $this->assertSame('Example Synthetic Seller Successor Ltd', SellerTaxProfile::query()->where('version', 2)->value('legal_name'));
        $this->assertSame(2, AdminAuditEvent::query()->where('event', 'billing_tax.seller_profile_published')->count());
    }

    public function test_invalid_profile_is_rejected_without_flashing_submitted_identity_values(): void
    {
        $this->asOwner();
        $values = $this->profileValues();
        unset($values['seller_address']['city']);

        $response = $this->from(route('owner.settings.index', ['section' => 'billing_tax']))
            ->post(route('owner.settings.seller-profiles.store'), $values)
            ->assertRedirect(route('owner.settings.index', ['section' => 'billing_tax']))
            ->assertSessionHasErrors('seller_address.city');

        $this->assertSame([], $response->getSession()->get('_old_input', []));
        $this->assertSame(0, SellerTaxProfile::query()->count());
    }

    public function test_customers_and_operators_cannot_manage_seller_profiles(): void
    {
        $values = $this->profileValues();
        $customer = User::factory()->create();
        $this->actingAs($customer, 'owner')->post(route('owner.settings.seller-profiles.store'), $values)
            ->assertRedirect(route('owner.login'));

        $operator = User::factory()->operator()->create();
        $this->actingAs($operator, 'owner')->post(route('owner.settings.seller-profiles.store'), $values)
            ->assertRedirect(route('owner.login'));

        $this->assertSame(0, SellerTaxProfile::query()->count());
        $this->assertSame(0, AdminAuditEvent::query()->where('event', 'billing_tax.seller_profile_published')->count());
    }

    private function profileValues(): array
    {
        return [
            'legal_name' => 'Example Synthetic Seller Ltd',
            'establishment_country' => 'hu',
            'seller_address' => [
                'address_line1' => 'Synthetic Street 1',
                'address_line2' => '',
                'postal_code' => '1011',
                'city' => 'Budapest',
                'region' => '',
                'country_code' => 'HU',
            ],
            'aam_status' => 'unknown',
            'verification_status' => 'verified',
            'effective_from' => now()->toDateString(),
            'effective_to' => '',
            'evidence_reference' => 'OWNER-APPROVED-TEST-EVIDENCE',
        ];
    }
}
