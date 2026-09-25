@php
    $columns = \App\Content\HomepageContent::footerColumns();
@endphp

<footer class="on-dark border-t border-dark-line bg-primary text-on-dark">
    <x-ui.container class="py-12 lg:py-16">
        <div class="grid gap-10 md:grid-cols-[1.4fr_repeat(3,1fr)] md:gap-8">
            <div>
                <a href="{{ route('home') }}" class="inline-block rounded-sm" aria-label="{{ __('home.home_link') }}">
                    <x-brand.logo tone="dark" class="h-16" />
                </a>
                <p class="mt-4 text-sm text-on-dark">{{ __('home.tagline') }}</p>
                <p class="mt-1 text-sm text-on-dark-muted">wpcaregrid.com</p>
            </div>

            @foreach ($columns as $column)
                <nav aria-labelledby="footer-{{ Str::slug($column['heading']) }}">
                    <h2 id="footer-{{ Str::slug($column['heading']) }}" class="text-xs font-semibold tracking-[0.12em] text-on-dark-muted uppercase">
                        {{ $column['heading'] }}
                    </h2>
                    <ul class="mt-4 space-y-3">
                        @foreach ($column['links'] as $link)
                            <li>
                                <a href="{{ $link['href'] }}" class="rounded-sm text-sm text-on-dark transition-colors hover:text-white hover:underline hover:underline-offset-4">
                                    {{ $link['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endforeach
        </div>

        <div class="mt-12 flex flex-col gap-2 border-t border-dark-line pt-6 text-sm text-on-dark-muted sm:flex-row sm:items-center sm:justify-between">
            <p>{{ __('home.footer.copyright', ['year' => now()->year]) }}</p>
            <p>{{ __('home.descriptor') }}</p>
        </div>
    </x-ui.container>
</footer>
