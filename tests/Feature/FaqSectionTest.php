<?php

namespace Tests\Feature;

use Tests\TestCase;

class FaqSectionTest extends TestCase
{
    public function test_faq_follows_the_proposed_order_in_both_languages(): void
    {
        $order = ['password', 'availability', 'broken_update', 'infected', 'backup_frequency', 'backup_location', 'license', 'custom_plugins'];

        foreach (['en', 'hu'] as $locale) {
            $items = (require lang_path("{$locale}/home.php"))['faq']['items'];
            $this->assertSame($order, array_keys($items), "FAQ order in {$locale}");
        }
    }

    public function test_faq_does_not_present_self_service_connecting_or_ask_for_passwords(): void
    {
        foreach (['en', 'hu'] as $locale) {
            $items = (require lang_path("{$locale}/home.php"))['faq']['items'];
            $this->assertStringNotContainsString('You connect your website', $items['password']['answer']);
            $this->assertStringNotContainsString('Ön csatlakoztatja', $items['password']['answer']);
        }
        $en = (require lang_path('en/home.php'))['faq']['items'];
        $this->assertStringContainsString('never ask for your WordPress administrator, hosting or database password', $en['password']['answer']);
        $this->assertStringContainsString('before anything starts', $en['availability']['answer']);
    }

    public function test_visible_questions_match_the_faq_structured_data(): void
    {
        foreach (['en', 'hu'] as $locale) {
            $html = $this->get("/{$locale}/")->assertOk()->getContent();

            preg_match_all('/<h3>\s*<button[^>]*id="faq-\d+-button".*?<span>(.*?)<\/span>/s', $html, $visible);
            $this->assertCount(8, $visible[1]);

            $this->assertSame(1, preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $json));
            $faq = collect(json_decode($json[1], true)['@graph'])->firstWhere('@type', 'FAQPage');
            $schemaQuestions = array_column($faq['mainEntity'], 'name');

            $this->assertSame(array_map(fn ($q) => html_entity_decode(trim($q), ENT_QUOTES | ENT_HTML5), $visible[1]), $schemaQuestions);
        }
    }
}
