<x-layouts.marketing :title="$document?->title ?? __('legal.unavailable_title')" :description="__('legal.description')" :indexable="false">
    <x-ui.container class="py-12 lg:py-16">
        @if ($unavailable ?? false)
            <section class="mx-auto max-w-3xl rounded-md border border-line bg-surface p-6 sm:p-8">
                <p class="text-xs font-semibold tracking-wide text-muted uppercase">{{ strtoupper($type) }} · {{ strtoupper($locale) }}</p>
                <h1 class="mt-3 text-2xl font-semibold">{{ __('legal.unavailable_title') }}</h1>
                <p class="mt-3 text-sm text-muted">{{ __('legal.unavailable_body') }}</p>
            </section>
        @else
            <article class="mx-auto max-w-3xl rounded-md border border-line bg-surface p-6 sm:p-8">
                <p class="text-xs font-semibold tracking-wide text-muted uppercase">{{ strtoupper($document->document_type) }} · {{ strtoupper($document->locale) }} · v{{ $document->version }}</p>
                @if (isset($historical) && $historical)<p class="mt-2 text-xs text-warning-ink">{{ __('legal.historical_version') }}</p>@endif
                <h1 class="mt-3 text-2xl font-semibold">{{ $document->title }}</h1>
                <p class="mt-2 text-xs text-muted">{{ __('legal.effective_at') }}: {{ $document->effective_at?->utc()->format('Y-m-d H:i').' UTC' }}</p>
                <div class="prose mt-8 max-w-none">{!! \App\Content\HtmlSanitizer::clean($document->body) !!}</div>
            </article>
        @endif
    </x-ui.container>
</x-layouts.marketing>
