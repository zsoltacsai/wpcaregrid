{{-- Notification shown when a third-party license has expired. --}}
@props(['product', 'site' => null, 'detected' => null])

<article {{ $attributes->merge(['class' => 'overflow-hidden rounded-lg border border-line bg-surface shadow-raised']) }} aria-labelledby="license-notice-title">
    <div class="flex items-center justify-between gap-4 border-b border-line px-6 py-4">
        <p class="flex items-center gap-2 text-sm text-muted">
            <x-ui.icon name="key" class="size-4" />
            Plugin license
        </p>
        <x-ui.demo-label text="Example" />
    </div>

    <div class="border-l-4 border-danger px-6 py-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 id="license-notice-title" class="text-2xl font-semibold">{{ $product }}</h3>
            <x-ui.status-badge tone="danger" variant="code">License expired</x-ui.status-badge>
        </div>

        @if ($site || $detected)
            <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                @if ($site)
                    <div>
                        <dt class="text-muted">Website</dt>
                        <dd class="mt-1 text-primary">{{ $site }}</dd>
                    </div>
                @endif
                @if ($detected)
                    <div>
                        <dt class="text-muted">Detected</dt>
                        <dd class="mt-1 font-mono text-primary">{{ $detected }}</dd>
                    </div>
                @endif
            </dl>
        @endif

        <p class="mt-6 flex items-start gap-3 rounded-sm border border-warning/40 bg-warning-soft px-4 py-3 text-sm text-warning-ink">
            <x-ui.icon name="alert-triangle" class="mt-px size-4" />
            <span><strong class="font-semibold">Customer action required.</strong> Renew the license to keep receiving updates.</span>
        </p>
    </div>
</article>
