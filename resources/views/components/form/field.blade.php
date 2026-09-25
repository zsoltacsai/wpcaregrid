{{--
    Labelled text input with hint and validation error.
    Old input and errors are read by `name`.
--}}
@props(['name', 'label', 'type' => 'text', 'hint' => null, 'optional' => false, 'value' => null])

@php
    $id = 'field-'.$name;
    $error = $errors->first($name);
    $describedBy = collect([$hint ? $id.'-hint' : null, $error ? $id.'-error' : null])->filter()->implode(' ');
@endphp

<div {{ $attributes->only('class') }}>
    <label for="{{ $id }}" class="block text-sm font-medium text-primary">
        {{ $label }}
        @if ($optional)<span class="font-normal text-muted">({{ __('checkout.fields.optional') }})</span>@endif
    </label>
    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ old($name, $value) }}"
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        @if ($error) aria-invalid="true" @endif
        {{ $attributes->except('class')->class([
            'mt-2 block h-12 w-full rounded-sm border bg-surface px-4 text-[15px] text-ink placeholder:text-muted',
            'border-danger' => $error,
            'border-line-strong hover:border-muted' => ! $error,
        ]) }}
    >
    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-2 text-xs text-muted">{{ $hint }}</p>
    @endif
    @if ($error)
        <p id="{{ $id }}-error" class="mt-2 flex items-start gap-1.5 text-sm text-danger-ink">
            <x-ui.icon name="alert-circle" class="mt-0.5 size-4" />
            {{ $error }}
        </p>
    @endif
</div>
