<?php

namespace Tests\Feature;

use App\Content\ContentRepository;
use App\Content\Schema\ContentSchema;
use App\Models\ContentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaqSectionTest extends TestCase
{
    use RefreshDatabase;

    private const ORDER = ['includes', 'why', 'woocommerce', 'start', 'downtime', 'failure', 'tracking', 'cancellation'];

    public function test_faq_has_the_eight_approved_questions_in_order_in_both_languages(): void
    {
        $hungarian = [
            'Mit tartalmaz a WordPress karbantartás?',
            'Miért fontos a rendszeres WordPress karbantartás?',
            'WooCommerce webáruházakat is karbantartotok?',
            'Hogyan indul az együttműködés?',
            'Elérhetetlenné válhat a weboldalam karbantartás közben?',
            'Mi történik, ha meghibásodik a weboldalam?',
            'Hogyan követhetem nyomon a karbantartást?',
            'Hogyan működik az előfizetés lemondása?',
        ];

        foreach (['en', 'hu'] as $locale) {
            $items = (require lang_path("{$locale}/home.php"))['faq']['items'];
            $this->assertSame(self::ORDER, array_keys($items), "FAQ order in {$locale}");
        }

        $this->assertSame($hungarian, array_column((require lang_path('hu/home.php'))['faq']['items'], 'question'));
    }

    public function test_faq_does_not_promise_unavailable_features_sla_uptime_or_refunds(): void
    {
        foreach (['en', 'hu'] as $locale) {
            $items = (require lang_path("{$locale}/home.php"))['faq']['items'];
            $text = mb_strtolower(implode(' ', array_column($items, 'answer')));

            foreach (['refund', 'visszatérítés', 'sla', '99.', 'immediately', 'azonnal', 'guaranteed uptime', 'garantált működés', '24/7', 'automatikusan csatlakoz'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $text, "FAQ ({$locale}) must not contain '{$forbidden}'.");
            }
        }

        $en = (require lang_path('en/home.php'))['faq']['items'];
        $hu = (require lang_path('hu/home.php'))['faq']['items'];
        $this->assertStringContainsString('not available at the moment', $en['start']['answer']);
        $this->assertStringContainsString('jelenleg nem érhető el', $hu['start']['answer']);
        $this->assertStringContainsString('not publicly available yet', $en['tracking']['answer']);
        $this->assertStringContainsString('jelenleg még nem nyilvánosan elérhető', $hu['tracking']['answer']);
        $this->assertStringContainsString('we do not guarantee availability', $en['downtime']['answer']);
        $this->assertStringContainsString('garantált elérhetőséget nem vállalunk', $hu['downtime']['answer']);
    }

    public function test_cta_below_the_faq_links_to_the_existing_contact_route_in_both_languages(): void
    {
        foreach (['en' => 'Have questions? Let&#039;s talk!', 'hu' => 'Kérdésed van? Beszéljük meg!'] as $locale => $label) {
            $html = $this->get("/{$locale}/")->assertOk()->getContent();
            $this->assertSame(1, preg_match('/<section[^>]*id="faq".*?<\/section>/s', $html, $section));

            $faq = $section[0];
            $this->assertStringContainsString($label, $faq);
            $this->assertLessThan(strpos($faq, $label), strrpos($faq, 'faq-8-panel'), 'The CTA comes after the last question.');
            $this->assertMatchesRegularExpression('/<a href="[^"]*(contact|mailto:)[^"]*"[^>]*>\s*'.preg_quote($label, '/').'/', $faq);
        }
    }

    public function test_faq_structured_data_stays_in_sync_with_the_visible_questions(): void
    {
        foreach (['en', 'hu'] as $locale) {
            $html = $this->get("/{$locale}/")->assertOk()->getContent();

            preg_match_all('/<h3>\s*<button[^>]*id="faq-\d+-button".*?<span>(.*?)<\/span>/s', $html, $visible);
            $this->assertCount(8, $visible[1]);

            $this->assertSame(1, substr_count($html, '"@type":"FAQPage"'), 'Exactly one FAQPage block.');
            $this->assertSame(1, preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $json));
            $faq = collect(json_decode($json[1], true)['@graph'])->firstWhere('@type', 'FAQPage');

            $this->assertSame(
                array_map(fn ($question) => html_entity_decode(trim($question), ENT_QUOTES | ENT_HTML5), $visible[1]),
                array_column($faq['mainEntity'], 'name'),
            );
        }
    }

    public function test_a_faq_published_before_the_cta_existed_still_shows_the_built_in_cta(): void
    {
        $published = ContentSchema::section('home.faq')->builtIn('en');
        unset($published['cta_label']);
        ContentEntry::create(['key' => 'home.faq', 'locale' => 'en'])
            ->forceFill(['published' => $published, 'published_at' => now()])->save();
        app(ContentRepository::class)->flush();

        $this->get('/en/')->assertOk()->assertSee('Have questions? Let&#039;s talk!', false);
    }
}
