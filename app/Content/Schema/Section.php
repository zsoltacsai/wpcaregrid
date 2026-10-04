<?php

namespace App\Content\Schema;

use Closure;

/**
 * One editable section (e.g. home.hero): its structured fields and the
 * built-in copy it shows until the owner publishes something.
 */
final readonly class Section
{
    /**
     * @param  string  $group  homepage | site | pricing | seo
     * @param  list<Field>  $fields
     * @param  Closure(string): ?array<string, mixed>  $builtIn  Built-in copy for a locale, or null when the
     *                                                           translation files have none for it.
     */
    public function __construct(
        public string $key,
        public string $group,
        public array $fields,
        private Closure $builtIn,
    ) {}

    /** @return array<string, mixed>|null */
    public function builtIn(string $locale): ?array
    {
        $values = ($this->builtIn)($locale);

        return $values === null ? null : $this->complete($values);
    }

    public function field(string $name): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    /** An empty draft: what a new translation starts from (never copied from another language). */
    public function emptyValues(): array
    {
        return Field::emptyItem($this->fields);
    }

    /**
     * Every field present, so templates never meet a missing key (e.g. a
     * field added after the content was published). Missing fields are
     * empty, not taken from another language.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function complete(array $values): array
    {
        $complete = [];

        foreach ($this->fields as $field) {
            $value = $values[$field->name] ?? null;

            $complete[$field->name] = match ($field->type) {
                FieldType::Group => $this->completeGroup($field, is_array($value) ? $value : []),
                FieldType::Repeater => array_values(array_map(
                    fn ($item) => array_replace(Field::emptyItem($field->fields), is_array($item) ? $item : []),
                    is_array($value) ? $value : [],
                )),
                default => $value ?? $field->emptyValue(),
            };
        }

        return $complete;
    }

    /** @return array<string, array<string, mixed>> */
    private function completeGroup(Field $field, array $value): array
    {
        $items = [];
        foreach ($field->keys as $key) {
            $items[$key] = array_replace(Field::emptyItem($field->fields), is_array($value[$key] ?? null) ? $value[$key] : []);
        }

        return $items;
    }
}
