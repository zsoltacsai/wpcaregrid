<?php

namespace App\Http\Controllers\Owner;

use App\Content\ContentRepository;
use App\Content\ContentState;
use App\Content\Schema\ContentSchema;
use App\Http\Controllers\Controller;
use App\Models\ContentEntry;
use Illuminate\Contracts\View\View;

/**
 * Search metadata for the public marketing pages (home, pricing): title,
 * description, canonical URL, robots and Open Graph text, per locale.
 * Only these pages' own tags; no robots.txt, headers or server settings.
 */
class SeoController extends Controller
{
    public function __invoke(ContentRepository $content): View
    {
        $sections = ContentSchema::group('seo');
        $entries = ContentEntry::whereIn('key', array_keys($sections))->get()->keyBy(fn (ContentEntry $entry) => $entry->key.':'.$entry->locale);

        $pages = [];
        foreach ($sections as $key => $section) {
            foreach (ContentSchema::locales() as $locale) {
                $pages[$key][$locale] = [
                    'state' => ContentState::of($section, $locale, $entries[$key.':'.$locale] ?? null),
                    'values' => $content->section($key, $locale),
                ];
            }
        }

        return view('owner.seo.index', ['pages' => $pages, 'locales' => (array) config('content.locales')]);
    }
}
