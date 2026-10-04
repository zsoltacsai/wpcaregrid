<x-layouts.owner :title="__('owner.seo.title')">
    <x-operator.page-header :title="__('owner.seo.title')" :lead="__('owner.seo.lead')" />

    <x-account.notice tone="neutral" class="mb-4">{{ __('owner.seo.scope') }}</x-account.notice>

    @foreach ($pages as $key => $languages)
        <x-operator.section :title="\App\Content\Schema\ContentSchema::label($key)" :id="'seo-'.str_replace('.', '-', $key)" class="mb-4">
            <div class="grid gap-3 md:grid-cols-2">
                @foreach ($languages as $code => $page)
                    @php $values = $page['values']; @endphp
                    <div class="min-w-0 rounded-sm border border-line p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-sm font-medium text-primary">{{ $locales[$code] }}</span>
                            <x-ui.status-badge :tone="$page['state']->tone()">{{ $page['state']->label() }}</x-ui.status-badge>
                        </div>
                        <dl class="mt-2 grid grid-cols-[minmax(0,90px)_minmax(0,1fr)] gap-x-2 gap-y-1 text-xs">
                            <dt class="text-muted">{{ __('content.fields.title') }}</dt><dd class="break-words text-primary">{{ $values['title'] }}</dd>
                            <dt class="text-muted">{{ __('content.fields.robots') }}</dt><dd class="font-mono">{{ $values['robots'] }}</dd>
                            <dt class="text-muted">{{ __('content.fields.canonical_url') }}</dt><dd class="font-mono break-all">{{ $values['canonical_url'] ?: __('owner.seo.canonical_default') }}</dd>
                        </dl>
                        @if ($page['state']->isMissingTranslation())
                            <p class="mt-2 text-xs text-warning-ink">{{ __('owner.seo.fallback', ['fallback' => strtoupper($values->locale)]) }}</p>
                        @endif
                        <x-ui.button :href="route('owner.seo.edit', [$key, $code])" size="sm" variant="secondary" class="mt-3">{{ __('owner.seo.edit') }}</x-ui.button>
                    </div>
                @endforeach
            </div>
        </x-operator.section>
    @endforeach
</x-layouts.owner>
