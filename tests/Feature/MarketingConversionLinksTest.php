<?php

namespace Tests\Feature;

use App\Content\ContentRepository;
use App\Content\Schema\ContentSchema;
use App\Models\ContentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MarketingConversionLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_hungarian_and_english_marketing_pages_use_the_approved_contact_email(): void
    {
        config(['marketing.links.contact' => 'mailto:contact@zsoltacsai.com']);

        foreach (['en', 'hu'] as $locale) {
            $home = $this->get('/'.$locale.'/')->assertOk()->getContent();
            $pricing = $this->get('/'.$locale.'/pricing')->assertOk()->getContent();

            $this->assertStringContainsString('mailto:contact@zsoltacsai.com', $home, $locale.' homepage contact');
            $this->assertStringNotContainsString('href="/contact"', $home, $locale.' homepage must not link to a missing contact route');
            $this->assertStringContainsString('mailto:contact@zsoltacsai.com', $pricing, $locale.' pricing contact');
        }
    }

    public function test_marketing_only_pricing_cards_render_contact_links_without_checkout_urls(): void
    {
        config([
            'marketing.links.contact' => 'mailto:contact@zsoltacsai.com',
            'marketing_release.profile' => 'marketing_only',
        ]);

        foreach (['en', 'hu'] as $locale) {
            $html = $this->get('/'.$locale.'/pricing')->assertOk()->getContent();
            $pricingMarkup = $this->pricingMarkup($html);
            $links = $this->marketingPlanLinks($pricingMarkup);

            $this->assertNotEmpty($links, $locale.' should render plan actions');
            $this->assertStringNotContainsString('/checkout', $pricingMarkup, $locale.' pricing markup must not contain a checkout URL');

            foreach ($links as $link) {
                $this->assertSame('mailto:contact@zsoltacsai.com', $link->getAttribute('href'));
                $this->assertFalse($link->hasAttribute('x-bind:href'), 'Marketing-only plan actions must not carry an Alpine checkout URL.');
                $this->assertStringContainsString(
                    $locale === 'en' ? 'Contact us about this plan' : 'Érdeklődj a csomagról',
                    trim($link->textContent),
                );
            }
        }
    }

    public function test_invalid_marketing_profile_fails_closed_to_contact_only_pricing_actions(): void
    {
        config([
            'marketing.links.contact' => 'mailto:contact@zsoltacsai.com',
            'marketing_release.profile' => 'unexpected-profile',
        ]);

        $html = $this->get('/en/pricing')->assertOk()->getContent();
        $pricingMarkup = $this->pricingMarkup($html);

        $this->assertNotEmpty($this->marketingPlanLinks($pricingMarkup));
        $this->assertStringNotContainsString('/checkout', $pricingMarkup);
        $this->assertStringContainsString('mailto:contact@zsoltacsai.com', $pricingMarkup);
    }

    public function test_invitation_beta_pricing_keeps_the_existing_server_resolved_checkout_selection(): void
    {
        config(['marketing_release.profile' => 'invitation_beta']);

        $html = $this->get('/en/pricing')->assertOk()->getContent();
        $pricingMarkup = $this->pricingMarkup($html);
        $links = $this->marketingPlanLinks($pricingMarkup);

        $this->assertNotEmpty($links);
        $this->assertStringContainsString('/checkout?', $pricingMarkup);

        foreach ($links as $link) {
            $this->assertStringContainsString('/checkout?', $link->getAttribute('href'));
            $this->assertStringContainsString('/checkout?', $link->getAttribute('x-bind:href'));
        }
    }

    public function test_legacy_published_contact_paths_are_replaced_with_email_without_rewriting_other_cms_links(): void
    {
        config(['marketing.links.contact' => 'mailto:contact@zsoltacsai.com']);

        foreach (['en', 'hu'] as $locale) {
            $faq = ContentSchema::section('home.faq')->builtIn($locale);
            $faq['contact_url'] = '/contact';
            ContentEntry::create(['key' => 'home.faq', 'locale' => $locale])
                ->forceFill(['published' => $faq, 'published_at' => now()])->save();

            $development = ContentSchema::section('home.development')->builtIn($locale);
            $development['cta_url'] = 'https://wpcaregrid.com/contact?source=legacy';
            ContentEntry::create(['key' => 'home.development', 'locale' => $locale])
                ->forceFill(['published' => $development, 'published_at' => now()])->save();

            $finalCta = ContentSchema::section('home.final_cta')->builtIn($locale);
            $finalCta['primary_url'] = 'https://example.test/marketing-contact-regression';
            $finalCta['secondary_url'] = '/contact/';
            ContentEntry::create(['key' => 'home.final_cta', 'locale' => $locale])
                ->forceFill(['published' => $finalCta, 'published_at' => now()])->save();
        }

        Cache::forget(ContentRepository::CACHE_KEY);
        app()->forgetInstance(ContentRepository::class);

        foreach (['en', 'hu'] as $locale) {
            $html = $this->get('/'.$locale.'/')->assertOk()->getContent();

            $this->assertGreaterThanOrEqual(4, substr_count($html, 'mailto:contact@zsoltacsai.com'), $locale.' approved contact links');
            $this->assertStringNotContainsString('href="/contact"', $html, $locale.' stale CMS contact route');
            $this->assertStringNotContainsString('wpcaregrid.com/contact', $html, $locale.' stale absolute CMS contact route');
            $this->assertStringContainsString('href="https://example.test/marketing-contact-regression"', $html, $locale.' unrelated CMS CTA must remain unchanged');
        }
    }

    public function test_localized_marketing_copy_qualifies_backup_security_and_service_scope(): void
    {
        $english = require lang_path('en/home.php');
        $hungarian = require lang_path('hu/home.php');
        $englishPricing = require lang_path('en/pricing.php');
        $hungarianPricing = require lang_path('hu/pricing.php');

        $this->assertStringContainsString('where the plan and site setup support them', $english['faq']['items']['backup_frequency']['answer']);
        $this->assertStringContainsString('ahol ezt a csomag és a webhely beállítása támogatja', $hungarian['faq']['items']['backup_frequency']['answer']);
        $this->assertStringContainsString('eligible Secure-plan cases and supported sites', $english['faq']['items']['infected']['answer']);
        $this->assertStringContainsString('jogosultsági és támogatott webhelyfeltételek mellett', $hungarian['faq']['items']['infected']['answer']);
        $this->assertStringNotContainsString('faster response', strtolower($englishPricing['plans']['wordpress']['secure']['description']));
        $this->assertStringNotContainsString('gyorsabb reagálás', strtolower($hungarianPricing['plans']['wordpress']['secure']['description']));
        $this->assertStringContainsString('where configured', $englishPricing['features']['remote_backups']['daily']);
        $this->assertStringContainsString('ahol be van állítva', $hungarianPricing['features']['remote_backups']['daily']);
    }

    /** @return list<\DOMElement> */
    private function marketingPlanLinks(string $html): array
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $nodes = (new \DOMXPath($document))->query('//*[@data-marketing-plan]');

        return $nodes === false ? [] : iterator_to_array($nodes);
    }

    private function pricingMarkup(string $html): string
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $nodes = (new \DOMXPath($document))->query('//*[@id="pricing"]');

        return $nodes === false || $nodes->length === 0
            ? ''
            : $document->saveHTML($nodes->item(0));
    }
}
