{{-- Labelled native select with hint and validation error. --}}
@props(['name', 'label', 'options', 'selected' => null, 'hint' => null])

@php
    $id = 'field-'.$name;
    $error = $errors->first($name);
    $current = old($name, $selected);
    $describedBy = collect([$hint ? $id.'-hint' : null, $error ? $id.'-error' : null])->filter()->implode(' ');
@endphp

<div {{ $attributes->only('class') }}>
    <label for="{{ $id }}" class="block text-sm font-medium text-primary">{{ $label }}</label>
    <div class="relative mt-2">
        <select
            id="{{ $id }}"
            name="{{ $name }}"
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @if ($error) aria-invalid="true" @endif
            {{ $attributes->except('class')->class([
                'block h-12 w-full appearance-none rounded-sm border bg-surface pr-10 pl-4 text-[15px] text-ink',
                'border-danger' => $error,
                'border-line-strong hover:border-muted' => ! $error,
            ]) }}
        >
            @foreach ($options as $value => $optionLabel)
                <option value="{{ $value }}" @selected($current === $value)>{{ $optionLabel }}</option>
            @endforeach
        </select>
        <x-ui.icon name="chevron-down" class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-muted" />
    </div>
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
