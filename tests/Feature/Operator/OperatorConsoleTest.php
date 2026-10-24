<?php

namespace Tests\Feature\Operator;

use App\Backups\BackupReason;
use App\Backups\BackupStatus;
use App\Backups\BackupType;
use App\Connector\DisconnectWebsite;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Website;
use App\Models\WebsiteInventory;
use App\Operator\Permission;
use App\Security\SecurityScanner;
use App\Security\SecurityState;
use App\Security\SecuritySummary;
use App\Security\ScanType;
use App\Security\Severity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OperatesConsole;
use Tests\TestCase;

class OperatorConsoleTest extends TestCase
{
    use OperatesConsole, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups(['manual' => true, 'restore' => true, 'pre_update_backup' => false]);
        config(['backup.restore.enabled' => true]);
    }

    protected function tearDown(): void
    {
        $this->tearDownBackups();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $attributes */
    private function website(string $host, array $attributes = [], array $customer = []): Website
    {
        $owner = Customer::factory()->create($customer);
        $subscription = Subscription::factory()->for($owner)->create();

        return Website::factory()->for($owner)->create(['url' => 'https://'.$host, 'subscription_id' => $subscription->id] + $attributes);
    }

    private function metric(string $html, string $key): int
    {
        $this->assertMatchesRegularExpression('/data-metric="'.$key.'"[^>]*>.*?<span class="mt-1 text-2xl[^"]*">([\d,]+)<\/span>/s', $html);
        preg_match('/data-metric="'.$key.'"[^>]*>.*?<span class="mt-1 text-2xl[^"]*">([\d,]+)<\/span>/s', $html, $m);

        return (int) str_replace(',', '', $m[1]);
    }

    public function test_dashboard_shows_zero_without_data(): void
    {
        $this->asOperator();

        $html = $this->get(route('operator.dashboard'))->assertOk()->assertSee(__('operator.dashboard.attention_none'))->getContent();

        foreach (['websites', 'healthy', 'attention', 'critical', 'backups_failed', 'security_findings', 'pending_updates', 'expired_licenses', 'connectors_offline'] as $key) {
            $this->assertSame(0, $this->metric($html, $key), $key);
        }
    }

    public function test_dashboard_counts_real_data(): void
    {
        $site = $this->consoleSite('https://company.example');                  // healthy after heartbeat below; 2 updates
        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.heartbeat'), $this->heartbeatPayload())->assertOk();
        $this->website('attention.example', ['health_status' => 'attention']);
        $this->website('critical.example', ['health_status' => 'critical']);
        $failed = $this->website('failed.example');
        $failed->backups()->create(['type' => BackupType::Full, 'reason' => BackupReason::Scheduled, 'status' => BackupStatus::Failed, 'requested_by' => 'test']);
        WebsiteInventory::where('website_id', $site['website']->id)->first()->forceFill(['plugins' => [
            ['file' => 'a/a.php', 'name' => 'A', 'version' => '1', 'active' => true, 'update_available' => true, 'license' => ['status' => 'expired']],
            ['file' => 'b/b.php', 'name' => 'B', 'version' => '1', 'active' => true, 'update_available' => null, 'license' => ['status' => 'unknown']],
        ]])->save();
        WebsiteInventory::where('website_id', $site['website']->id)->first()->refreshCounts();
        $stale = $this->consoleSite('https://stale.example');
        $stale['website']->activeConnector->forceFill(['last_seen_at' => now()->subHours(2)])->save();
        $this->asOperator();

        $html = $this->get(route('operator.dashboard'))->assertOk()->getContent();

        $this->assertSame(5, $this->metric($html, 'websites'));
        $this->assertSame(1, $this->metric($html, 'healthy'));
        $this->assertSame(1, $this->metric($html, 'attention'));
        $this->assertSame(1, $this->metric($html, 'critical'));
        $this->assertSame(1, $this->metric($html, 'backups_failed'));
        $this->assertSame(0, $this->metric($html, 'security_findings'));
        // company.example: plugin A + core; stale.example: akismet + core (from the standard inventory).
        $this->assertSame(4, $this->metric($html, 'pending_updates'));
        $this->assertSame(1, $this->metric($html, 'expired_licenses'), 'Unknown licenses are never counted as expired.');
        $this->assertSame(1, $this->metric($html, 'connectors_offline'));
        $this->assertStringContainsString('critical.example', $html);
    }

    public function test_website_list_shows_the_columns_and_states(): void
    {
        $site = $this->consoleSite();
        $site['website']->customer->update(['company_name' => 'Business Ltd']);
        $site['website']->update(['type' => 'woocommerce', 'health_status' => 'healthy']);
        $this->verifiedBackup($site['website']);
        $this->asOperator();

        $this->get(route('operator.websites.index'))->assertOk()
            ->assertSeeInOrder(['Website', 'Customer', 'Type', 'Status', 'Connector', 'Backup', 'Security', 'Updates', 'Last seen'])
            ->assertSee('company.example')
            ->assertSee('Business Ltd')
            ->assertSee('WooCommerce')
            ->assertSee(__('operator.health.healthy'))
            ->assertSee(__('operator.connector_states.connected'))
            ->assertSee(__('operator.backup_states.verified'))
            ->assertSee(__('operator.security.states.not_scanned'))
            ->assertSee(__('operator.websites.updates_pending', ['count' => 2]));
    }

    public function test_search_by_url_customer_email_and_company(): void
    {
        $this->website('alpha.example', [], ['email' => 'owner@alpha-mail.example', 'company_name' => 'Alpha Kft']);
        $this->website('beta.example', [], ['email' => 'someone@beta-mail.example', 'company_name' => 'Beta Zrt']);
        $this->asOperator();

        $search = fn (string $q) => $this->get(route('operator.websites.index', ['q' => $q]))->assertOk();

        $search('alpha.exam')->assertSee('alpha.example')->assertDontSee('beta.example');
        $search('SOMEONE@beta')->assertSee('beta.example')->assertDontSee('alpha.example');
        $search('alpha kft')->assertSee('alpha.example')->assertDontSee('beta.example');
        $search('nothing-here')->assertSee(__('operator.websites.empty'));
    }

    public function test_filters_run_in_the_database(): void
    {
        $connected = $this->consoleSite('https://online.example')['website'];
        $connected->update(['health_status' => 'healthy']);
        $this->verifiedBackup($connected);
        $stale = $this->consoleSite('https://stale.example')['website'];
        $stale->activeConnector->forceFill(['last_seen_at' => now()->subHours(3)])->save();
        $gone = $this->consoleSite('https://gone.example')['website'];
        $gone->activeConnector->forceFill(['last_seen_at' => now()->subDays(3)])->save();
        $revoked = $this->consoleSite('https://revoked.example')['website'];
        app(DisconnectWebsite::class)($revoked, 'disconnected_by_customer', 'system');
        $never = $this->website('never.example', ['type' => 'woocommerce', 'health_status' => 'critical']);
        $never->backups()->create(['type' => BackupType::Full, 'reason' => BackupReason::Manual, 'status' => BackupStatus::Running, 'requested_by' => 'test']);
        $failed = $this->website('failed.example');
        $failed->backups()->create(['type' => BackupType::Full, 'reason' => BackupReason::Manual, 'status' => BackupStatus::Corrupt, 'requested_by' => 'test']);
        $this->asOperator();

        $hosts = fn (array $filters) => collect($this->get(route('operator.websites.index', $filters))->assertOk()->viewData('websites')->items())->map->host()->sort()->values()->all();

        $this->assertSame(['online.example'], $hosts(['connector' => 'connected']));
        $this->assertSame(['stale.example'], $hosts(['connector' => 'stale']));
        $this->assertSame(['gone.example'], $hosts(['connector' => 'disconnected']));
        $this->assertSame(['revoked.example'], $hosts(['connector' => 'revoked']));
        $this->assertSame(['failed.example', 'never.example'], $hosts(['connector' => 'not_connected']));
        $this->assertSame(['online.example'], $hosts(['backup' => 'verified']));
        $this->assertSame(['failed.example'], $hosts(['backup' => 'failed']));
        $this->assertSame(['never.example'], $hosts(['backup' => 'in_progress']));
        $this->assertSame(['gone.example', 'revoked.example', 'stale.example'], $hosts(['backup' => 'none']));
        $this->assertSame(['never.example'], $hosts(['status' => 'critical']));
        $this->assertSame(['online.example'], $hosts(['status' => 'healthy']));
        $this->assertSame(['never.example'], $hosts(['type' => 'woocommerce']));
        $this->assertSame(['never.example'], $hosts(['type' => 'woocommerce', 'status' => 'critical']));
        $this->assertSame([], $hosts(['security' => 'findings']), 'No scanner: no website has findings.');
        $this->assertCount(6, $hosts(['security' => 'not_scanned']), 'Nothing has been scanned yet.');
        $this->assertSame([], $hosts(['security' => 'not_configured']), 'The engine exists: nothing is "not configured".');
        // Unknown values are ignored, not errors.
        $this->assertCount(6, $hosts(['status' => 'bogus', 'connector' => ['x'], 'sort' => 'password', 'direction' => 'sideways']));
    }

    public function test_the_list_is_paginated_and_sortable(): void
    {
        foreach (range(1, 27) as $i) {
            $this->website(sprintf('site%02d.example', $i));
        }
        $this->asOperator();

        $first = $this->get(route('operator.websites.index', ['sort' => 'website', 'direction' => 'asc']))->assertOk();
        $this->assertCount(25, $first->viewData('websites')->items());
        $this->assertSame(27, $first->viewData('websites')->total());
        $first->assertSee('site01.example')->assertDontSee('site26.example')->assertSee('sort=website', false);

        $second = $this->get(route('operator.websites.index', ['sort' => 'website', 'direction' => 'asc', 'page' => 2]))->assertOk();
        $this->assertSame(['site26.example', 'site27.example'], collect($second->viewData('websites')->items())->map->host()->all());

        $desc = $this->get(route('operator.websites.index', ['sort' => 'website', 'direction' => 'desc']))->viewData('websites');
        $this->assertSame('site27.example', $desc->items()[0]->host());
    }

    public function test_default_sort_puts_critical_websites_first(): void
    {
        $this->website('healthy.example', ['health_status' => 'healthy']);
        $this->website('critical.example', ['health_status' => 'critical']);
        $this->website('attention.example', ['health_status' => 'attention']);
        $this->asOperator();

        $hosts = collect($this->get(route('operator.websites.index'))->viewData('websites')->items())->map->host()->all();

        $this->assertSame(['critical.example', 'attention.example', 'healthy.example'], $hosts);
    }

    public function test_website_detail_shows_every_section(): void
    {
        $site = $this->consoleSite();
        $website = $site['website'];
        $website->customer->update(['company_name' => 'Business Ltd', 'country_code' => 'HU']);
        $this->verifiedBackup($website);
        $this->asOperator();

        $response = $this->get(route('operator.websites.show', $website))->assertOk();

        foreach (['overview', 'connector', 'wordpress', 'plugins', 'themes', 'woocommerce', 'backups', 'security', 'admin_access', 'activity'] as $section) {
            $response->assertSee(__('operator.detail.sections.'.$section));
        }
        $response->assertSee('Business Ltd')
            ->assertSee($website->customer->email)
            ->assertSee($website->subscription->planName())
            ->assertSee('7.1.2')                      // WordPress
            ->assertSee('8.4.19')                     // PHP
            ->assertSee('Akismet')
            ->assertSee('akismet/akismet.php')
            ->assertSee('Twenty Twenty-Five')
            ->assertSee(config('connector.latest_version'))
            ->assertSee(__('security.states.unknown'))
            ->assertSee(route('operator.websites.security', $website))
            ->assertSee(__('operator.admin_access.none'))
            ->assertSee(__('operator.admin_access.open'))
            ->assertSee(__('operator.backups.request'))
            ->assertSee(route('operator.websites.updates.store', $website))
            ->assertSee(__('operator.licenses.states.not_available'))
            ->assertSee(__('operator.licenses.states.unknown'))
            ->assertDontSee(__('operator.licenses.states.expired'));
    }

    public function test_license_details_need_licenses_view(): void
    {
        $website = $this->consoleSite()['website'];
        $this->asOperator([Permission::WebsitesView]);

        $this->get(route('operator.websites.show', $website))->assertOk()
            ->assertDontSee(__('operator.licenses.details'))
            ->assertDontSee(__('operator.detail.sections.backups').'</h2>', false);
    }

    public function test_connector_update_required_is_shown(): void
    {
        $website = $this->consoleSite()['website'];
        $website->activeConnector->update(['connector_version' => '0.1.0']);
        $this->asOperator();

        $this->get(route('operator.websites.show', $website))->assertOk()
            ->assertSee(__('operator.connector.update_required', ['version' => '0.1.0', 'latest' => config('connector.latest_version')]));
    }

    public function test_offline_site_explains_why_admin_access_is_unavailable(): void
    {
        $website = $this->consoleSite()['website'];
        $website->activeConnector->forceFill(['last_seen_at' => now()->subHours(3)])->save();
        $this->asOperator();

        $this->get(route('operator.websites.show', $website))->assertOk()
            ->assertSee(__('operator.connector_states.stale'))
            ->assertSee(__('operator.refusals.CONNECTOR_OFFLINE'))
            ->assertSee(__('operator.kinds.offline'))
            ->assertDontSee('action="'.route('operator.websites.admin-access.maintenance', $website).'"', false);
    }

    public function test_security_page_without_scans_shows_nothing_invented(): void
    {
        $this->consoleSite();
        $this->asOperator();

        $html = $this->get(route('operator.security.index'))->assertOk()
            ->assertSee(__('operator.security.no_incidents'))
            ->assertSee(__('operator.security.no_open_findings'))
            ->assertSee(__('security.unavailable_reasons.VULNERABILITY_SOURCE_NOT_CONFIGURED'))
            ->getContent();
        $this->assertSame(0, $this->metric($html, 'open_findings'));
        $this->assertSame(1, $this->metric($html, 'not_scanned'));
    }

    public function test_security_scan_history_displays_all_integrity_coverage_counts(): void
    {
        $website = $this->consoleSite()['website'];
        $website->securityScans()->create([
            'type' => ScanType::Integrity,
            'status' => 'completed',
            'requested_by' => 'operator:1',
            'scanner_version' => '1.1.0',
            'rules_version' => '2026.10.1',
            'connector_version' => '0.4.7',
            'duration_ms' => 10752,
            'coverage' => ['integrity' => [
                'status' => 'completed',
                'verified' => 5930,
                'modified' => 0,
                'missing' => 1,
                'extra' => 0,
                'unavailable' => 4,
            ]],
            'summary' => ['findings' => 0, 'by_severity' => []],
        ]);
        $this->asOperator();

        $this->get(route('operator.websites.security', $website))->assertOk()
            ->assertSee('5,930 verified')
            ->assertSee('0 modified')
            ->assertSee('1 missing')
            ->assertSee('0 extra')
            ->assertSee('4 unavailable');
    }

    public function test_security_scan_history_does_not_invent_missing_integrity_counts(): void
    {
        $website = $this->consoleSite()['website'];
        $website->securityScans()->create([
            'type' => ScanType::Integrity,
            'status' => 'partial',
            'requested_by' => 'operator:1',
            'scanner_version' => '1.1.0',
            'rules_version' => '2026.10.1',
            'connector_version' => '0.4.7',
            'coverage' => ['integrity' => [
                'status' => 'partial',
                'verified' => 1,
            ]],
            'summary' => ['findings' => 0, 'by_severity' => []],
        ]);
        $this->asOperator();

        $this->get(route('operator.websites.security', $website))->assertOk()
            ->assertSee('1 verified')
            ->assertSee('— modified')
            ->assertSee('— missing')
            ->assertSee('— extra')
            ->assertSee('— unavailable');
    }

    public function test_a_future_scanner_plugs_into_the_console(): void
    {
        $website = $this->consoleSite()['website'];
        $this->app->instance(SecurityScanner::class, new class implements SecurityScanner {
            public function isConfigured(): bool
            {
                return true;
            }

            public function summaries(iterable $websites): array
            {
                $out = [];
                foreach ($websites as $w) {
                    $out[$w->id] = new SecuritySummary(SecurityState::Findings, 3, Severity::High, now()->subHour());
                }

                return $out;
            }

            public function openFindings(): int
            {
                return 3;
            }

            public function whereState(Builder $websites, SecurityState $state): void
            {
                if ($state !== SecurityState::Findings) {
                    $websites->whereRaw('1 = 0');
                }
            }
        });
        $this->asOperator();

        $this->assertSame(3, $this->metric($this->get(route('operator.dashboard'))->getContent(), 'security_findings'));
        // The overview lists websites by their stored security state; the row comes from the scanner.
        $website->forceFill(['security_status' => 'attention', 'security_open_findings' => 3])->save();
        $this->get(route('operator.security.index'))->assertOk()->assertSee(__('operator.security.severities.high'))->assertSee($website->host());
        $this->get(route('operator.websites.show', $website))->assertOk()->assertSee(__('operator.security.severities.high'));
        $this->get(route('operator.websites.index', ['security' => 'findings']))->assertOk()->assertSee($website->host());
    }

    public function test_activity_page_lists_and_filters_the_audit_log(): void
    {
        $site = $this->consoleSite();
        $operator = $this->asOperator();
        $this->post(route('operator.websites.backups.store', $site['website']))->assertRedirect();

        $this->get(route('operator.activity.index'))->assertOk()
            ->assertSeeInOrder([__('operator.activity.columns.time'), __('operator.activity.columns.website'), __('operator.activity.columns.actor'), __('operator.activity.columns.event'), __('operator.activity.columns.result')])
            ->assertSee(__('operator.events.backup.requested'))
            ->assertSee(__('operator.events.connector.paired'))
            ->assertSee($operator->email);

        $this->get(route('operator.activity.index', ['actor' => 'operator']))->assertOk()
            ->assertSee(__('operator.events.backup.requested'))->assertDontSee(__('operator.events.connector.paired'));
        $this->get(route('operator.activity.index', ['group' => 'connector']))->assertOk()
            ->assertSee(__('operator.events.connector.paired'))->assertDontSee(__('operator.events.backup.requested'));
        $this->get(route('operator.activity.index', ['q' => 'nomatch.example']))->assertOk()->assertSee(__('operator.activity.empty'));
    }

    public function test_layout_does_not_overflow_on_small_screens(): void
    {
        $this->consoleSite();
        $this->asOperator();

        $list = $this->get(route('operator.websites.index'))->assertOk()->getContent();
        // Phones get cards; the table is only shown from tablet width, inside its own scroll container.
        $this->assertStringContainsString('grid grid-cols-1 gap-3 md:hidden', $list);
        $this->assertStringContainsString('hidden relative overflow-x-auto rounded-md border border-line bg-surface md:block', $list);
        $this->assertStringContainsString('name="viewport" content="width=device-width, initial-scale=1"', $list);

        $detail = $this->get(route('operator.websites.show', Website::first()))->assertOk()->getContent();
        // Every wide table on the detail page scrolls inside its own container.
        $this->assertSame(substr_count($detail, '<table'), substr_count($detail, 'overflow-x-auto rounded-sm border border-line">') + substr_count($detail, '<div class="relative overflow-x-auto -mx-4 -my-2">'));
    }

    public function test_translation_keys_never_show_as_text(): void
    {
        $site = $this->consoleSite();
        $this->verifiedBackup($site['website']);
        $this->asOperator();
        $this->post(route('operator.websites.backups.store', $site['website']));

        foreach ([route('operator.dashboard'), route('operator.websites.index'), route('operator.websites.show', $site['website']), route('operator.backups.index'), route('operator.security.index'), route('operator.activity.index'), route('operator.login')] as $page) {
            $html = $this->get($page)->getContent();
            $this->assertDoesNotMatchRegularExpression('/\b(operator|backups|connector|admin_access)\.[a-z_]+\.[a-z_.]+/', strip_tags($html), $page);
        }
    }
}
