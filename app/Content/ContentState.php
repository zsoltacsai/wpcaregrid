<?php

namespace App\Content;

use App\Content\Schema\ContentSchema;
use App\Content\Schema\Section;
use App\Models\ContentEntry;

/**
 * Where a section stands in one locale, for the editor: whether something
 * is published, whether a draft is waiting, and what the public page shows
 * meanwhile (this locale, or the fallback language).
 */
final readonly class ContentState
{
    public const PUBLISHED = 'published';

    public const CHANGED = 'changed';

    public const DRAFT = 'draft';

    public const BUILT_IN = 'built_in';

    public const MISSING = 'missing';

    private function __construct(
        public string $state,
        public string $locale,
        public bool $hasDraft,
        public bool $hasPublished,
        public bool $hasBuiltIn,
    ) {}

    public static function of(Section $section, string $locale, ?ContentEntry $entry): self
    {
        $draft = $entry?->draft !== null;
        $published = $entry?->published !== null;
        $builtIn = $section->builtIn($locale) !== null;

        $state = match (true) {
            $published && $draft => self::CHANGED,
            $published => self::PUBLISHED,
            $draft => self::DRAFT,
            $builtIn => self::BUILT_IN,
            default => self::MISSING,
        };

        return new self($state, $locale, $draft, $published, $builtIn);
    }

    /** No content of its own in this language on the public page: the fallback language is shown. */
    public function isMissingTranslation(): bool
    {
        return ! $this->hasPublished && ! $this->hasBuiltIn;
    }

    /** The language the public page shows this section in. */
    public function publicLocale(): string
    {
        return $this->isMissingTranslation() ? ContentSchema::fallbackLocale() : $this->locale;
    }

    public function tone(): string
    {
        return match ($this->state) {
            self::PUBLISHED, self::BUILT_IN => 'positive',
            self::CHANGED, self::DRAFT => 'warning',
            self::MISSING => 'danger',
        };
    }

    public function label(): string
    {
        return __('owner.content.states.'.$this->state);
    }
}
