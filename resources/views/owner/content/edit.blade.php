@use('App\Content\Schema\FieldType')
@use('App\Content\Schema\Field')
@use('App\Content\ContentValidator')

@php
    $sectionName = \App\Content\Schema\ContentSchema::label($section->key);
    $fallback = \App\Content\Schema\ContentSchema::fallbackLocale();
    $referenceLocale = $reference !== null ? $fallback : null;
    $routes = ['edit' => "owner.{$area}.edit", 'update' => "owner.{$area}.update", 'unpublish' => "owner.{$area}.unpublish", 'discard' => "owner.{$area}.discard"];
@endphp

<x-layouts.owner :title="$sectionName.' · '.strtoupper($locale)">
    <x-operator.page-header :title="$sectionName" :eyebrow="__('owner.content.groups.'.$section->group)" :lead="$section->key">
        <x-slot:actions>
            @if ($preview)
                <x-ui.button :href="$preview" variant="secondary" size="sm" target="_blank" rel="noopener">{{ __('owner.content.preview') }}</x-ui.button>
            @endif
        </x-slot:actions>
    </x-operator.page-header>

    {{-- Every language, with its state: a missing translation is never hidden. --}}
    <nav aria-label="{{ __('owner.content.languages') }}" class="mb-4 flex flex-wrap gap-2">
        @foreach ($states as $code => $languageState)
            <a href="{{ route($routes['edit'], [$section->key, $code]) }}" @if ($code === $locale) aria-current="page" @endif
               @class(['inline-flex items-center gap-2 rounded-sm border px-3 py-1.5 text-sm', 'border-primary bg-surface font-medium text-primary' => $code === $locale, 'border-line text-ink hover:border-primary' => $code !== $locale])>
                {{ $locales[$code] }}
                <x-ui.status-badge :tone="$languageState->tone()">{{ $languageState->label() }}</x-ui.status-badge>
            </a>
        @endforeach
    </nav>

    @if ($state->isMissingTranslation())
        <x-account.notice tone="warning" class="mb-4">{{ __('owner.content.missing_notice', ['language' => $locales[$locale], 'fallback' => $locales[$fallback]]) }}</x-account.notice>
    @elseif ($state->state === \App\Content\ContentState::BUILT_IN)
        <x-account.notice tone="neutral" class="mb-4">{{ __('owner.content.built_in_notice') }}</x-account.notice>
    @elseif ($state->state === \App\Content\ContentState::CHANGED)
        <x-account.notice tone="warning" class="mb-4">{{ __('owner.content.changed_notice') }}</x-account.notice>
    @elseif ($state->state === \App\Content\ContentState::DRAFT)
        <x-account.notice tone="warning" class="mb-4">{{ __('owner.content.draft_notice') }}</x-account.notice>
    @endif

    @if ($reference !== null)
        <p class="mb-4 text-xs text-muted">{{ __('owner.content.reference_note', ['fallback' => $locales[$fallback]]) }}</p>
    @endif

    <form method="POST" action="{{ route($routes['update'], [$section->key, $locale]) }}" class="grid gap-4" novalidate>
        @csrf
        @method('PUT')

        @foreach ($section->fields as $field)
            @php $path = 'fields.'.$field->name; $name = 'fields['.$field->name.']'; @endphp

            @if ($field->type === FieldType::Group)
                <fieldset class="min-w-0 rounded-md border border-line bg-surface p-4">
                    <legend class="px-1 text-sm font-semibold text-primary">{{ ContentValidator::label($field) }}</legend>
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ($field->keys as $itemKey)
                            <div class="min-w-0 rounded-sm border border-line p-3">
                                <p class="mb-2 font-mono text-[11px] tracking-[0.08em] text-muted uppercase">{{ __('content.items.'.$itemKey) !== 'content.items.'.$itemKey ? __('content.items.'.$itemKey) : $itemKey }}</p>
                                <div class="grid gap-3">
                                    @foreach ($field->fields as $child)
                                        <x-owner.content-field
                                            :field="$child"
                                            :name="$name.'['.$itemKey.']['.$child->name.']'"
                                            :path="$path.'.'.$itemKey.'.'.$child->name"
                                            :value="$values[$field->name][$itemKey][$child->name] ?? null"
                                            :reference="$reference[$field->name][$itemKey][$child->name] ?? null"
                                            :reference-locale="$referenceLocale"
                                        />
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </fieldset>
            @elseif ($field->type === FieldType::Repeater)
                @php
                    $items = array_values((array) old($path, $values[$field->name] ?? []));
                    $repeaterErrors = collect($errors->getMessages())->filter(fn ($m, $k) => str_starts_with($k, $path.'.'))->flatten()->unique();
                @endphp
                <fieldset
                    class="min-w-0 rounded-md border border-line bg-surface p-4"
                    x-data="{
                        items: @js(array_map(fn ($item, $i) => array_merge(Field::emptyItem($field->fields), is_array($item) ? $item : [], ['_k' => $i]), $items, array_keys($items))),
                        blank: @js(Field::emptyItem($field->fields)),
                        next: {{ count($items) }},
                        max: {{ $field->max }},
                        move(index, step) { const to = index + step; if (to < 0 || to >= this.items.length) return; const [item] = this.items.splice(index, 1); this.items.splice(to, 0, item); },
                    }"
                >
                    <legend class="px-1 text-sm font-semibold text-primary">{{ ContentValidator::label($field) }}</legend>
                    <p class="text-xs text-muted">{{ __('owner.content.repeater_hint', ['max' => $field->max]) }}</p>

                    @if ($repeaterErrors->isNotEmpty())
                        <ul class="mt-2 list-disc pl-5 text-sm text-danger-ink">
                            @foreach ($repeaterErrors as $message)<li>{{ $message }}</li>@endforeach
                        </ul>
                    @endif

                    <ol class="mt-3 grid gap-3">
                        <template x-for="(item, index) in items" :key="item._k">
                            <li class="min-w-0 rounded-sm border border-line p-3">
                                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                    <span class="font-mono text-xs text-muted" x-text="'#' + (index + 1)"></span>
                                    <span class="flex gap-1">
                                        <button type="button" class="h-7 rounded-sm border border-line px-2 text-xs hover:border-primary" @click="move(index, -1)" :disabled="index === 0">{{ __('owner.content.move_up') }}</button>
                                        <button type="button" class="h-7 rounded-sm border border-line px-2 text-xs hover:border-primary" @click="move(index, 1)" :disabled="index === items.length - 1">{{ __('owner.content.move_down') }}</button>
                                        <button type="button" class="h-7 rounded-sm border border-danger/40 px-2 text-xs text-danger-ink hover:border-danger" @click="items.splice(index, 1)">{{ __('owner.content.remove') }}</button>
                                    </span>
                                </div>
                                <div class="grid gap-3">
                                    @foreach ($field->fields as $child)
                                        @php $childName = "'{$name}[' + index + '][{$child->name}]'"; @endphp
                                        @switch($child->type)
                                            @case(FieldType::Boolean)
                                                <label class="flex items-center gap-2 text-sm text-primary">
                                                    <input type="hidden" :name="{{ $childName }}" value="0">
                                                    <input type="checkbox" :name="{{ $childName }}" value="1" x-model="item.{{ $child->name }}" class="size-4">
                                                    {{ ContentValidator::label($child) }}
                                                </label>
                                                @break
                                            @case(FieldType::Number)
                                                {{-- Order is the list order. --}}
                                                <input type="hidden" :name="{{ $childName }}" :value="index + 1">
                                                @break
                                            @case(FieldType::Textarea)
                                            @case(FieldType::RichText)
                                                <label class="block text-sm font-medium text-primary">
                                                    {{ ContentValidator::label($child) }}
                                                    <textarea :name="{{ $childName }}" x-model="item.{{ $child->name }}" rows="4" maxlength="{{ $child->type === FieldType::RichText ? $child->max * 2 : $child->max }}" class="mt-1 block w-full rounded-sm border border-line-strong bg-surface px-3 py-2 text-sm font-normal text-ink {{ $child->type === FieldType::RichText ? 'font-mono text-xs' : '' }}"></textarea>
                                                </label>
                                                @if ($child->type === FieldType::RichText)
                                                    <p class="-mt-2 text-xs text-muted">{{ __('owner.content.hints.rich_text') }}</p>
                                                @endif
                                                @break
                                            @default
                                                <label class="block text-sm font-medium text-primary">
                                                    {{ ContentValidator::label($child) }}
                                                    <input type="text" :name="{{ $childName }}" x-model="item.{{ $child->name }}" maxlength="{{ $child->max }}" @class(['mt-1 block h-9 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm font-normal text-ink', 'font-mono text-xs' => $child->type === FieldType::Url])>
                                                </label>
                                                @if ($child->type === FieldType::Url)
                                                    <p class="-mt-2 text-xs text-muted">{{ __('owner.content.hints.url') }}</p>
                                                @endif
                                        @endswitch
                                    @endforeach
                                </div>
                            </li>
                        </template>
                    </ol>
                    <p x-show="items.length === 0" class="mt-2 text-sm text-muted">{{ __('owner.content.repeater_empty') }}</p>
                    <button type="button" class="mt-3 inline-flex h-8 items-center gap-2 rounded-sm border border-line-strong px-3 text-sm text-primary hover:border-primary" x-show="items.length < max" @click="items.push(Object.assign({}, blank, { _k: next++ }))">
                        <x-ui.icon name="plus" class="size-4" /> {{ __('owner.content.add_item') }}
                    </button>

                    @if ($reference !== null && ! empty($reference[$field->name]))
                        <details class="mt-3 text-xs text-muted">
                            <summary class="cursor-pointer">{{ __('owner.content.reference_items', ['fallback' => $locales[$fallback], 'count' => count($reference[$field->name])]) }}</summary>
                            <ol class="mt-2 list-decimal space-y-1 pl-5">
                                @foreach ($reference[$field->name] as $item)
                                    <li class="break-words">{{ \Illuminate\Support\Str::limit(strip_tags((string) ($item['question'] ?? $item['label'] ?? '')), 160) }}</li>
                                @endforeach
                            </ol>
                        </details>
                    @endif
                </fieldset>
            @else
                <x-owner.content-field
                    :field="$field"
                    :name="$name"
                    :path="$path"
                    :value="$values[$field->name] ?? null"
                    :reference="$reference[$field->name] ?? null"
                    :reference-locale="$referenceLocale"
                    class="min-w-0 rounded-md border border-line bg-surface p-4"
                />
            @endif
        @endforeach

        <div class="sticky bottom-0 -mx-4 flex flex-wrap items-center gap-2 border-t border-line bg-canvas/95 px-4 py-3 sm:mx-0 sm:rounded-md sm:border">
            <x-ui.button type="submit" name="action" value="draft" variant="secondary">{{ __('owner.content.save_draft') }}</x-ui.button>
            <x-ui.button type="submit" name="action" value="publish">{{ __('owner.content.publish') }}</x-ui.button>
            <span class="text-xs text-muted">{{ __('owner.content.publish_hint') }}</span>
        </div>
    </form>

    <div class="mt-4 flex flex-wrap gap-2">
        @if ($state->hasPublished)
            <form method="POST" action="{{ route($routes['unpublish'], [$section->key, $locale]) }}">
                @csrf
                <x-ui.button type="submit" size="sm" variant="ghost">{{ __('owner.content.unpublish') }}</x-ui.button>
            </form>
        @endif
        @if ($state->hasDraft)
            <form method="POST" action="{{ route($routes['discard'], [$section->key, $locale]) }}">
                @csrf
                <x-ui.button type="submit" size="sm" variant="ghost">{{ __('owner.content.discard') }}</x-ui.button>
            </form>
        @endif
    </div>

    @if ($entry)
        <p class="mt-4 font-mono text-xs text-muted">
            @if ($entry->published_at){{ __('owner.content.published_at', ['at' => $entry->published_at->format('Y-m-d H:i')]) }}@endif
            @if ($entry->draft_updated_at) · {{ __('owner.content.draft_at', ['at' => $entry->draft_updated_at->format('Y-m-d H:i')]) }}@endif
        </p>
    @endif
</x-layouts.owner>
