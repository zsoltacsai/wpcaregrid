<?php

namespace Tests\Feature\Owner;

use App\Content\ContentRepository;
use App\Content\ContentState;
use App\Content\Schema\ContentSchema;
use App\Models\AdminAuditEvent;
use App\Models\ContentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

/** Structured content: drafts, publishing, translations, fallback, preview, FAQ, navigation and SEO. */
class ContentManagementTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    /** The section's current editable values with some fields replaced. */
    private function fields(string $key, string $locale, array $changes = []): array
    {
        $section = ContentSchema::section($key);
        $values = $section->builtIn($locale) ?? $section->emptyValues();

        return array_replace_recursive($values, $changes);
    }

    /**
     * A language the owner can write in but that has no built-in copy: the
     * deliberately incomplete fixture for "missing translation" behaviour
     * (English and Hungarian are both complete, see HungarianContentTest).
     */
    private function withGerman(): void
    {
        config([
            'content.locales' => ['en' => 'English', 'hu' => 'Magyar', 'de' => 'Deutsch'],
            // The public site only renders supported display languages (config/localization.php).
            'localization.locales' => ['hu' => 'Magyar', 'en' => 'English', 'de' => 'Deutsch'],
        ]);
    }

    private function save(string $key, string $locale, array $fields, string $action = 'draft', string $area = 'content')
    {
        return $this->put(route("owner.{$area}.update", [$key, $locale]), ['action' => $action, 'fields' => $fields]);
    }

    public function test_the_homepage_shows_built_in_copy_until_the_owner_publishes(): void
    {
        $this->get('/en/')->assertOk()->assertSee('WordPress maintenance, without the hassle.');

        $this->asOwner();
        $this->get(route('owner.content.index'))->assertOk()->assertSee('Hero')->assertSee('Built-in text')->assertDontSee('Missing');
        $this->get(route('owner.content.edit', ['home.hero', 'en']))->assertOk()->assertSee('WordPress maintenance, without the hassle.');
    }

    public function test_a_draft_never_reaches_the_public_page_and_publishing_shows_it_at_once(): void
    {
        $owner = $this->asOwner();
        $this->get('/en/')->assertSee('WordPress maintenance, without the hassle.'); // warms the published-content cache

        $this->save('home.hero', 'en', $this->fields('home.hero', 'en', ['title' => 'Draft headline for review']))->assertRedirect(route('owner.content.edit', ['home.hero', 'en']));
        $this->get('/en/')->assertSee('WordPress maintenance, without the hassle.')->assertDontSee('Draft headline for review');
        $this->assertSame(ContentState::DRAFT, ContentState::of(ContentSchema::section('home.hero'), 'en', ContentEntry::sole())->state);

        $this->save('home.hero', 'en', $this->fields('home.hero', 'en', ['title' => 'Published headline']), 'publish')->assertSessionHasNoErrors();
        $this->get('/en/')->assertSee('Published headline')->assertDontSee('WordPress maintenance, without the hassle.');

        // A later draft leaves the published version in place.
        $this->save('home.hero', 'en', $this->fields('home.hero', 'en', ['title' => 'Next idea']));
        $this->get('/en/')->assertSee('Published headline')->assertDontSee('Next idea');
        $this->assertSame(ContentState::CHANGED, ContentState::of(ContentSchema::section('home.hero'), 'en', ContentEntry::sole())->state);

        // Unpublishing returns the page to the built-in copy; the text is kept as a draft.
        $this->post(route('owner.content.unpublish', ['home.hero', 'en']))->assertRedirect();
        $this->get('/en/')->assertSee('WordPress maintenance, without the hassle.')->assertDontSee('Published headline');
        $this->assertSame('Next idea', ContentEntry::sole()->draft['title']);

        $events = AdminAuditEvent::where('subject_type', 'content')->orderBy('id')->get();
        $this->assertSame(['content.created', 'content.published', 'content.updated', 'content.unpublished'], $events->pluck('event')->all());
        $this->assertTrue($events->every(fn ($e) => $e->actor_user_id === $owner->id && $e->subject_key === 'home.hero:en' && $e->created_at !== null));
    }

    public function test_publishing_requires_every_required_field_but_a_draft_may_be_incomplete(): void
    {
        $this->asOwner();
        $fields = $this->fields('home.hero', 'en', ['title' => '', 'primary_cta_url' => '']);

        $this->save('home.hero', 'en', $fields, 'publish')->assertSessionHasErrors(['fields.title', 'fields.primary_cta_url']);
        $this->assertSame(0, ContentEntry::count());
        $this->assertSame(0, AdminAuditEvent::count());

        $this->save('home.hero', 'en', $fields)->assertSessionHasNoErrors();
        $this->assertSame('', ContentEntry::sole()->draft['title']);
        $this->get('/en/')->assertSee('WordPress maintenance, without the hassle.');
    }

    public function test_translations_are_separate_records_and_missing_ones_are_visible(): void
    {
        $this->asOwner();
        $this->withGerman();
        $hero = ContentSchema::section('home.hero');

        // No German copy exists: shown as missing, with English as a reference only.
        $this->assertSame(ContentState::MISSING, ContentState::of($hero, 'de', null)->state);
        $this->get(route('owner.content.edit', ['home.hero', 'de']))->assertOk()
            ->assertSee('No Deutsch content yet')
            ->assertSee('<span class="font-mono uppercase">en:</span>', false)
            ->assertDontSee('value="WordPress maintenance, without the hassle."', false);
        $this->get(route('owner.content.index'))->assertOk()->assertSee('Missing');

        $this->save('home.hero', 'de', $this->fields('home.hero', 'de', [
            'eyebrow' => 'WordPress gewartet.', 'title' => 'WordPress-Wartung ohne Aufwand.', 'description' => 'Updates, Backups und Prüfungen.',
            'primary_cta_label' => 'Tarife', 'primary_cta_url' => '#pricing', 'secondary_cta_label' => 'So funktioniert es', 'secondary_cta_url' => '#how-it-works',
            'trust' => ['contract' => ['label' => 'Keine Vertragsbindung', 'enabled' => '1'], 'connection' => ['label' => 'Sichere Verbindung', 'enabled' => '1'], 'business' => ['label' => 'Für Unternehmen', 'enabled' => '1']],
        ]), 'publish')->assertSessionHasNoErrors();

        $this->assertSame(['en' => null, 'hu' => null, 'de' => 'WordPress-Wartung ohne Aufwand.'], [
            'en' => ContentEntry::where('locale', 'en')->first()?->published['title'],
            'hu' => ContentEntry::where('locale', 'hu')->first()?->published['title'],
            'de' => ContentEntry::where('locale', 'de')->sole()->published['title'],
        ]);
        $this->assertSame(ContentState::PUBLISHED, ContentState::of($hero, 'de', ContentEntry::where('locale', 'de')->sole())->state);
        // English and Hungarian are untouched.
        $this->assertSame(ContentState::BUILT_IN, ContentState::of($hero, 'hu', null)->state);
        // English is untouched.
        $this->get('/en/')->assertSee('WordPress maintenance, without the hassle.');
    }

    public function test_public_fallback_is_whole_sections_in_english_never_mixed_fields(): void
    {
        $this->asOwner();
        $this->withGerman();
        $this->save('home.hero', 'de', $this->fields('home.hero', 'de', [
            'eyebrow' => 'Slogan', 'title' => 'Deutsche Überschrift', 'description' => 'Deutsche Beschreibung.',
            'primary_cta_label' => 'Tarife', 'primary_cta_url' => '#pricing', 'secondary_cta_label' => 'Ablauf', 'secondary_cta_url' => '#how-it-works',
            'trust' => ['contract' => ['label' => 'Keine Bindung'], 'connection' => ['label' => 'Verbindung'], 'business' => ['label' => 'Für Firmen']],
        ]), 'publish')->assertSessionHasNoErrors();

        app()->setLocale('de');
        $content = app(ContentRepository::class);
        $content->flush();

        $hero = $content->section('home.hero');
        $this->assertSame(['de', null, 'Deutsche Überschrift'], [$hero->locale, $hero->fallbackLang(), $hero['title']]);

        $faq = $content->section('home.faq');
        $this->assertSame(['en', 'en'], [$faq->locale, $faq->fallbackLang()], 'No German FAQ: the whole section is English, and marked so.');

        config(['app.locale' => 'de', 'localization.default' => 'de']);
        $html = $this->get('/en/')->assertOk()->assertHeader('Content-Language', 'en')->getContent();
        $this->assertStringContainsString('WordPress maintenance, without the hassle.', $html);
        $this->assertStringNotContainsString('Deutsche Überschrift', $html);
    }

    public function test_a_field_missing_from_published_content_stays_empty_and_is_not_taken_from_another_language(): void
    {
        // E.g. content published before a field was added to the schema.
        $values = ContentSchema::section('home.hero')->emptyValues();
        $values['title'] = 'Magyar főcím';
        unset($values['eyebrow'], $values['description']);
        ContentEntry::create(['key' => 'home.hero', 'locale' => 'hu'])->forceFill(['published' => $values])->save();

        $hero = app(ContentRepository::class)->section('home.hero', 'hu');

        $this->assertSame(['hu', 'Magyar főcím', '', ''], [$hero->locale, $hero['title'], $hero['eyebrow'], $hero['description']]);
    }

    public function test_faq_items_keep_their_order_and_hidden_items_are_not_shown(): void
    {
        $this->asOwner();
        $fields = $this->fields('home.faq', 'en');
        $fields['items'] = [
            ['question' => 'Third question?', 'answer' => 'Three.', 'enabled' => '1', 'position' => '3'],
            ['question' => 'First question?', 'answer' => 'One.', 'enabled' => '1', 'position' => '1'],
            ['question' => 'Hidden question?', 'answer' => 'Hidden.', 'enabled' => '0', 'position' => '2'],
            ['question' => '', 'answer' => '', 'enabled' => '1', 'position' => '4'], // empty row: dropped
        ];

        $this->save('home.faq', 'en', $fields, 'publish')->assertSessionHasNoErrors();

        $stored = ContentEntry::sole()->published['items'];
        $this->assertSame(['First question?', 'Hidden question?', 'Third question?'], array_column($stored, 'question'));
        $this->assertSame([1, 2, 3], array_column($stored, 'position'));

        $this->get('/en/')->assertSeeInOrder(['First question?', 'Third question?'])->assertDontSee('Hidden question?');
    }

    public function test_navigation_is_editable_with_order_and_visibility(): void
    {
        $this->asOwner();
        $fields = $this->fields('site.navigation', 'en', ['login_label' => 'Sign in', 'cta_label' => 'Start now']);
        $fields['items'] = [
            ['label' => 'Plans', 'url' => '/pricing', 'enabled' => '1', 'position' => '2'],
            ['label' => 'Questions', 'url' => '#faq', 'enabled' => '1', 'position' => '1'],
            ['label' => 'Blog', 'url' => 'https://blog.wpcaregrid.example/', 'enabled' => '0', 'position' => '3'],
        ];

        $this->save('site.navigation', 'en', $fields, 'publish')->assertSessionHasNoErrors();

        $this->get('/en/')->assertSeeInOrder(['href="/en/#faq"', 'Questions', 'href="/en/pricing"', 'Plans'], false)
            ->assertSee('Sign in')->assertSee('Start now')->assertDontSee('blog.wpcaregrid.example');
        // The same navigation on other public pages.
        $this->get(route('pricing'))->assertSee('Questions');
    }

    public function test_seo_metadata_is_managed_per_page_and_canonical_urls_stay_on_this_site(): void
    {
        $this->asOwner();
        config(['app.url' => 'https://wpcaregrid.example']);
        URL::forceRootUrl('https://wpcaregrid.example');
        $fields = ['title' => 'WP CareGrid – Managed WordPress', 'description' => 'Maintenance with checks.', 'canonical_url' => 'https://wpcaregrid.example/en/', 'robots' => 'noindex,follow', 'og_title' => 'Share title', 'og_description' => ''];

        // Another site as canonical, or an unknown robots value: refused.
        $this->save('seo.home', 'en', ['canonical_url' => 'https://other-site.example/'] + $fields, 'publish', 'seo')->assertSessionHasErrors('fields.canonical_url');
        $this->save('seo.home', 'en', ['robots' => 'noarchive'] + $fields, 'publish', 'seo')->assertSessionHasErrors('fields.robots');

        $this->save('seo.home', 'en', $fields, 'publish', 'seo')->assertRedirect(route('owner.seo.edit', ['seo.home', 'en']));

        $this->get('/en/')
            ->assertSee('<title>WP CareGrid – Managed WordPress</title>', false)
            ->assertSee('<meta name="description" content="Maintenance with checks.">', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertSee('<link rel="canonical" href="https://wpcaregrid.example/en/">', false)
            ->assertSee('<meta property="og:title" content="Share title">', false)
            ->assertSee('<meta property="og:description" content="Maintenance with checks.">', false);

        // Only that page: the pricing page keeps its own defaults.
        $this->get(route('pricing'))
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertDontSee('Managed WordPress');
        $this->get(route('owner.seo.index'))->assertOk()->assertSee('noindex,follow');
    }

    public function test_drafts_are_previewed_only_through_a_signed_link_by_a_signed_in_owner(): void
    {
        $this->asOwner();
        $this->save('home.hero', 'en', $this->fields('home.hero', 'en', ['title' => 'Preview-only headline']));
        $link = URL::temporarySignedRoute('owner.content.preview', now()->addMinutes(15), ['locale' => 'en']);

        $this->get($link)->assertOk()->assertSee('Preview-only headline')->assertSee(__('owner.content.preview_banner'))
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);

        // Unsigned, tampered or expired: refused.
        $this->get(route('owner.content.preview', 'en'))->assertForbidden();
        $this->get(str_replace('/preview/en', '/preview/hu', $link))->assertForbidden();
        $this->get(URL::temporarySignedRoute('owner.content.preview', now()->subMinute(), ['locale' => 'en']))->assertForbidden();

        // A valid link is useless without an owner session.
        auth('owner')->logout();
        $this->get($link)->assertRedirect(route('owner.login'));
        $this->get('/en/')->assertDontSee('Preview-only headline');
    }

    public function test_plan_wording_comes_from_published_pricing_content(): void
    {
        $this->asOwner();
        $fields = $this->fields('pricing.wordpress.business', 'en', ['name' => 'Professional', 'badge' => 'Recommended', 'cta_label' => 'Start Professional']);

        $this->save('pricing.wordpress.business', 'en', $fields)->assertSessionHasNoErrors();
        $this->get('/en/')->assertDontSee('Professional');

        $this->save('pricing.wordpress.business', 'en', $fields, 'publish')->assertSessionHasNoErrors();
        $this->get('/en/')->assertSee('Professional')->assertSee('Recommended')->assertSee('Start Professional')->assertSee('Everything in Professional');
        // Same wording wherever the plan is named.
        $site = $this->customerWithWebsite();
        $this->assertSame('Professional', $site['subscription']->planName());
    }

    public function test_only_configured_locales_can_be_written(): void
    {
        $this->asOwner();
        $this->put(route('owner.content.update', ['home.hero', 'de']), ['action' => 'publish', 'fields' => ['title' => 'Hallo']])->assertNotFound();
        $this->assertSame(0, ContentEntry::count());

        config(['content.locales' => ['en' => 'English', 'hu' => 'Magyar', 'de' => 'Deutsch']]);
        $this->get(route('owner.content.edit', ['home.hero', 'de']))->assertOk()->assertSee('Deutsch');
    }
}
