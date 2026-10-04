<?php

namespace App\Content\Schema;

/** The kinds of content field. There is no free HTML field. */
enum FieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    // Limited, sanitised HTML (paragraphs, emphasis, lists, safe links). Only where it is needed.
    case RichText = 'rich_text';
    case Url = 'url';
    case Boolean = 'boolean';
    case Number = 'number';
    case Select = 'select';
    // A list the owner can add to, remove from and reorder (e.g. FAQ, navigation).
    case Repeater = 'repeater';
    // A fixed set of cards tied to the page structure (icons), editable but not added to.
    case Group = 'group';

    public function isList(): bool
    {
        return $this === self::Repeater || $this === self::Group;
    }
}
