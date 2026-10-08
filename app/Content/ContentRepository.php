<?php

namespace App\Content;

use App\Content\Schema\ContentSchema;
use App\Models\ContentEntry;
use App\Pricing\PricingCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * What public pages show. Published content is read once per request from
 * the cache (cleared on every publish and unpublish, so the next request
 * sees it); drafts are only ever read for a signed owner preview.
 *
 * Fallback policy, one whole section at a time (docs/CONTENT_MANAGEMENT.md):
 *
 *   1. published content in the page's locale
 *   2. built-in copy in the page's locale (lang/{locale}), if there is one
 *   3. the same two steps in the fallback locale (English)
 *
 * A section is never assembled from fields of different languages; a
 * section shown in the fallback language says so (fallbackLang()).
 */
class ContentRepository
{
    public const CACHE_KEY = 'content.published';

    /** @var array<string, array<string, array<string, mixed>>>|null [key][locale] => values */
    private ?array $published = null;

    private ?string $previewLocale = null;

    /** @var array<string, array<string, mixed>> [key] => draft values in the preview locale */
    private array $drafts = [];

    public function section(string $key, ?string $locale = null): ContentValues
    {
        $section = ContentSchema::section($key) ?? throw new InvalidArgumentException("Unknown content section [{$key}].");
        $locale ??= app()->getLocale();

        if ($this->previewLocale === $locale && isset($this->drafts[$key])) {
            return new ContentValues($section->complete($this->drafts[$key]), $locale, $locale, ContentValues::DRAFT);
        }

        foreach (array_unique([$locale, ContentSchema::fallbackLocale()]) as $candidate) {
            if (is_array($values = $this->published()[$key][$candidate] ?? null)) {
                return new ContentValues($section->complete($values), $candidate, $locale, ContentValues::PUBLISHED);
            }

            if (is_array($values = $section->builtIn($candidate))) {
                return new ContentValues($values, $candidate, $locale, ContentValues::BUILT_IN);
            }
        }

        return new ContentValues($section->emptyValues(), $locale, $locale, ContentValues::BUILT_IN);
    }

    /**
     * Owner-written plan wording for the pricing catalogue: only plans with
     * published content (or a draft in a preview); others keep the
     * catalogue's own wording.
     *
     * @return array<string, ContentValues> Keyed "{product}.{tier}".
     */
    public function planMarketing(?string $locale = null): array
    {
        $plans = [];

        foreach (ContentSchema::group('pricing') as $key => $section) {
            $values = $this->section($key, $locale);
            if ($values->source !== ContentValues::BUILT_IN) {
                $plans[substr($key, strlen('pricing.'))] = $values;
            }
        }

        return $plans;
    }

    /** Show drafts in this locale instead of published content (signed owner preview only). */
    public function preview(string $locale): void
    {
        $this->previewLocale = $locale;
        $this->drafts = ContentEntry::where('locale', $locale)->whereNotNull('draft')->pluck('draft', 'key')->all();
    }

    /** Published content changed: the next read loads it again, and so does the catalogue (plan wording). */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->published = null;
        app()->forgetInstance(PricingCatalog::class);
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    private function published(): array
    {
        return $this->published ??= $this->load();
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    private function load(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, function () {
                $published = [];
                foreach (ContentEntry::whereNotNull('published')->get(['key', 'locale', 'published']) as $entry) {
                    $published[$entry->key][$entry->locale] = $entry->published;
                }

                return $published;
            });
        } catch (QueryException $e) {
            // Public pages keep working on the built-in copy if the content tables cannot be read.
            Log::warning('Published content could not be read; built-in copy is shown.', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
