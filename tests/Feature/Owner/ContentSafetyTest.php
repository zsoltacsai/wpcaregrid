<?php

namespace Tests\Feature\Owner;

use App\Content\ContentRepository;
use App\Content\HtmlSanitizer;
use App\Content\SafeUrl;
use App\Content\Schema\ContentSchema;
use App\Models\ContentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

/**
 * Owner content cannot inject markup or scripts into public pages: text is
 * escaped, rich text is sanitised (when saved and when rendered), links
 * must be safe.
 */
class ContentSafetyTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    private function publish(string $key, array $changes)
    {
        $fields = array_replace_recursive(ContentSchema::section($key)->builtIn('en'), $changes);

        return $this->put(route('owner.content.update', [$key, 'en']), ['action' => 'publish', 'fields' => $fields]);
    }

    public function test_html_and_script_in_text_fields_are_shown_as_text(): void
    {
        $this->asOwner();
        $this->publish('home.hero', [
            'title' => '<script>alert(1)</script> Hello <b>bold</b>',
            'description' => '"><img src=x onerror=alert(2)>',
            'eyebrow' => "Line\u{0007}with control",
        ])->assertSessionHasNoErrors();

        $html = $this->get('/en/')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; Hello &lt;b&gt;bold&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<img src=x onerror', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;img src=x onerror=alert(2)&gt;', $html);
        $this->assertSame('Line with control', ContentEntry::sole()->published['eyebrow']);
    }

    public function test_attribute_injection_through_labels_and_seo_is_escaped(): void
    {
        $this->asOwner();
        $nav = ContentSchema::section('site.navigation')->builtIn('en');
        $nav['items'][0]['label'] = 'Services" onmouseover="alert(3)';
        $this->put(route('owner.content.update', ['site.navigation', 'en']), ['action' => 'publish', 'fields' => $nav])->assertSessionHasNoErrors();

        $seo = ContentSchema::section('seo.home')->builtIn('en');
        $seo['description'] = '" /><script>alert(4)</script><meta x="';
        $seo['og_title'] = "' onload='alert(5)";
        $this->put(route('owner.seo.update', ['seo.home', 'en']), ['action' => 'publish', 'fields' => $seo])->assertSessionHasNoErrors();

        $html = $this->get('/en/')->getContent();

        $this->assertStringNotContainsString('onmouseover="alert(3)"', $html);
        $this->assertStringContainsString('Services&quot; onmouseover=&quot;alert(3)', $html);
        $this->assertStringNotContainsString('<script>alert(4)</script>', $html);
        $this->assertStringContainsString('content="&quot; /&gt;&lt;script&gt;alert(4)&lt;/script&gt;&lt;meta x=&quot;"', $html);
        $this->assertStringNotContainsString("content='' onload='alert(5)", $html);
        $this->assertStringContainsString('&#039; onload=&#039;alert(5)', $html);
    }

    /** @return array<string, array{0: string}> */
    public static function unsafeUrls(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'javascript mixed case' => ['JaVaScRiPt:alert(1)'],
            'javascript with whitespace' => [' javascript:alert(1)'],
            'javascript with tab' => ["java\tscript:alert(1)"],
            'data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'vbscript' => ['vbscript:msgbox(1)'],
            'protocol relative' => ['//evil.example/x'],
            'backslash' => ['/\\evil.example'],
            'credentials' => ['https://user:pass@evil.example/'],
            'file' => ['file:///etc/passwd'],
            'bad anchor' => ['#"><script>'],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_link_targets_are_refused(string $url): void
    {
        $this->asOwner();

        $this->publish('home.hero', ['primary_cta_url' => $url])->assertSessionHasErrors('fields.primary_cta_url');
        $this->assertSame(0, ContentEntry::count());
        $this->assertFalse(SafeUrl::isSafe($url));
    }

    public function test_safe_link_targets_are_accepted(): void
    {
        foreach (['#pricing', '/pricing', '/#faq', 'https://wpcaregrid.example/contact?x=1', 'http://example.com', 'mailto:hello@wpcaregrid.example'] as $url) {
            $this->assertTrue(SafeUrl::isSafe($url), $url);
        }
    }

    public function test_an_unsafe_link_stored_by_other_means_is_not_rendered(): void
    {
        // Defence in depth: values are checked again when the page is built.
        $values = ContentSchema::section('home.hero')->builtIn('en');
        $values['primary_cta_url'] = 'javascript:alert(1)';
        $nav = ContentSchema::section('site.navigation')->builtIn('en');
        $nav['items'][0]['url'] = 'javascript:alert(2)';
        $faq = ContentSchema::section('home.faq')->builtIn('en');
        $faq['items'][0]['answer'] = '<p onclick="steal()">Hi</p><script>alert(5)</script><a href="javascript:alert(6)">x</a>';
        foreach (['home.hero' => $values, 'site.navigation' => $nav, 'home.faq' => $faq] as $key => $published) {
            $entry = ContentEntry::create(['key' => $key, 'locale' => 'en']);
            $entry->forceFill(['published' => $published])->save();
        }
        app(ContentRepository::class)->flush();

        $html = $this->get('/en/')->assertOk()->getContent();
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<script>alert(5)', $html);
        $this->assertStringNotContainsString('onclick="steal()"', $html);
        $this->assertStringContainsString('<p>Hi</p>', $html);
    }

    public function test_rich_text_keeps_simple_formatting_and_drops_everything_else(): void
    {
        $this->asOwner();
        $faq = ContentSchema::section('home.faq')->builtIn('en');
        $faq['items'] = [[
            'question' => 'Safe?',
            'answer' => '<p>Yes, <strong>really</strong> <a href="https://wpcaregrid.example/docs" onclick="steal()" style="color:red">docs</a>.</p>'
                .'<script>alert(1)</script><img src=x onerror=alert(2)><a href="javascript:alert(3)">bad link</a>'
                .'<iframe src="https://evil.example"></iframe><p onmouseover="x()">hover</p><!-- comment --><svg onload=alert(4)></svg>',
            'enabled' => '1',
            'position' => '1',
        ]];

        $this->put(route('owner.content.update', ['home.faq', 'en']), ['action' => 'publish', 'fields' => $faq])->assertSessionHasNoErrors();

        $stored = ContentEntry::sole()->published['items'][0]['answer'];
        $this->assertSame('<p>Yes, <strong>really</strong> <a href="https://wpcaregrid.example/docs" rel="nofollow noopener noreferrer">docs</a>.</p>bad link<p>hover</p>', $stored);

        $html = $this->get('/en/')->getContent();
        foreach (['<script>alert(1)', 'onerror', 'javascript:', '<iframe', 'onmouseover', 'onclick', 'onload=alert(4)', 'style="color:red"', '<!-- comment'] as $bad) {
            $this->assertStringNotContainsString($bad, $html, $bad);
        }
        $this->assertStringContainsString('<strong>really</strong>', $html);
    }

    public function test_rich_text_that_sanitises_to_nothing_cannot_be_published(): void
    {
        $this->asOwner();
        $faq = ContentSchema::section('home.faq')->builtIn('en');
        $faq['items'] = [['question' => 'Q?', 'answer' => '<script>only a script</script>', 'enabled' => '1', 'position' => '1']];

        $this->put(route('owner.content.update', ['home.faq', 'en']), ['action' => 'publish', 'fields' => $faq])->assertSessionHasErrors('fields.items.0.answer');
    }

    public function test_the_sanitiser_unwraps_unknown_tags_and_handles_broken_markup(): void
    {
        $this->assertSame('', HtmlSanitizer::clean(null));
        $this->assertSame('plain &amp; simple', HtmlSanitizer::clean('plain & simple'));
        $this->assertSame('<p>unclosed <em>text</em></p>', HtmlSanitizer::clean('<p>unclosed <em>text'));
        $this->assertSame('<ul><li>one</li><li>two</li></ul>', HtmlSanitizer::clean('<ul class="x"><li>one</li><li data-x="1">two</li></ul>'));
        $this->assertSame('inner text', HtmlSanitizer::clean('<div><span>inner</span> <font>text</font></div>'));
        $this->assertSame('', HtmlSanitizer::clean('<style>body{display:none}</style>'));
        $this->assertSame('<a href="mailto:hello@wpcaregrid.example" rel="nofollow noopener noreferrer">mail</a>', HtmlSanitizer::clean('<a href="mailto:hello@wpcaregrid.example" target="_blank">mail</a>'));
        $this->assertSame('Árvíztűrő tükörfúrógép', HtmlSanitizer::clean('Árvíztűrő tükörfúrógép'));
    }

    public function test_unknown_fields_are_never_stored(): void
    {
        $this->asOwner();
        $this->publish('home.hero', ['injected' => '<script>x</script>', 'title' => 'Fine'])->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey('injected', ContentEntry::sole()->published);
    }
}
