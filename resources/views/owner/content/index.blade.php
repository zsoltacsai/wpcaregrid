<x-layouts.owner :title="__('owner.content.title')">
    <x-operator.page-header :title="__('owner.content.title')" :lead="__('owner.content.lead')">
        <x-slot:actions>
            @foreach ($previews as $code => $url)
                <x-ui.button :href="$url" variant="secondary" size="sm" target="_blank" rel="noopener">{{ __('owner.content.preview_language', ['language' => $locales[$code]]) }}</x-ui.button>
            @endforeach
        </x-slot:actions>
    </x-operator.page-header>

    <x-account.notice tone="neutral" class="mb-4">{{ __('owner.content.fallback_policy', ['fallback' => $locales[\App\Content\Schema\ContentSchema::fallbackLocale()]]) }}</x-account.notice>

    @foreach ($groups as $group => $rows)
        <x-operator.section :title="__('owner.content.groups.'.$group)" :id="'content-'.$group" class="mb-4">
            <ul class="divide-y divide-line">
                @foreach ($rows as $key => $row)
                    <li class="flex flex-col gap-2 py-2 sm:flex-row sm:items-center sm:justify-between">
                        <span class="min-w-0">
                            <span class="block font-medium text-primary">{{ \App\Content\Schema\ContentSchema::label($key) }}</span>
                            <span class="block font-mono text-[11px] text-muted">{{ $key }}</span>
                        </span>
                        <span class="flex flex-wrap gap-2">
                            @foreach ($row['states'] as $code => $state)
                                <a href="{{ route('owner.content.edit', [$key, $code]) }}" class="inline-flex items-center gap-1.5 rounded-sm border border-line px-2 py-1 text-xs hover:border-primary" aria-label="{{ \App\Content\Schema\ContentSchema::label($key) }} · {{ $locales[$code] }} · {{ $state->label() }}">
                                    <span class="font-mono uppercase">{{ $code }}</span>
                                    <x-ui.status-badge :tone="$state->tone()">{{ $state->label() }}</x-ui.status-badge>
                                </a>
                            @endforeach
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-operator.section>
    @endforeach
</x-layouts.owner>
