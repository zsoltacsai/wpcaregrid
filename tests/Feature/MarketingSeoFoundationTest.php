<?php

namespace Tests\Feature;

use App\Content\ContentRepository;
use App\Content\Schema\ContentSchema;
use App\Marketing\MarketingClaimAuditor;
use App\Models\ContentEntry;
use App\Pricing\PricingCatalog;
use App\Seo\MarketingMetadataFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MarketingSeoFoundationTest extends TestCase
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
            'seo.social_image' => '',
        ]);
    }

    public function test_indexable_pages_have_unique_localized_metadata_and_safe_social_tags(): void
    {
        $pages = [
            '/en/' => ['en', 'https://wpcaregrid.com/en/'],
            '/hu/' => ['hu', 'https://wpcaregrid.com/hu/'],
            '/en/pricing' => ['en', 'https://wpcaregrid.com/en/pricing'],
            '/hu/pricing' => ['hu', 'https://wpcaregrid.com/hu/pricing'],
        ];
        $titles = [];

        foreach ($pages as $path => [$locale, $canonical]) {
            $html = $this->get($path)->assertOk()->getContent();
            preg_match('/<title>(.*?)<\/title>/s', $html, $titleMatch);
            $this->assertNotEmpty($titleMatch[1] ?? null, $path.' has a title');
            $titles[] = html_entity_decode($titleMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $this->assertMatchesRegularExpression('/<meta name="description" content="[^"]+">/', $html);
            $this->assertStringContainsString('<html lang="'.$locale.'">', $html);
            $this->assertStringContainsString('<link rel="canonical" href="'.$canonical.'">', $html);
            $this->assertStringContainsString('<meta property="og:url" content="'.$canonical.'">', $html);
            $this->assertStringContainsString('<meta property="og:locale" content="'.($locale === 'hu' ? 'hu_HU' : 'en_US').'">', $html);
            $this->assertStringContainsString('<meta property="og:locale:alternate" content="'.($locale === 'hu' ? 'en_US' : 'hu_HU').'">', $html);
            $this->assertStringContainsString('<meta name="twitter:card" content="summary">', $html);
            $this->assertStringNotContainsString('property="og:image"', $html);
            $this->assertStringNotContainsString('name="twitter:image"', $html);
            $this->assertStringContainsString('hreflang="'.$locale.'"', $html);
            $this->assertStringContainsString('hreflang="'.($locale === 'hu' ? 'en' : 'hu').'"', $html);
            $this->assertStringContainsString('hreflang="x-default"', $html);
            $this->assertMatchesRegularExpression('/<h1\b/i', $html);
        }

        $this->assertCount(4, array_unique($titles));
    }

    public function test_home_and_pricing_json_ld_are_valid_localized_and_limited_to_visible_semantics(): void
    {
        foreach (['/en/' => ['en', 4], '/hu/' => ['hu', 4], '/en/pricing' => ['en', 3], '/hu/pricing' => ['hu', 3]] as $path => [$locale, $expectedNodes]) {
            $html = $this->get($path)->assertOk()->getContent();
            preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $match);
            $this->assertNotEmpty($match[1] ?? null, $path.' has JSON-LD');
            $data = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('https://schema.org', $data['@context']);
            $this->assertCount($expectedNodes, $data['@graph']);

            $types = array_column($data['@graph'], '@type');
            $this->assertContains('Organization', $types);
            $this->assertSame('WP CareGrid', collect($data['@graph'])->firstWhere('@type', 'Organization')['name']);
            $this->assertContains('WebSite', $types);
            $this->assertContains('WebPage', $types);

            foreach ($data['@graph'] as $entity) {
                if ($entity['@type'] === 'WebPage') {
                    $this->assertSame($locale, $entity['inLanguage']);
                    $this->assertSame('https://wpcaregrid.com'.$path, $entity['url']);
                }
            }

            if (str_ends_with($path, '/')) {
                $this->assertContains('FAQPage', $types);
                $faq = collect($data['@graph'])->firstWhere('@type', 'FAQPage');
                $this->assertNotEmpty($faq['mainEntity']);
                $this->assertStringContainsString($faq['mainEntity'][0]['name'], $html);
                $this->assertStringNotContainsString('<script', json_encode($faq));
            } else {
                $this->assertNotContains('FAQPage', $types);
            }

            $this->assertNotContains('Product', $types);
            $this->assertNotContains('Offer', $types);
            $this->assertStringNotContainsString('"price"', json_encode($data));
            $this->assertStringNotContainsString('"aggregateRating"', json_encode($data));
        }
    }

    public function test_staging_is_noindex_and_never_emits_a_staging_sitemap(): void
    {
        config(['app.env' => 'staging', 'app.url' => 'https://staging.wpcaregrid.com']);

        $this->get('/en/')->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertSee('<link rel="canonical" href="https://staging.wpcaregrid.com/en/">', false);
        $this->get('/sitemap.xml')->assertNotFound();
    }

    public function test_preview_metadata_is_noindex_and_has_no_structured_data(): void
    {
        $metadata = app(MarketingMetadataFactory::class)->make(
            Request::create('/en/'),
            'Preview title',
            'Preview description',
            null,
            indexable: true,
            preview: true,
            structuredDataType: 'home',
            faqs: [['question' => 'Visible only in preview?', 'answer' => 'This is preview content.']],
        );

        $this->assertSame('noindex,nofollow', $metadata->robots);
        $this->assertSame([], $metadata->structuredData);
    }

    public function test_owner_faq_text_is_serialized_as_json_and_cannot_break_out_of_script_element(): void
    {
        $faq = ContentSchema::section('home.faq')->builtIn('en');
        $faq['items'][0]['answer'] = 'Literal marker </script><script>alert(1)</script> remains text only.';
        ContentEntry::create(['key' => 'home.faq', 'locale' => 'en'])
            ->forceFill(['published' => $faq, 'published_at' => now()])->save();
        Cache::forget(ContentRepository::CACHE_KEY);
        app()->forgetInstance(ContentRepository::class);

        $html = $this->get('/en/')->assertOk()->getContent();
        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $match);
        $this->assertNotEmpty($match[1] ?? null);
        $data = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('</script><script>', $match[1]);
        $this->assertSame(1, substr_count($html, '<script type="application/ld+json">'));
        $this->assertCount(1, array_filter($data['@graph'], fn (array $entity) => $entity['@type'] === 'FAQPage'));
    }

    public function test_marketed_plan_names_and_descriptions_exist_in_both_locales(): void
    {
        foreach (['wordpress', 'woocommerce'] as $product) {
            foreach (['basic', 'business', 'secure'] as $tier) {
                foreach (['en', 'hu'] as $locale) {
                    $plan = trans('pricing.plans.'.$product.'.'.$tier, [], $locale);
                    $this->assertNotEmpty($plan['name'] ?? null, "$locale $product $tier name");
                    $this->assertNotEmpty($plan['description'] ?? null, "$locale $product $tier description");
                    if ($product === 'woocommerce') {
                        $this->assertStringNotContainsString('WordPress sites', $plan['description'], "$locale WooCommerce description is distinct");
                        $this->assertStringNotContainsString('WordPress-oldalak', $plan['description'], "$locale WooCommerce description is distinct");
                    }
                }
            }
        }
    }

    public function test_schema_never_duplicates_prices_and_catalogue_remains_checkout_source(): void
    {
        $catalogue = app(PricingCatalog::class);
        $this->assertNotEmpty($catalogue->plans());

        foreach (['/en/pricing', '/hu/pricing'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $match);
            $data = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('"price"', json_encode($data));
            $this->assertStringNotContainsString('"offers"', json_encode($data));
        }
    }

    public function test_claim_auditor_reports_review_candidates_without_changing_published_content(): void
    {
        $seo = ContentSchema::section('seo.home')->builtIn('en');
        $seo['description'] = 'GDPR compliant and guaranteed recovery.';
        $entry = new ContentEntry(['key' => 'seo.home', 'locale' => 'en']);
        $entry->forceFill(['published' => $seo, 'published_at' => now()])->save();
        Cache::forget(ContentRepository::CACHE_KEY);
        app()->forgetInstance(ContentRepository::class);

        $before = $entry->fresh()->published;
        $findings = app(MarketingClaimAuditor::class)->findings();

        $this->assertContains('compliance_guarantee', array_column($findings, 'rule'));
        $this->assertContains('recovery_guarantee', array_column($findings, 'rule'));
        $this->assertSame($before, $entry->fresh()->published);
        $this->assertArrayNotHasKey('text', $findings[0] ?? []);
        $this->artisan('marketing:claims-audit')->assertSuccessful();
    }
}
