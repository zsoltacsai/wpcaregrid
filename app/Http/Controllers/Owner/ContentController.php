<?php

namespace App\Http\Controllers\Owner;

use App\Content\ContentEditor;
use App\Content\ContentRepository;
use App\Content\ContentState;
use App\Content\DemoDashboard;
use App\Content\HomepageContent;
use App\Content\Schema\ContentSchema;
use App\Content\Schema\Section;
use App\Http\Controllers\Controller;
use App\Models\ContentEntry;
use App\Pricing\PricingCatalog;
use App\Pricing\PricingOverrides;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;

/**
 * Structured content editing: one section in one locale at a time, saved
 * as a draft or validated and published (App\Content\ContentEditor). SEO
 * sections are edited through the same screens under /owner/seo, with
 * their own ability. Drafts are visible only in a signed owner preview.
 */
class ContentController extends Controller
{
    public function index(ContentEditor $editor): View
    {
        return view('owner.content.index', [
            'groups' => collect(['homepage', 'site', 'pricing'])->mapWithKeys(fn (string $group) => [$group => $this->states(ContentSchema::group($group), $editor)]),
            'locales' => (array) config('content.locales'),
            'previews' => $this->previewLinks(),
        ]);
    }

    public function edit(Request $request, string $section, string $locale, ContentEditor $editor): View
    {
        [$definition, $area] = $this->resolve($request, $section, $locale);
        $entry = $editor->entry($definition, $locale);
        $fallback = ContentSchema::fallbackLocale();

        return view('owner.content.edit', [
            'section' => $definition,
            'locale' => $locale,
            'area' => $area,
            'entry' => $entry,
            'state' => ContentState::of($definition, $locale, $entry),
            'states' => collect(ContentSchema::locales())->mapWithKeys(fn (string $l) => [$l => ContentState::of($definition, $l, $l === $locale ? $entry : $editor->entry($definition, $l))]),
            'values' => $editor->editable($definition, $locale, $entry),
            // The other language's text beside each field, as a reference for translating (never copied in).
            'reference' => $locale !== $fallback ? app(ContentRepository::class)->section($definition->key, $fallback)->values : null,
            'locales' => (array) config('content.locales'),
            'preview' => $definition->group === 'seo' ? null : $this->previewLinks()[$locale] ?? null,
        ]);
    }

    public function update(Request $request, string $section, string $locale, ContentEditor $editor): RedirectResponse
    {
        [$definition, $area] = $this->resolve($request, $section, $locale);
        $input = is_array($request->input('fields')) ? $request->input('fields') : [];

        if ($request->input('action') === 'publish') {
            $editor->publish($definition, $locale, $input, $request->user());
            $message = __('owner.content.published');
        } else {
            $editor->saveDraft($definition, $locale, $input, $request->user());
            $message = __('owner.content.draft_saved');
        }

        return redirect()->route("owner.{$area}.edit", [$definition->key, $locale])->with('status', $message);
    }

    public function unpublish(Request $request, string $section, string $locale, ContentEditor $editor): RedirectResponse
    {
        [$definition, $area] = $this->resolve($request, $section, $locale);
        $editor->unpublish($definition, $locale, $request->user());

        return redirect()->route("owner.{$area}.edit", [$definition->key, $locale])->with('status', __('owner.content.unpublished'));
    }

    public function discard(Request $request, string $section, string $locale, ContentEditor $editor): RedirectResponse
    {
        [$definition, $area] = $this->resolve($request, $section, $locale);
        $editor->discardDraft($definition, $locale, $request->user());

        return redirect()->route("owner.{$area}.edit", [$definition->key, $locale])->with('status', __('owner.content.draft_discarded'));
    }

    /**
     * The homepage with this locale's drafts (published content where there
     * is no draft). Signed, short-lived, owner-only, never cached or indexed.
     */
    public function preview(string $locale, PricingOverrides $overrides): Response
    {
        abort_unless(in_array($locale, ContentSchema::locales(), true), 404);

        // A repository of its own for this render only: the shared one never holds drafts.
        $public = app(ContentRepository::class);
        $previousLocale = App::getLocale();
        $content = new ContentRepository;
        $content->preview($locale);
        $catalog = PricingCatalog::fromConfig($overrides->apply(config('pricing')), $locale, $content->planMarketing($locale));

        App::setLocale($locale);
        app()->instance(ContentRepository::class, $content);
        app()->instance(PricingCatalog::class, $catalog);

        try {
            $html = view('pages.home', [
                'content' => new HomepageContent($content),
                'demo' => new DemoDashboard,
                'pricing' => $catalog,
                'seo' => $content->section('seo.home', $locale),
                'preview' => true,
            ])->render();
        } finally {
            app()->instance(ContentRepository::class, $public);
            app()->forgetInstance(PricingCatalog::class);
            App::setLocale($previousLocale);
        }

        return response($html);
    }

    /**
     * The section, and whether this is the content or the SEO area; a
     * section of the other area, an unknown section or locale is a 404.
     *
     * @return array{0: Section, 1: string}
     */
    private function resolve(Request $request, string $section, string $locale): array
    {
        $area = str_starts_with((string) $request->route()?->getName(), 'owner.seo.') ? 'seo' : 'content';
        $definition = ContentSchema::section($section);

        abort_unless($definition !== null && in_array($locale, ContentSchema::locales(), true), 404);
        abort_unless(($definition->group === 'seo') === ($area === 'seo'), 404);

        return [$definition, $area];
    }

    /**
     * @param  array<string, Section>  $sections
     * @return array<string, array{section: Section, states: array<string, ContentState>}>
     */
    private function states(array $sections, ContentEditor $editor): array
    {
        $entries = ContentEntry::whereIn('key', array_keys($sections))->get()->keyBy(fn (ContentEntry $entry) => $entry->key.':'.$entry->locale);

        $rows = [];
        foreach ($sections as $key => $section) {
            $rows[$key] = [
                'section' => $section,
                'states' => collect(ContentSchema::locales())->mapWithKeys(fn (string $locale) => [$locale => ContentState::of($section, $locale, $entries[$key.':'.$locale] ?? null)])->all(),
            ];
        }

        return $rows;
    }

    /** @return array<string, string> Signed preview links per locale. */
    private function previewLinks(): array
    {
        $expires = now()->addMinutes(max(1, (int) config('content.preview_minutes')));

        return collect(ContentSchema::locales())->mapWithKeys(fn (string $locale) => [$locale => URL::temporarySignedRoute('owner.content.preview', $expires, ['locale' => $locale])])->all();
    }
}
