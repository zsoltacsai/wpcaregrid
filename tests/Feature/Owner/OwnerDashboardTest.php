<?php

namespace Tests\Feature\Owner;

use App\Backups\BackupStatus;
use App\Billing\SubscriptionStatus;
use App\Connector\HealthStatus;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\WebsiteInventory;
use App\Owner\BusinessMetrics;
use App\SiteHealth\SiteHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsOwner;
use Tests\Concerns\RunsSecurityScans;
use Tests\TestCase;

/** The owner dashboard counts stored data only. */
class OwnerDashboardTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase, RunsSecurityScans;

    public function test_an_empty_system_shows_zeros_not_invented_figures(): void
    {
        $this->asOwner();

        $metrics = app(BusinessMetrics::class)->all();
        $this->assertSame(0, $metrics['mrr'][0]->amount);
        $this->assertSame('HUF', $metrics['mrr'][0]->currency->code);
        foreach (['active_customers', 'active_subscriptions', 'active_websites', 'healthy_websites', 'attention_websites', 'critical_websites', 'failed_backups', 'open_security_findings', 'expired_licenses'] as $key) {
            $this->assertSame(0, $metrics[$key], $key);
        }

        $this->get(route('owner.dashboard'))->assertOk()
            ->assertSee('Monthly recurring revenue')
            ->assertSee("0\u{00A0}Ft", false)
            ->assertSee(__('owner.audit.empty'));
    }

    public function test_mrr_counts_active_subscriptions_per_month_and_never_mixes_currencies(): void
    {
        $customer = Customer::factory()->create();
        Subscription::factory()->for($customer)->create(['amount' => 899000, 'interval' => 'monthly', 'currency' => 'HUF']);
        Subscription::factory()->for($customer)->create(['amount' => 8990000, 'interval' => 'annual', 'currency' => 'HUF']); // 749 166 per month
        Subscription::factory()->for($customer)->create(['amount' => 5000, 'interval' => 'monthly', 'currency' => 'EUR', 'market' => 'eu']);
        // Not paying (yet / any more): not revenue.
        Subscription::factory()->for($customer)->status(SubscriptionStatus::Trialing)->create(['amount' => 1000000]);
        Subscription::factory()->for($customer)->status(SubscriptionStatus::Canceled)->create(['amount' => 1000000]);
        Subscription::factory()->for($customer)->status(SubscriptionStatus::PastDue)->create(['amount' => 1000000]);

        $mrr = collect(app(BusinessMetrics::class)->mrr())->mapWithKeys(fn ($money) => [$money->currency->code => $money->amount]);

        $this->assertSame(['EUR' => 5000, 'HUF' => 899000 + intdiv(8990000, 12)], $mrr->all());
    }

    public function test_counts_come_from_customers_subscriptions_site_health_backups_findings_and_licenses(): void
    {
        $this->asOwner();
        $healthy = $this->customerWithWebsite('https://healthy.example');
        $attention = $this->customerWithWebsite('https://attention.example');
        $inactive = $this->customerWithWebsite('https://ended.example', subscription: ['status' => SubscriptionStatus::Canceled]);
        Customer::factory()->create(); // no subscription: not an active customer

        $attention['website']->forceFill(['health_status' => HealthStatus::Attention])->save();
        $healthy['website']->forceFill(['health_status' => HealthStatus::Healthy])->save();
        $healthy['website']->backups()->create(['type' => 'full', 'reason' => 'manual', 'status' => BackupStatus::Failed, 'storage_provider' => 'local', 'requested_by' => 'system']);
        WebsiteInventory::create(['website_id' => $healthy['website']->id, 'core' => [], 'plugins' => [], 'themes' => [], 'licenses_expired' => 2, 'updates_pending' => 0]);

        $metrics = app(BusinessMetrics::class)->all();

        $this->assertSame(2, $metrics['active_customers']);
        $this->assertSame(2, $metrics['active_subscriptions']);
        $this->assertSame(2, $metrics['active_websites']);
        $this->assertSame(1, $metrics['failed_backups']);
        $this->assertSame(2, $metrics['expired_licenses']);

        // The same Site Health operators and customers see, not a second calculation.
        $health = app(SiteHealth::class);
        $expected = ['healthy' => 0, 'attention' => 0, 'critical' => 0, 'unknown' => 0];
        foreach ([$healthy['website'], $attention['website']] as $website) {
            $expected[$health->for($website->refresh())->overall->value]++;
        }
        $this->assertSame($expected['healthy'], $metrics['healthy_websites']);
        $this->assertSame($expected['attention'], $metrics['attention_websites']);
        $this->assertSame($expected['critical'], $metrics['critical_websites']);
        $this->assertSame(2, $metrics['healthy_websites'] + $metrics['attention_websites'] + $metrics['critical_websites'] + $metrics['unknown_websites']);
        $this->assertNotNull($inactive);

        $this->get(route('owner.dashboard'))->assertOk()->assertSee('data-metric="failed_backups"', false);
    }

    public function test_open_security_findings_are_counted(): void
    {
        $site = $this->securitySite();
        $this->reportScan($site, $this->requestScan($site), [$this->suspiciousFile()]);

        $this->assertSame(1, app(BusinessMetrics::class)->all()['open_security_findings']);
    }
}
