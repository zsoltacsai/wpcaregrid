<?php

namespace Tests\Feature\Owner;

use App\Billing\SubscriptionStatus;
use App\Models\AdminAuditEvent;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\WebsiteEvent;
use App\Security\ScanType;
use App\SiteHealth\Dimension;
use App\SiteHealth\SiteHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsOwner;
use Tests\Concerns\RunsSecurityScans;
use Tests\TestCase;

/** Customer and website management in the owner area. */
class OwnerCustomersAndWebsitesTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase, RunsSecurityScans;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups(['manual' => true]);
    }

    protected function tearDown(): void
    {
        $this->tearDownBackups();
        parent::tearDown();
    }

    public function test_customers_are_searched_and_filtered_in_the_database(): void
    {
        $this->asOwner();
        $anna = $this->customerWithWebsite('https://anna-shop.example', ['email' => 'anna@acme.example', 'company_name' => 'Acme Kft', 'country_code' => 'HU'], ['product_type' => 'woocommerce', 'plan' => 'secure']);
        $bob = $this->customerWithWebsite('https://bob.example', ['email' => 'bob@other.example', 'company_name' => 'Other Ltd', 'country_code' => 'AT'], ['status' => SubscriptionStatus::PastDue]);
        $cleo = Customer::factory()->create(['email' => 'cleo@nowhere.example', 'country_code' => 'DE']);

        $list = fn (array $query) => $this->get(route('owner.customers.index', $query))->assertOk();

        $list([])->assertSee('anna@acme.example')->assertSee('bob@other.example')->assertSee('cleo@nowhere.example')->assertSee('3 results');
        $list(['q' => 'ACME'])->assertSee('anna@acme.example')->assertDontSee('bob@other.example');
        $list(['q' => 'anna-shop'])->assertSee('anna@acme.example')->assertDontSee('cleo@nowhere.example');
        $list(['q' => 'other.example'])->assertSee('bob@other.example')->assertDontSee('anna@acme.example');
        $list(['q' => '%'])->assertSee('0 results');
        $list(['subscription' => 'past_due'])->assertSee('bob@other.example')->assertDontSee('anna@acme.example');
        $list(['subscription' => 'none'])->assertSee('cleo@nowhere.example')->assertDontSee('bob@other.example');
        $list(['country' => 'AT'])->assertSee('bob@other.example')->assertDontSee('anna@acme.example');
        $list(['type' => 'woocommerce', 'plan' => 'secure'])->assertSee('anna@acme.example')->assertDontSee('bob@other.example');
        // Unknown filter values are ignored, not passed to the query.
        $list(['subscription' => "active' or 1=1", 'country' => 'XX', 'plan' => 'gold'])->assertSee('3 results');
        $this->assertNotNull($anna && $bob && $cleo);
    }

    public function test_the_customer_list_is_paginated_on_the_server(): void
    {
        $this->asOwner();
        Customer::factory()->count(30)->create();

        $this->get(route('owner.customers.index'))->assertOk()->assertSee('30 results')->assertSee('page=2', false);
        $this->get(route('owner.customers.index', ['page' => 2]))->assertOk();
    }

    public function test_customer_detail_shows_business_data_but_no_secrets(): void
    {
        $this->asOwner();
        $site = $this->securitySite();
        $customer = $site['website']->customer;
        $customer->update(['company_name' => 'Acme Kft', 'tax_id' => 'HU12345678', 'stripe_customer_id' => 'cus_TEST123', 'billing_address' => ['line1' => 'Fő utca 1', 'city' => 'Budapest']]);
        $hash = $customer->user->getAuthPassword();

        $this->get(route('owner.customers.show', $customer))->assertOk()
            ->assertSee('Acme Kft')->assertSee('HU••••5678')->assertDontSee('HU12345678')->assertSee('Fő utca 1')->assertSee('cus_TEST123')
            ->assertSee('company.example')->assertSee($site['website']->subscription->planName())
            ->assertDontSee($hash)
            ->assertDontSee($site['credential']);
    }

    public function test_the_owner_edits_contact_and_billing_details_and_the_change_is_audited(): void
    {
        $owner = $this->asOwner();
        $site = $this->customerWithWebsite(customer: ['email' => 'anna@acme.example']);
        $customer = $site['customer'];
        $customer->update(['stripe_customer_id' => 'cus_KEEP']);

        $this->put(route('owner.customers.update', $customer), [
            'first_name' => 'Anna', 'last_name' => 'Kovács', 'customer_type' => 'company', 'company_name' => 'Acme Kft',
            'country_code' => 'AT', 'tax_id' => 'ATU1234', 'address_line1' => 'Ring 1', 'city' => 'Wien', 'postal_code' => '1010',
            // Not editable: ignored.
            'email' => 'evil@attacker.example', 'stripe_customer_id' => 'cus_EVIL', 'active' => '0', 'password' => 'x', 'user_id' => 999,
        ])->assertRedirect(route('owner.customers.show', $customer));

        $customer->refresh();
        $this->assertSame(['Anna', 'Kovács', 'company', 'Acme Kft', 'AT', 'ATU1234'], [$customer->first_name, $customer->last_name, $customer->customer_type, $customer->company_name, $customer->country_code, $customer->taxIdentifier()]);
        $this->assertNull($customer->tax_id);
        $this->assertNotSame('ATU1234', DB::table('customers')->where('id', $customer->id)->value('tax_identifier_encrypted'));
        $this->assertSame(1, $customer->billingIdentities()->count());
        $this->assertEquals(['line1' => 'Ring 1', 'postal_code' => '1010', 'city' => 'Wien'], $customer->billing_address);
        $this->assertSame(['anna@acme.example', 'cus_KEEP', true], [$customer->email, $customer->stripe_customer_id, $customer->active]);
        $this->assertSame('Anna Kovács', $customer->user->refresh()->name);

        $event = AdminAuditEvent::where('event', 'customer.updated')->sole();
        $this->assertSame([$owner->id, 'owner', 'customer', $customer->uuid], [$event->actor_user_id, $event->actor_type, $event->subject_type, $event->subject_key]);
        $this->assertEqualsCanonicalizing(['first_name', 'last_name', 'customer_type', 'company_name', 'country_code', 'tax_id', 'billing_address'], $event->data['fields']);
        $this->assertStringNotContainsString('Ring 1', json_encode($event->data), 'Field names only, not values.');

        // Invalid input is refused and nothing is recorded.
        $this->put(route('owner.customers.update', $customer), ['first_name' => '', 'last_name' => 'X', 'customer_type' => 'alien', 'country_code' => 'ZZ'])->assertSessionHasErrors(['first_name', 'customer_type', 'country_code']);
        $this->assertSame(1, AdminAuditEvent::where('event', 'customer.updated')->count());
    }

    public function test_websites_show_unified_site_health_and_link_customers(): void
    {
        $this->asOwner();
        $site = $this->securitySite('https://shop.example');
        $other = $this->customerWithWebsite('https://plain.example');

        $this->get(route('owner.websites.index'))->assertOk()->assertSee('shop.example')->assertSee('plain.example')->assertSee('Site health');
        $this->get(route('owner.websites.index', ['q' => 'shop']))->assertSee('shop.example')->assertDontSee('plain.example');
        $this->get(route('owner.websites.index', ['type' => 'woocommerce']))->assertDontSee('shop.example');

        $report = app(SiteHealth::class)->for($site['website']->refresh());
        $this->get(route('owner.websites.show', $site['website']))->assertOk()
            ->assertSee($report->overall->label())
            ->assertSee($report->dimension(Dimension::Backups)->reason)
            ->assertSee($report->dimension(Dimension::Connector)->reason)
            ->assertDontSee($site['credential']);
        $this->assertNotNull($other);
    }

    public function test_owner_website_actions_go_through_the_backup_and_scan_services(): void
    {
        $owner = $this->asOwner();
        $site = $this->securitySite();

        $this->post(route('owner.websites.backups.store', $site['website']))->assertRedirect()->assertSessionHas('status');
        $backup = $site['website']->backups()->sole();
        $this->assertSame(['manual', 'owner:'.$owner->id], [$backup->reason->value, $backup->requested_by]);
        $requested = WebsiteEvent::where('event', 'backup.requested')->sole();
        $this->assertSame(['owner', $owner->id], [$requested->actor_type, $requested->actor_user_id]);

        // The service's own refusal comes back as a refusal, with its code.
        $this->post(route('owner.websites.backups.store', $site['website']))->assertRedirect()->assertSessionHas('owner_error', fn ($e) => $e['code'] === 'BACKUP_IN_PROGRESS');
        $this->assertSame(1, $site['website']->backups()->count());

        $this->post(route('owner.websites.security-scans.store', $site['website']))->assertRedirect()->assertSessionHas('status');
        $scan = $site['website']->securityScans()->sole();
        $this->assertSame([ScanType::Full, 'owner', $owner->id], [$scan->type, $scan->actor_type, $scan->actor_user_id]);

        // A website without an active plan is refused by the service, exactly as for operators.
        $site['website']->subscription->update(['status' => SubscriptionStatus::Canceled]);
        $site['website']->securityScans()->update(['status' => 'completed']);
        $this->post(route('owner.websites.security-scans.store', $site['website']))->assertSessionHas('owner_error', fn ($e) => $e['code'] === 'SUBSCRIPTION_INACTIVE');
    }

    public function test_subscriptions_of_another_customer_are_not_shown_on_a_customer(): void
    {
        $this->asOwner();
        $anna = $this->customerWithWebsite('https://anna.example', ['company_name' => 'Anna Co']);
        $bob = $this->customerWithWebsite('https://bob.example', ['company_name' => 'Bob Co'], ['stripe_price_id' => 'price_bob_special']);
        Subscription::factory()->for($bob['customer'])->create();

        $this->get(route('owner.customers.show', $anna['customer']))->assertOk()->assertSee('anna.example')->assertDontSee('bob.example')->assertDontSee('Bob Co');
    }
}
