<?php

namespace Tests\Feature;

use App\Content\ContentRepository;
use App\Content\Schema\ContentSchema;
use App\Models\ContentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HowItWorksSectionTest extends TestCase
{
    use RefreshDatabase;

    private function howSection(string $locale): string
    {
        $html = $this->get("/{$locale}/")->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<section[^>]*id="how-it-works".*?<\/section>/s', $html, $match));

        return $match[0];
    }

    public function test_how_it_works_has_three_steps_based_on_contact_and_agreement(): void
    {
        $en = $this->howSection('en');
        $this->assertSame(3, substr_count($en, '<li class="border-t-2'));
        foreach (['Up and running in three steps.', 'Explore the plans', 'Talk it through', 'Ongoing maintenance', 'Where service is available'] as $text) {
            $this->assertStringContainsString($text, $en);
        }
        foreach (['Connect your website', 'Initial check', 'Connect and check your site'] as $removed) {
            $this->assertStringNotContainsString($removed, $en);
        }

        $hu = $this->howSection('hu');
        $this->assertSame(3, substr_count($hu, '<li class="border-t-2'));
        foreach (['Három lépésben indul a karbantartás.', 'Egyeztetés', 'Ahol a szolgáltatás elérhető'] as $text) {
            $this->assertStringContainsString($text, $hu);
        }
        $this->assertStringNotContainsString('Weboldal csatlakoztatása', $hu);
    }

    public function test_previously_published_step_rows_do_not_override_the_new_copy(): void
    {
        $published = ContentSchema::section('home.how')->builtIn('en');
        $published['steps'] = [
            'plan' => $published['steps']['plan'],
            'connect' => ['title' => 'Stale connect step', 'text' => 'Old published text.'],
            'audit' => ['title' => 'Stale audit step', 'text' => 'Old published text.'],
            'ongoing' => ['title' => 'Stale ongoing step', 'text' => 'Old published text.'],
        ];
        ContentEntry::create(['key' => 'home.how', 'locale' => 'en'])->forceFill(['published' => $published])->save();
        app(ContentRepository::class)->flush();

        $html = $this->howSection('en');
        $this->assertStringNotContainsString('Stale', $html);
        $this->assertStringContainsString('Talk it through', $html);
    }
}
