<?php

namespace App\Content\Schema;

/** One structured field of a content section. */
final readonly class Field
{
    /**
     * @param  list<string>  $options  Select: allowed values.
     * @param  list<Field>  $fields  Repeater and group: the fields of each item.
     * @param  list<string>  $keys  Group: the fixed item keys, in page order.
     */
    public function __construct(
        public string $name,
        public FieldType $type,
        public bool $required = false,
        public int $max = 255,
        public array $options = [],
        public array $fields = [],
        public array $keys = [],
        public int $min = 0,
        public bool $sameHost = false,
    ) {}

    public static function text(string $name, bool $required = true, int $max = 160): self
    {
        return new self($name, FieldType::Text, $required, $max);
    }

    public static function textarea(string $name, bool $required = true, int $max = 600): self
    {
        return new self($name, FieldType::Textarea, $required, $max);
    }

    public static function richText(string $name, bool $required = true, int $max = 2000): self
    {
        return new self($name, FieldType::RichText, $required, $max);
    }

    /** $sameHost: only links on this application's own host (canonical URLs). */
    public static function url(string $name, bool $required = true, bool $sameHost = false): self
    {
        return new self($name, FieldType::Url, $required, 2048, sameHost: $sameHost);
    }

    public static function boolean(string $name): self
    {
        return new self($name, FieldType::Boolean, false);
    }

    public static function number(string $name, int $min = 1, int $max = 999): self
    {
        return new self($name, FieldType::Number, true, $max, min: $min);
    }

    /** @param  list<string>  $options */
    public static function select(string $name, array $options): self
    {
        return new self($name, FieldType::Select, true, options: $options);
    }

    /** @param  list<Field>  $fields */
    public static function repeater(string $name, array $fields, int $maxItems = 20): self
    {
        return new self($name, FieldType::Repeater, false, $maxItems, fields: $fields);
    }

    /**
     * @param  list<string>  $keys
     * @param  list<Field>  $fields
     */
    public static function group(string $name, array $keys, array $fields): self
    {
        return new self($name, FieldType::Group, true, fields: $fields, keys: $keys);
    }

    /** Repeater items are ordered by this field when present. */
    public function positionField(): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->name === 'position' && $field->type === FieldType::Number) {
                return $field;
            }
        }

        return null;
    }

    /** An empty value of this field, for a new draft. */
    public function emptyValue(): mixed
    {
        return match ($this->type) {
            FieldType::Boolean => true,
            FieldType::Number => $this->min,
            FieldType::Select => $this->options[0] ?? null,
            FieldType::Repeater => [],
            FieldType::Group => array_fill_keys($this->keys, self::emptyItem($this->fields)),
            default => '',
        };
    }

    /**
     * @param  list<Field>  $fields
     * @return array<string, mixed>
     */
    public static function emptyItem(array $fields): array
    {
        $item = [];
        foreach ($fields as $field) {
            $item[$field->name] = $field->emptyValue();
        }

        return $item;
    }
}
