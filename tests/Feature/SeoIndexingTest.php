<?php

namespace Tests\Feature;

use App\Checkout\CheckoutStatus;
use App\Content\ContentRepository;
use App\Content\Schema\ContentSchema;
use App\Models\CheckoutIntent;
use App\Models\ContentEntry;
use App\Models\Customer;
use App\Models\User;
use App\Operator\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SeoIndexingTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_and_pricing_are_indexable_only_after_explicit_production_opt_in(): void
    {
        $this->allowProductionIndexing();

        foreach (['/en/', '/hu/', '/en/pricing', '/hu/pricing'] as $path) {
            $response = $this->get('https://wpcaregrid.com'.$path.'?utm_source=campaign')->assertOk();
            $response->assertSee('<meta name="robots" content="index, follow">', false);
            $response->assertSee('<link rel="canonical" href="https://wpcaregrid.com'.$path.'">', false);
            $response->assertDontSee('utm_source=campaign', false);
        }
    }

    public function test_staging_and_mismatched_hosts_are_noindex_even_when_the_opt_in_is_true(): void
    {
        config([
            'app.env' => 'staging',
            'app.url' => 'https://staging.wpcaregrid.com',
            'seo.indexing_enabled' => true,
            'seo.production_host' => 'wpcaregrid.com',
            'http.force_https' => false,
        ]);

        foreach (['/en/', '/hu/', '/en/pricing', '/hu/pricing'] as $path) {
            $this->get('https://staging.wpcaregrid.com'.$path)
                ->assertOk()
                ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
        }

        config(['app.env' => 'production', 'app.url' => 'https://wpcaregrid.com']);

        $this->get('https://staging.wpcaregrid.com/en/')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);

        $this->allowProductionIndexing();
        config(['seo.indexing_enabled' => false]);

        $this->get('https://wpcaregrid.com/en/')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);

        config(['app.url' => 'https://staging.wpcaregrid.com']);

        $this->get('https://staging.wpcaregrid.com/en/')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function test_invalid_canonical_and_untrusted_host_fall_back_to_configured_origin_without_query(): void
    {
        $this->allowProductionIndexing();

        $seo = ContentSchema::section('seo.home')->builtIn('en');
        $seo['canonical_url'] = 'https://attacker.example/forged';
        $entry = new ContentEntry(['key' => 'seo.home', 'locale' => 'en']);
        $entry->forceFill(['published' => $seo, 'published_at' => now()])->save();
        Cache::forget(ContentRepository::CACHE_KEY);
        app()->forgetInstance(ContentRepository::class);

        $this->get('https://wpcaregrid.com/en/?campaign=one')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="https://wpcaregrid.com/en/">', false)
            ->assertDontSee('attacker.example', false)
            ->assertDontSee('campaign=one', false);

        $this->get('https://attacker.example/en/?campaign=two')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertSee('<link rel="canonical" href="https://wpcaregrid.com/en/">', false)
            ->assertSee('<meta property="og:url" content="https://wpcaregrid.com/en/">', false)
            ->assertDontSee('rel="canonical" href="https://attacker.example', false)
            ->assertDontSee('property="og:url" content="https://attacker.example', false);

        $seo['canonical_url'] = 'https://wpcaregrid.com/en/';
        $entry->forceFill(['published' => $seo, 'published_at' => now()])->save();
        Cache::forget(ContentRepository::CACHE_KEY);
        app()->forgetInstance(ContentRepository::class);

        $this->get('https://wpcaregrid.com/en/?campaign=three')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="https://wpcaregrid.com/en/">', false)
            ->assertDontSee('campaign=three', false);
    }

    public function test_checkout_and_customer_authentication_pages_render_noindex(): void
    {
        $this->allowProductionIndexing();

        $intent = CheckoutIntent::create([
            'status' => CheckoutStatus::CheckoutCreated,
            'email' => 'seo-buyer@example.test',
            'first_name' => 'SEO',
            'last_name' => 'Buyer',
            'country' => 'HU',
            'product_type' => 'wordpress',
            'plan' => 'basic',
            'interval' => 'monthly',
            'market' => 'HU',
            'currency' => 'HUF',
            'amount' => 899000,
            'tax_treatment' => 'final_price_no_vat_added',
            'stripe_price_id' => 'price_seo_test',
            'stripe_checkout_session_id' => 'cs_test_seo',
        ]);

        $urls = [
            route('checkout.show', ['product' => 'wordpress', 'plan' => 'basic', 'interval' => 'monthly']),
            route('checkout.success'),
            route('checkout.cancel', $intent),
            route('login'),
            route('password.request'),
            route('password.reset', ['token' => 'opaque-reset-token', 'email' => 'seo-buyer@example.test']),
            route('account.activate', ['token' => 'opaque-activation-token', 'email' => 'seo-buyer@example.test']),
            route('owner.login'),
            route('operator.login'),
            route('staff.activate', ['token' => 'opaque-staff-token']),
        ];

        foreach ($urls as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
                ->assertDontSee('<script type="application/ld+json">', false);
        }
    }

    public function test_authenticated_customer_owner_and_operator_pages_remain_noindex_and_auth_protected(): void
    {
        $this->allowProductionIndexing();

        $this->get(route('account.dashboard'))->assertRedirect(route('login'));
        $this->get(route('owner.dashboard'))->assertRedirect(route('owner.login'));
        $this->get(route('operator.dashboard'))->assertRedirect(route('operator.login'));

        $customer = Customer::factory()->withUser()->create();
        $this->actingAs($customer->user, 'web')
            ->get(route('account.dashboard'))
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);

        $owner = User::factory()->owner()->create();
        $this->actingAs($owner, 'owner')
            ->get(route('owner.dashboard'))
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $operator = User::factory()->operator(Permission::cases())->create();
        $this->actingAs($operator, 'operator')
            ->get(route('operator.dashboard'))
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_robots_endpoint_keeps_noindex_pages_crawlable_and_hides_sitemap_when_indexing_is_disabled(): void
    {
        config(['seo.indexing_enabled' => false]);
        $robots = $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString('User-agent: *', $robots);
        $this->assertStringContainsString('Disallow:', $robots);
        $this->assertStringNotContainsString('Disallow: /checkout', $robots);
        $this->assertStringNotContainsString('Disallow: /login', $robots);
        $this->assertStringNotContainsString('Sitemap:', $robots);
    }

    private function allowProductionIndexing(): void
    {
        config([
            'app.env' => 'production',
            'app.url' => 'https://wpcaregrid.com',
            'seo.indexing_enabled' => true,
            'seo.production_host' => 'wpcaregrid.com',
            'http.force_https' => false,
        ]);
    }
}
