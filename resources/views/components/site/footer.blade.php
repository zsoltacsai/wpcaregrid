@php
    $columns = \App\Content\HomepageContent::footerColumns();
@endphp

<footer class="border-t border-line bg-surface">
    <x-ui.container class="py-12 lg:py-16">
        <div class="grid gap-10 md:grid-cols-[1.4fr_repeat(3,1fr)] md:gap-8">
            <div>
                <a href="{{ route('home') }}" class="inline-block rounded-sm" aria-label="WP CareGrid home">
                    <x-site.logo />
                </a>
                <p class="mt-4 text-sm text-muted">WordPress Maintenance</p>
                <p class="mt-1 font-mono text-xs text-muted">wpcaregrid.com</p>
            </div>

            @foreach ($columns as $column)
                <nav aria-labelledby="footer-{{ Str::slug($column['heading']) }}">
                    <h2 id="footer-{{ Str::slug($column['heading']) }}" class="font-mono text-xs font-medium tracking-[0.08em] text-muted uppercase">
                        {{ $column['heading'] }}
                    </h2>
                    <ul class="mt-4 space-y-3">
                        @foreach ($column['links'] as $link)
                            <li>
                                <a href="{{ $link['href'] }}" class="rounded-sm text-sm text-ink transition-colors hover:text-accent-ink">
                                    {{ $link['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endforeach
        </div>

        <div class="mt-12 flex flex-col gap-2 border-t border-line pt-6 text-sm text-muted sm:flex-row sm:items-center sm:justify-between">
            <p>&copy; {{ now()->year }} WP CareGrid</p>
            <p>WordPress and WooCommerce maintenance</p>
        </div>
    </x-ui.container>
</footer>
