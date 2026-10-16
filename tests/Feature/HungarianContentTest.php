<?php

namespace Tests\Feature;

use App\Content\ContentRepository;
use App\Content\ContentState;
use App\Content\Schema\ContentSchema;
use App\Models\ContentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

/**
 * The public site is complete in English and Hungarian: every content
 * section has built-in copy in both languages, Hungarian pages never fall
 * back to English, and the owner area reports nothing missing. Section
 * fallback itself is tested with a deliberately incomplete language
 * (ContentManagementTest, "de").
 */
class HungarianContentTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    /** Translation files whose keys exist in full in both languages. */
    private const COMPLETE_FILES = ['home', 'pricing', 'checkout', 'dashboard', 'status', 'countries', 'account', 'backups', 'admin_access', 'security', 'site_health', 'connector'];

    /**
     * Text that is the same in both languages on purpose: product and brand
     * names, plan names, the demo domain and technical identifiers.
     */
    private const SAME_IN_BOTH = [
        'WordPress', 'WooCommerce', 'Basic', 'Business', 'Secure', 'shop.example.com',
        '© :year WP CareGrid', ':currency / :unit', ':name', 'Belgium', 'Liechtenstein', 'Moldova',
        'index,follow', ':page – WP CareGrid', '—', 'PHP', 'VIES',
    ];

    private function useHungarian(): void
    {
        config(['app.locale' => 'hu', 'localization.default' => 'hu']);
        app()->setLocale('hu');
        app(ContentRepository::class)->flush();
    }

    /** @return array<string, string> dotted key => text, strings only */
    private function strings(array $values): array
    {
        return array_filter(Arr::dot($values), 'is_string');
    }

    public function test_every_content_section_has_english_and_hungarian_copy(): void
    {
        $this->assertCount(23, ContentSchema::sections(), 'The inventory: 13 homepage, 2 site, 6 plan wording and 2 SEO sections.');

        foreach (ContentSchema::sections() as $key => $section) {
            foreach (['en', 'hu'] as $locale) {
                $state = ContentState::of($section, $locale, null);

                $this->assertSame(ContentState::BUILT_IN, $state->state, "{$key} in {$locale}");
                $this->assertFalse($state->isMissingTranslation(), "{$key} in {$locale}");
                $this->assertSame($locale, $state->publicLocale(), "{$key} in {$locale}");
            }
        }
    }

    public function test_hungarian_translation_files_have_exactly_the_english_keys(): void
    {
        foreach (self::COMPLETE_FILES as $file) {
            $this->assertEqualsCanonicalizing(
                array_keys(Arr::dot(trans($file, [], 'en'))),
                array_keys(Arr::dot(trans($file, [], 'hu'))),
                "lang/hu/{$file}.php must have the same keys as lang/en/{$file}.php",
            );
        }
    }

    public function test_framework_messages_exist_in_hungarian_with_the_same_keys_and_placeholders(): void
    {
        // Validation, authentication, password reset and pagination texts the customer sees.
        foreach (['validation', 'auth', 'passwords', 'pagination'] as $file) {
            $english = Arr::dot(trans($file, [], 'en'));
            $hungarian = Arr::dot(trans($file, [], 'hu'));
            unset($english['attributes'], $hungarian['attributes']);

            foreach ($english as $key => $text) {
                if (! is_string($text) || str_starts_with($key, 'custom.')) {
                    continue;
                }
                $this->assertArrayHasKey($key, $hungarian, "lang/hu/{$file}.php is missing {$key}");
                $this->assertNotSame($text, $hungarian[$key], "{$file}.{$key} is not translated");
                preg_match_all('/:[a-z_]+/', $text, $placeholders);
                foreach ($placeholders[0] as $placeholder) {
                    $this->assertStringContainsString($placeholder, (string) $hungarian[$key], "{$file}.{$key} lost {$placeholder}");
                }
            }
        }

        // The default error pages (404, 403, 419…) use the English phrase as the key.
        foreach (['Unauthorized', 'Forbidden', 'Not Found', 'Page Expired', 'Too Many Requests', 'Server Error', 'Service Unavailable'] as $phrase) {
            $this->assertNotSame($phrase, __($phrase, [], 'hu'), "{$phrase} is not translated (lang/hu.json)");
        }
    }

    public function test_hungarian_text_is_written_not_copied_and_keeps_every_placeholder(): void
    {
        $pairs = [];
        foreach (self::COMPLETE_FILES as $file) {
            foreach ($this->strings(trans($file, [], 'en')) as $key => $english) {
                $pairs["{$file}.{$key}"] = [$english, $this->strings(trans($file, [], 'hu'))[$key] ?? ''];
            }
        }
        foreach (ContentSchema::sections() as $key => $section) {
            $hungarian = $this->strings($section->builtIn('hu'));
            foreach ($this->strings($section->builtIn('en')) as $field => $english) {
                $pairs["{$key}.{$field}"] = [$english, $hungarian[$field] ?? ''];
            }
        }

        $problems = [];
        foreach ($pairs as $where => [$english, $hungarian]) {
            if ($english === '' || str_starts_with($english, '#') || str_starts_with($english, '/') || preg_match('/^(true|false)$/', $english)) {
                continue;
            }

            if (trim($hungarian) === '') {
                $problems[] = "{$where} is empty in Hungarian";

                continue;
            }

            $sameOnPurpose = in_array($english, self::SAME_IN_BOTH, true);
            if (($english === $hungarian) !== $sameOnPurpose) {
                $problems[] = "{$where}: ".($english === $hungarian ? 'identical to the English' : 'expected to be identical (brand/identifier)')." ({$english})";
            }

            preg_match_all('/:[a-z_]+/', $english, $placeholders);
            foreach ($placeholders[0] as $placeholder) {
                if (! str_contains($hungarian, $placeholder)) {
                    $problems[] = "{$where} lost {$placeholder}";
                }
            }
        }

        $this->assertSame([], $problems);
    }

    public function test_hungarian_pages_render_without_falling_back_to_english(): void
    {
        $this->useHungarian();
        $content = app(ContentRepository::class);

        foreach (array_keys(ContentSchema::sections()) as $key) {
            $section = $content->section($key);
            $this->assertSame(['hu', null], [$section->locale, $section->fallbackLang()], "{$key} is served in Hungarian");
        }

        $home = $this->get('/hu/')->assertOk()->getContent();
        $this->assertStringContainsString('<html lang="hu">', $home);
        // The English button of the language selector is rightly lang="en"; no content section is.
        $this->assertDoesNotMatchRegularExpression('/<section[^>]*lang="en"/', $home, 'No section is marked as English fallback.');

        foreach ([
            'Karbantartott WordPress. Működő weboldal.', 'WordPress-karbantartás, gond nélkül.', 'Mit tartalmaz a karbantartás',
            'Nemcsak frissítünk. Ellenőrzünk is.', 'Csomagok és árak', 'Minden weboldala egy helyen.', 'A lejárt licencek nem maradnak észrevétlenek.',
            'Webáruházaknál többet ellenőrzünk.', 'Többre van szüksége a karbantartásnál?', 'Gyakran ismételt kérdések', 'Meg kell adnom a WordPress-jelszavamat?',
            'Tartsa karbantartva a WordPress-oldalát.', 'Szerződési feltételek', 'Belépés', 'Rendben', 'Tevékenységnapló',
        ] as $text) {
            $this->assertStringContainsString($text, $home);
        }
        foreach ([
            'WordPress maintenance, without the hassle.', 'What maintenance includes', 'We don&#039;t just update', 'Frequently asked questions',
            'All your websites in one place', 'Keep your WordPress maintained', 'Cookie Policy', 'Healthy', 'Activity log',
        ] as $text) {
            $this->assertStringNotContainsString($text, $home, "English text left on the Hungarian homepage: {$text}");
        }

        $pricing = $this->get('/hu/pricing')->assertOk()->getContent();
        $this->assertStringContainsString('Éves', $pricing);
        $this->assertStringContainsString('Basic', $pricing);
        $this->assertStringNotContainsString('Monthly', $pricing);
        $this->assertStringNotContainsString('Choose', $pricing);
        $this->assertStringContainsString('<title>WordPress- és WooCommerce-csomagok, árak | WP CareGrid</title>', $pricing);

        $checkout = $this->get('/checkout?product=wordpress&plan=business&interval=monthly')->assertOk()->getContent();
        $this->assertStringContainsString('Rendelés véglegesítése', $checkout);
        $this->assertStringNotContainsString('Complete your order', $checkout);
    }

    public function test_hungarian_prices_are_the_configured_hungarian_prices(): void
    {
        $this->useHungarian();
        $this->withSession(['display_currency' => 'HUF']);
        $pricing = $this->get('/hu/pricing')->assertOk()->getContent();
        $visible = html_entity_decode(strip_tags($pricing), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $visible = preg_replace('/[\p{Z}\s]+/u', ' ', $visible);

        // Amounts come from config/pricing.php and are formatted for Hungarian readers, never as minor units.
        foreach (['8 990', '18 990', '28 990', '14 900', '24 900', '39 900', '89 900', '189 900', '289 900', '149 000', '249 000', '399 000'] as $amount) {
            $this->assertStringContainsString($amount, $visible, "{$amount} Ft");
        }
        $this->assertStringContainsString('Ft', $visible);
        $this->assertDoesNotMatchRegularExpression('/\b(899000|1899000|2899000)\b/', $visible);
        $this->assertStringContainsString('hó', $visible);
        $this->assertStringContainsString('2 hónap ingyenes', $visible, 'Annual = ten monthly payments, two months free.');
        $this->assertStringContainsString('áfát nem számítunk fel', $visible);
    }

    public function test_english_rendering_is_unchanged(): void
    {
        $home = $this->get('/en/')->assertOk()->getContent();

        $this->assertStringContainsString('<html lang="en">', $home);
        foreach (['WordPress maintenance, without the hassle.', 'What maintenance includes', 'Frequently asked questions', 'Keep your WordPress maintained.', 'Cookie Policy'] as $text) {
            $this->assertStringContainsString($text, $home);
        }
        $this->assertStringNotContainsString('Gyakran ismételt kérdések', $home);
        $this->assertSame('WordPress maintained. Website working.', trans('home.tagline', [], 'en'));

        $this->withSession(['display_currency' => 'EUR']);
        $pricing = $this->get('/en/pricing')->assertOk()->getContent();
        $this->assertStringContainsString('Plans and pricing', $pricing);
        $this->assertStringContainsString('€89', $pricing);
    }

    public function test_hungarian_drafts_stay_private_and_published_content_wins_over_the_built_in_copy(): void
    {
        $this->useHungarian();
        $draft = ContentSchema::section('home.hero')->builtIn('hu');
        $draft['title'] = 'Piszkozat főcím, nem nyilvános';
        ContentEntry::create(['key' => 'home.hero', 'locale' => 'hu'])->forceFill(['draft' => $draft])->save();

        $this->get('/hu/')->assertOk()
            ->assertDontSee('Piszkozat főcím, nem nyilvános')
            ->assertSee('WordPress-karbantartás, gond nélkül.');

        // Publishing replaces the built-in Hungarian copy for Hungarian pages only.
        $draft['title'] = 'Közzétett főcím';
        ContentEntry::where('key', 'home.hero')->where('locale', 'hu')->sole()->forceFill(['published' => $draft, 'draft' => null])->save();
        app(ContentRepository::class)->flush();
        $this->get('/hu/')->assertSee('Közzétett főcím');

        config(['app.locale' => 'en', 'localization.default' => 'en']);
        app()->setLocale('en');
        app(ContentRepository::class)->flush();
        $this->get('/en/')->assertSee('WordPress maintenance, without the hassle.')->assertDontSee('Közzétett főcím');
    }

    public function test_the_owner_area_reports_no_missing_hungarian_content(): void
    {
        $this->asOwner();

        foreach (['owner.content.index', 'owner.seo.index', 'owner.pricing.index'] as $route) {
            $this->get(route($route))->assertOk()->assertDontSee('Missing')->assertSee('Built-in text');
        }

        $this->get(route('owner.content.edit', ['home.faq', 'hu']))->assertOk()
            ->assertDontSee('No Magyar content yet')
            ->assertSee('Meg kell adnom a WordPress-jelszavamat?');
    }

    public function test_the_owner_area_still_reports_a_translation_that_is_really_missing(): void
    {
        $this->asOwner();
        config(['content.locales' => ['en' => 'English', 'hu' => 'Magyar', 'de' => 'Deutsch']]);

        $this->get(route('owner.content.index'))->assertOk()->assertSee('Missing');
        $this->get(route('owner.content.edit', ['home.hero', 'de']))->assertOk()->assertSee('No Deutsch content yet');
        $this->get(route('owner.content.edit', ['home.hero', 'hu']))->assertOk()->assertDontSee('No Magyar content yet');
    }
}
