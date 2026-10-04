{{--
    One scalar content field (text, textarea, rich text, URL, boolean,
    number, select) with its validation error and, when translating, the
    fallback language's text as a reference (never copied into the field).
--}}
@use('App\Content\Schema\FieldType')
@use('App\Content\ContentValidator')
@props(['field', 'name', 'path', 'value' => null, 'reference' => null, 'referenceLocale' => null])

@php
    $id = 'f-'.str_replace(['.', '_'], '-', $path);
    $current = old($path, $value);
    $error = $errors->first($path);
    $label = ContentValidator::label($field);
    $input = 'mt-1 block w-full rounded-sm border bg-surface px-3 text-sm text-ink '.($error ? 'border-danger' : 'border-line-strong hover:border-muted');
    $hint = match ($field->type) {
        FieldType::Url => $field->sameHost ? __('owner.content.hints.canonical') : __('owner.content.hints.url'),
        FieldType::RichText => __('owner.content.hints.rich_text'),
        default => null,
    };
@endphp

<div {{ $attributes->only('class') }}>
    @if ($field->type === FieldType::Boolean)
        <input type="hidden" name="{{ $name }}" value="0">
        <label for="{{ $id }}" class="flex items-center gap-2 text-sm text-primary">
            <input id="{{ $id }}" type="checkbox" name="{{ $name }}" value="1" @checked(filter_var($current, FILTER_VALIDATE_BOOLEAN)) class="size-4">
            {{ $label }}
        </label>
    @else
        <label for="{{ $id }}" class="block text-sm font-medium text-primary">
            {{ $label }}
            @unless ($field->required)<span class="font-normal text-muted">({{ __('owner.optional') }})</span>@endunless
        </label>
        @switch($field->type)
            @case(FieldType::Textarea)
            @case(FieldType::RichText)
                <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $field->type === FieldType::RichText ? 5 : 3 }}" maxlength="{{ $field->type === FieldType::RichText ? $field->max * 2 : $field->max }}" @if ($error) aria-invalid="true" @endif class="{{ $input }} py-2 {{ $field->type === FieldType::RichText ? 'font-mono text-xs' : '' }}">{{ $current }}</textarea>
                @break
            @case(FieldType::Number)
                <input id="{{ $id }}" type="number" name="{{ $name }}" value="{{ $current }}" min="{{ $field->min }}" max="{{ $field->max }}" class="{{ $input }} h-9 w-32">
                @break
            @case(FieldType::Select)
                <select id="{{ $id }}" name="{{ $name }}" class="{{ $input }} h-9">
                    @foreach ($field->options as $option)
                        <option value="{{ $option }}" @selected($current === $option)>{{ __('content.options.'.$option) !== 'content.options.'.$option ? __('content.options.'.$option) : $option }}</option>
                    @endforeach
                </select>
                @break
            @default
                <input id="{{ $id }}" type="text" name="{{ $name }}" value="{{ $current }}" maxlength="{{ $field->max }}" @if ($error) aria-invalid="true" @endif @class([$input, 'h-9', 'font-mono text-xs' => $field->type === FieldType::Url])>
        @endswitch
        @if ($hint)
            <p class="mt-1 text-xs text-muted">{{ $hint }}</p>
        @endif
    @endif

    @if ($error)
        <p class="mt-1 text-sm text-danger-ink">{{ $error }}</p>
    @endif

    @if ($referenceLocale && filled($reference) && ! is_bool($reference))
        <p class="mt-1 text-xs break-words text-muted"><span class="font-mono uppercase">{{ $referenceLocale }}:</span> {{ \Illuminate\Support\Str::limit(strip_tags((string) $reference), 300) }}</p>
    @endif
</div>
