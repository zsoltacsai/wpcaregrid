<?php

namespace App\Content;

use App\Content\Schema\Field;
use App\Content\Schema\FieldType;
use App\Content\Schema\Section;
use App\Rules\SafeUrl;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validates and normalises a section's values from the editor. Unknown
 * fields are dropped; text loses control characters; rich text is
 * sanitised; links must be safe. A draft may be incomplete; publishing
 * requires every required field.
 */
final class ContentValidator
{
    /**
     * @param  array<string, mixed>  $input  The submitted "fields" array.
     * @return array<string, mixed> Complete, normalised values.
     *
     * @throws ValidationException
     */
    public static function validate(Section $section, array $input, bool $publishing): array
    {
        $input = self::withoutEmptyRows($section->fields, $input);
        $rules = [];
        $attributes = [];
        foreach ($section->fields as $field) {
            self::rules($field, 'fields.'.$field->name, $publishing, $rules, $attributes);
        }

        Validator::make(['fields' => $input], $rules, [], $attributes)->validate();

        $values = $section->complete(self::normalize($section->fields, $input));

        if ($publishing) {
            self::requireRichText($section->fields, $values, 'fields');
        }

        return $values;
    }

    /**
     * Repeater rows with no text at all are blank form rows, not items: they
     * are left out before validation instead of failing "required".
     *
     * @param  list<Field>  $fields
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function withoutEmptyRows(array $fields, array $input): array
    {
        foreach ($fields as $field) {
            if ($field->type !== FieldType::Repeater || ! is_array($input[$field->name] ?? null)) {
                continue;
            }

            $text = array_filter($field->fields, fn (Field $child) => in_array($child->type, [FieldType::Text, FieldType::Textarea, FieldType::RichText, FieldType::Url], true));
            $input[$field->name] = array_values(array_filter($input[$field->name], fn ($item) => ! is_array($item)
                || array_filter($text, fn (Field $child) => is_string($item[$child->name] ?? null) && trim(strip_tags($item[$child->name])) !== '') !== []));
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $attributes
     */
    private static function rules(Field $field, string $path, bool $publishing, array &$rules, array &$attributes): void
    {
        $presence = $field->required && $publishing ? 'required' : 'nullable';
        $attributes[$path] = self::label($field);

        $rules[$path] = match ($field->type) {
            FieldType::Text, FieldType::Textarea => [$presence, 'string', 'max:'.$field->max],
            FieldType::RichText => [$presence, 'string', 'max:'.($field->max * 2)],
            FieldType::Url => [$presence, 'string', 'max:2048', new SafeUrl($field->sameHost)],
            FieldType::Boolean => ['nullable', 'boolean'],
            FieldType::Number => [$presence, 'integer', 'min:'.$field->min, 'max:'.$field->max],
            FieldType::Select => [$presence, Rule::in($field->options)],
            FieldType::Repeater => ['nullable', 'array', 'max:'.$field->max],
            FieldType::Group => ['nullable', 'array'],
        };

        if ($field->type === FieldType::Repeater) {
            $rules[$path.'.*'] = ['array'];
            foreach ($field->fields as $child) {
                self::rules($child, $path.'.*.'.$child->name, $publishing, $rules, $attributes);
            }
        }

        if ($field->type === FieldType::Group) {
            foreach ($field->keys as $key) {
                $rules[$path.'.'.$key] = ['nullable', 'array'];
                foreach ($field->fields as $child) {
                    self::rules($child, $path.'.'.$key.'.'.$child->name, $publishing, $rules, $attributes);
                }
            }
        }
    }

    /**
     * @param  list<Field>  $fields
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function normalize(array $fields, array $input): array
    {
        $values = [];
        foreach ($fields as $field) {
            $values[$field->name] = self::value($field, $input[$field->name] ?? null);
        }

        return $values;
    }

    private static function value(Field $field, mixed $raw): mixed
    {
        return match ($field->type) {
            FieldType::Text => self::text($raw, false),
            FieldType::Textarea => self::text($raw, true),
            FieldType::RichText => HtmlSanitizer::clean(self::text($raw, true)),
            FieldType::Url => self::text($raw, false),
            FieldType::Boolean => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            FieldType::Number => is_numeric($raw) ? (int) $raw : $field->min,
            FieldType::Select => in_array($raw, $field->options, true) ? $raw : ($field->options[0] ?? null),
            FieldType::Repeater => self::repeater($field, is_array($raw) ? $raw : []),
            FieldType::Group => array_combine($field->keys, array_map(
                fn (string $key) => self::normalize($field->fields, is_array($raw[$key] ?? null) ? $raw[$key] : []),
                $field->keys,
            )),
        };
    }

    /** Plain text: no control characters; single-line fields have no line breaks. */
    private static function text(mixed $raw, bool $multiline): string
    {
        if (! is_string($raw)) {
            return '';
        }

        $text = str_replace(["\r\n", "\r"], "\n", $raw);
        $text = preg_replace($multiline ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]+/u', $multiline ? '' : ' ', $text) ?? '';

        return trim($multiline ? preg_replace("/\n{3,}/", "\n\n", $text) : preg_replace('/ {2,}/', ' ', $text));
    }

    /**
     * Items in the order of their position field (then as submitted),
     * positions renumbered 1…n, empty rows dropped.
     *
     * @param  array<array-key, mixed>  $raw
     * @return list<array<string, mixed>>
     */
    private static function repeater(Field $field, array $raw): array
    {
        $items = [];
        foreach (array_values($raw) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $values = self::normalize($field->fields, $item);
            $content = array_filter($field->fields, fn (Field $child) => in_array($child->type, [FieldType::Text, FieldType::Textarea, FieldType::RichText, FieldType::Url], true));
            if (array_filter($content, fn (Field $child) => trim(strip_tags((string) $values[$child->name])) !== '') === []) {
                continue;
            }

            $items[] = ['values' => $values, 'index' => $index];
        }

        if ($position = $field->positionField()) {
            usort($items, fn ($a, $b) => [$a['values'][$position->name], $a['index']] <=> [$b['values'][$position->name], $b['index']]);
        }

        return array_values(array_map(function (array $item, int $order) use ($field) {
            if ($position = $field->positionField()) {
                $item['values'][$position->name] = $order + 1;
            }

            return $item['values'];
        }, $items, array_keys($items)));
    }

    /**
     * Rich text that sanitises to nothing (e.g. only a script) is empty.
     *
     * @param  list<Field>  $fields
     * @param  array<string, mixed>  $values
     */
    private static function requireRichText(array $fields, array $values, string $path): void
    {
        foreach ($fields as $field) {
            $value = $values[$field->name] ?? null;

            if ($field->type === FieldType::RichText && $field->required && trim(strip_tags((string) $value)) === '') {
                throw ValidationException::withMessages([$path.'.'.$field->name => __('validation.required', ['attribute' => self::label($field)])]);
            }

            if ($field->type === FieldType::Repeater) {
                foreach ((array) $value as $index => $item) {
                    self::requireRichText($field->fields, $item, $path.'.'.$field->name.'.'.$index);
                }
            }
        }
    }

    public static function label(Field $field): string
    {
        $key = 'content.fields.'.$field->name;

        return __($key) !== $key ? __($key) : str_replace('_', ' ', $field->name);
    }
}
