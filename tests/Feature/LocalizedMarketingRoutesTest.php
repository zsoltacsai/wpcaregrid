<?php

namespace Tests\Feature;

use App\Content\ContentRepository;
use App\Content\ContentValues;
use App\Content\Schema\ContentSchema;
use App\Models\ContentEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LocalizedMarketingRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://wpcaregrid.com',
            'app.env' => 'production',
            'http.force_https' => false,
            'seo.indexing_enabled' => true,
            'seo.production_host' => 'wpcaregrid.com',
        ]);
    }

    public function test_all_supported_marketing_routes_render_their_url_locale_and_seo_metadata(): void
    {
        $cases = [
            ['/en/', 'en', 'WordPress maintenance, without the hassle.', 'https://wpcaregrid.com/en/'],
            ['/hu/', 'hu', 'WordPress-karbantartás, gond nélkül.', 'https://wpcaregrid.com/hu/'],
            ['/en/pricing', 'en', 'Plans and pricing', 'https://wpcaregrid.com/en/pricing'],
            ['/hu/pricing', 'hu', 'Csomagok és árak', 'https://wpcaregrid.com/hu/pricing'],
        ];

        foreach ($cases as [$path, $locale, $copy, $canonical]) {
            $response = $this->withCookie('caregrid_locale', $locale === 'en' ? 'hu' : 'en')->get($path)->assertOk();
            $response->assertHeader('Content-Language', $locale)
                ->assertSee('<html lang="'.$locale.'">', false)
                ->assertSee($copy)
                ->assertSee('<link rel="canonical" href="'.$canonical.'">', false)
                ->assertSee('<link rel="alternate" hreflang="en"', false)
                ->assertSee('<link rel="alternate" hreflang="hu"', false)
                ->assertSee('<link rel="alternate" hreflang="x-default"', false);
        }
    }

    public function test_url_locale_overrides_customer_and_cookie_without_mutating_saved_preference(): void
    {
        $user = User::factory()->create(['locale' => 'hu']);

        $this->actingAs($user, 'web')
            ->withCookie('caregrid_locale', 'hu')
            ->get('/en/')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertSee('<html lang="en">', false);

        $this->assertSame('hu', $user->fresh()->locale);

        $this->withCookie('caregrid_locale', 'en')
            ->get('/hu/pricing')
            ->assertOk()
            ->assertHeader('Content-Language', 'hu')
            ->assertSee('<html lang="hu">', false);
    }

    public function test_request_locale_is_restored_after_response_for_persistent_processes(): void
    {
        $original = app()->getLocale();

        $this->get('/hu/')->assertOk()->assertHeader('Content-Language', 'hu');

        $this->assertSame($original, app()->getLocale());
    }

    public function test_marketing_switcher_uses_equivalent_urls_without_locale_post(): void
    {
        $englishHome = $this->get('/en/')->assertOk()->getContent();
        $this->assertStringContainsString('href="/hu/"', $englishHome);
        $this->assertStringNotContainsString('action="https://wpcaregrid.com/locale"', $englishHome);

        $hungarianPricing = $this->get('/hu/pricing')->assertOk()->getContent();
        $this->assertStringContainsString('href="/en/pricing"', $hungarianPricing);
        $this->assertStringNotContainsString('action="https://wpcaregrid.com/locale"', $hungarianPricing);
    }

    public function test_pricing_controls_stack_at_narrow_widths_to_keep_localized_rows_stable(): void
    {
        $html = $this->get('/en/pricing')->assertOk()->getContent();

        $this->assertStringContainsString(
            'flex flex-col items-start gap-3 md:flex-row md:flex-wrap md:items-center',
            $html,
        );
        $this->assertStringContainsString('role="group" aria-label="Billing period"', $html);
        $this->assertStringContainsString('data-price="monthly"', $html);
        $this->assertStringContainsString('data-price="annual"', $html);
    }

    public function test_legacy_get_urls_redirect_permanently_to_configured_origin_and_keep_only_safe_query_data(): void
    {
        $this->get('https://attacker.example/?utm_source=campaign&token=secret&session_id=cs_test_secret')
            ->assertStatus(301)
            ->assertRedirect('https://wpcaregrid.com/en/?utm_source=campaign');

        $this->get('https://attacker.example/pricing?product=wordpress&plan=business&interval=annual&email=private%40example.test')
            ->assertStatus(301)
            ->assertRedirect('https://wpcaregrid.com/en/pricing?product=wordpress&plan=business&interval=annual')
            ->assertDontSee('private@example.test');

        $this->post('/pricing', ['token' => 'not-redirected'])->assertMethodNotAllowed();
        $this->get('/fr/')->assertNotFound();
    }

    public function test_owner_canonical_override_must_match_the_exact_localized_url(): void
    {
        $seo = ContentSchema::section('seo.home')->builtIn('hu');
        $seo['canonical_url'] = 'https://wpcaregrid.com/en/';
        ContentEntry::create(['key' => 'seo.home', 'locale' => 'hu'])
            ->forceFill(['published' => $seo, 'published_at' => now()])->save();
        Cache::forget(ContentRepository::CACHE_KEY);
        app()->forgetInstance(ContentRepository::class);

        $this->get('/hu/')->assertOk()
            ->assertSee('<link rel="canonical" href="https://wpcaregrid.com/hu/">', false)
            ->assertDontSee('<link rel="canonical" href="https://wpcaregrid.com/en/">', false);
    }

    public function test_page_with_fallback_language_content_omits_hreflang_in_rendered_html(): void
    {
        $repository = new class extends ContentRepository
        {
            public function section(string $key, ?string $locale = null): ContentValues
            {
                if ($key === 'home.hero' && ($locale ?? app()->getLocale()) === 'hu') {
                    $english = parent::section($key, 'en');

                    return new ContentValues($english->values, 'en', 'hu', ContentValues::BUILT_IN);
                }

                return parent::section($key, $locale);
            }
        };
        $this->app->instance(ContentRepository::class, $repository);

        $this->get('/hu/')->assertOk()
            ->assertSee('<section', false)
            ->assertDontSee('hreflang=', false)
            ->assertSee('lang="en"', false);
    }

    public function test_request_host_cannot_control_canonical_or_enable_indexing(): void
    {
        $html = $this->get('https://attacker.example/hu/?campaign=one')->assertOk()
            ->assertSee('<link rel="canonical" href="https://wpcaregrid.com/hu/">', false)
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertDontSee('campaign=one', false)
            ->getContent();

        preg_match_all('/<link rel="(?:canonical|alternate)"[^>]+>/', $html, $metadataUrls);
        $this->assertNotEmpty($metadataUrls[0]);
        foreach ($metadataUrls[0] as $metadataUrl) {
            $this->assertDoesNotMatchRegularExpression('/https?:\/\/attacker\.example/i', $metadataUrl);
        }
    }

    public function test_sitemap_is_valid_gated_xml_with_only_canonical_marketing_urls_and_reciprocal_alternates(): void
    {
        $response = $this->get('https://wpcaregrid.com/sitemap.xml')->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertHeader('Cache-Control', 'no-store, private');

        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $this->assertCount(4, $xml->url);
        $locations = [];
        foreach ($xml->url as $url) {
            $locations[] = (string) $url->loc;
        }
        $this->assertSame([
            'https://wpcaregrid.com/en/', 'https://wpcaregrid.com/hu/',
            'https://wpcaregrid.com/en/pricing', 'https://wpcaregrid.com/hu/pricing',
        ], $locations);
        $this->assertStringNotContainsString('/checkout', $response->getContent());
        $this->assertStringNotContainsString('/kalkulator', $response->getContent());
        $this->assertStringNotContainsString('staging.', $response->getContent());
        $this->assertSame(12, substr_count($response->getContent(), 'xhtml:link'));
    }

    public function test_sitemap_and_robots_fail_closed_outside_the_approved_production_host(): void
    {
        config(['app.env' => 'staging', 'app.url' => 'https://staging.wpcaregrid.com']);
        $this->get('https://staging.wpcaregrid.com/sitemap.xml')->assertNotFound();
        $this->get('https://staging.wpcaregrid.com/robots.txt')->assertOk()->assertDontSee('Sitemap:');

        config(['app.env' => 'production', 'app.url' => 'https://wpcaregrid.com']);
        $this->get('https://attacker.example/robots.txt')->assertOk()->assertDontSee('Sitemap:');
        $this->get('https://wpcaregrid.com/robots.txt')->assertOk()
            ->assertSee("User-agent: *\nDisallow:", false)
            ->assertSee('Sitemap: https://wpcaregrid.com/sitemap.xml');
        $this->assertFileDoesNotExist(public_path('robots.txt'));
    }

    public function test_checkout_and_authentication_routes_remain_unprefixed_and_calculator_route_is_absent(): void
    {
        $this->get(route('checkout.show', ['product' => 'wordpress', 'plan' => 'basic', 'interval' => 'monthly']))
            ->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false);
        $this->get(route('login'))->assertOk();
        $this->get(route('account.dashboard'))->assertRedirect(route('login'));
        $this->assertFalse(Route::has('floor-calculator'));
        $this->assertSame('checkout', Route::getRoutes()->getByName('checkout.show')->uri());
        $this->assertSame('login', Route::getRoutes()->getByName('login')->uri());
    }
}
