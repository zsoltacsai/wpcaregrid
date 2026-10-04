<?php

namespace App\Content;

use ArrayAccess;
use LogicException;

/**
 * A resolved section for a page: its values, the language they are in and
 * where they came from. Values are plain text (escaped by Blade on output)
 * except rich text fields, which are rendered through HtmlSanitizer.
 *
 * @implements ArrayAccess<string, mixed>
 */
final readonly class ContentValues implements ArrayAccess
{
    public const PUBLISHED = 'published';

    public const DRAFT = 'draft';

    public const BUILT_IN = 'built_in';

    /** @param  array<string, mixed>  $values */
    public function __construct(
        public array $values,
        public string $locale,
        public string $requestedLocale,
        public string $source,
    ) {}

    /** The section's language when it differs from the page's (a fallback), for a lang attribute. */
    public function fallbackLang(): ?string
    {
        return $this->locale !== $this->requestedLocale ? $this->locale : null;
    }

    public function isFallback(): bool
    {
        return $this->locale !== $this->requestedLocale;
    }

    /** A link field, safe to put in an href (checked again at render time). */
    public function url(string $field, string $fallback = '#'): string
    {
        return SafeUrl::orFallback($this->values[$field] ?? null, $fallback);
    }

    /**
     * Enabled items of a list field, in order; group items keep their keys.
     *
     * @return array<array-key, array<string, mixed>>
     */
    public function items(string $field): array
    {
        return array_filter(
            (array) ($this->values[$field] ?? []),
            fn ($item) => is_array($item) && ($item['enabled'] ?? true) !== false,
        );
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->values[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->values[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Content values are read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Content values are read-only.');
    }
}
