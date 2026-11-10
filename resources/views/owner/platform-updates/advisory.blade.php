@php($part = $advisory->component)
<x-layouts.owner :title="__('platform_updates.detail.title', ['id' => $advisory->advisory_id])">
    <x-operator.page-header :title="__('platform_updates.detail.title', ['id' => $advisory->advisory_id])" :lead="$part->name.' '.($part->installed_version ?? __('platform_updates.version_states.UNKNOWN'))" />

    <p class="mb-4 text-sm"><a class="underline" href="{{ route('owner.platform-updates.index') }}">{{ __('platform_updates.detail.back') }}</a></p>

    <x-operator.section :title="__('platform_updates.detail.recommended')">
        <p class="text-sm" data-testid="recommended-action">{{ __('platform_updates.action.'.\App\Platform\Updates\PlatformUpdateOverview::action($advisory), ['component' => $part->name, 'fixed' => $advisory->fixed_version]) }}</p>
    </x-operator.section>

    <x-operator.section class="mt-5" :title="__('platform_updates.advisories')">
        <dl class="grid grid-cols-[minmax(0,12rem)_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm">
            <dt class="text-muted">{{ __('platform_updates.columns.component') }}</dt><dd class="break-all">{{ $part->name }} ({{ $part->kind }})</dd>
            <dt class="text-muted">{{ __('platform_updates.columns.installed') }}</dt><dd class="font-mono">{{ $part->installed_version ?? __('platform_updates.version_states.UNKNOWN') }}</dd>
            <dt class="text-muted">{{ __('platform_updates.columns.fixed') }}</dt><dd class="font-mono">{{ $advisory->fixed_version ?? (\App\Platform\Updates\PlatformUpdateOverview::fixNotConfirmed($advisory) ? __('platform_updates.detail.not_confirmed') : __('platform_updates.detail.none')) }}</dd>
            <dt class="text-muted">{{ __('platform_updates.columns.severity') }}</dt><dd>{{ __('platform_updates.severity.'.$advisory->severity) }}</dd>
            <dt class="text-muted">{{ __('platform_updates.detail.aliases') }}</dt><dd class="font-mono break-all">{{ collect($advisory->aliases ?? [])->implode(', ') ?: __('platform_updates.detail.none') }}</dd>
            <dt class="text-muted">{{ __('platform_updates.columns.published') }}</dt><dd>@if ($advisory->published_at) @displayDateTime($advisory->published_at, 'Y-m-d') @else — @endif</dd>
            <dt class="text-muted">{{ __('platform_updates.detail.summary') }}</dt><dd class="break-words">{{ $advisory->summary ?? '—' }}</dd>
            <dt class="text-muted">{{ __('platform_updates.detail.official') }}</dt><dd><a class="break-all underline" rel="noopener noreferrer" href="{{ $advisory->source_url }}">{{ $advisory->source_url }}</a></dd>
            <dt class="text-muted">{{ __('platform_updates.detail.last_checked') }}</dt><dd>@displayDateTime($advisory->details_checked_at ?? $advisory->updated_at, 'Y-m-d H:i T')</dd>
            <dt class="text-muted">{{ __('platform_updates.columns.state') }}</dt><dd>{{ __('platform_updates.advisory_status.'.$advisory->state) }}</dd>
        </dl>
    </x-operator.section>
</x-layouts.owner>
