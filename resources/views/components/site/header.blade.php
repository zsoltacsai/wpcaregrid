@php
    $navigation = \App\Content\HomepageContent::navigation();
    $links = config('marketing.links');
@endphp

<header
    x-data="{ open: false, scrolled: false }"
    x-init="scrolled = window.scrollY > 8"
    @scroll.window.passive="scrolled = window.scrollY > 8"
    @keydown.escape.window="if (open) { open = false; $refs.menuButton.focus() }"
    @resize.window.debounce.150ms="if (window.innerWidth >= 1024) open = false"
    x-bind:data-raised="scrolled || open"
    class="sticky top-0 z-50 border-b border-transparent bg-canvas transition-shadow duration-200 data-raised:border-line data-raised:shadow-header"
>
    <x-ui.container class="flex h-16 items-center justify-between gap-6 lg:h-[72px]">
        <a href="{{ route('home') }}" class="shrink-0 rounded-sm" aria-label="WP CareGrid home">
            <x-brand.logo :descriptor="false" class="h-8 lg:h-9" />
        </a>

        <nav aria-label="Main" class="hidden lg:block">
            <ul class="flex items-center gap-6 xl:gap-8">
                @foreach ($navigation as $item)
                    <li>
                        <a href="{{ $item['href'] }}" class="rounded-sm text-sm font-medium text-ink transition-colors hover:text-accent-ink">
                            {{ $item['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="hidden items-center gap-2 lg:flex">
            <x-ui.button :href="$links['login']" variant="ghost">Login</x-ui.button>
            <x-ui.button href="#pricing">Get started</x-ui.button>
        </div>

        <button
            type="button"
            x-ref="menuButton"
            @click="open = !open"
            class="-mr-2 inline-flex size-10 items-center justify-center rounded-sm text-primary hover:bg-subtle lg:hidden"
            aria-controls="mobile-menu"
            aria-expanded="false"
            :aria-expanded="open.toString()"
        >
            <span class="sr-only" x-text="open ? 'Close menu' : 'Open menu'">Open menu</span>
            <x-ui.icon name="menu" class="size-6" x-show="!open" />
            <x-ui.icon name="close" class="size-6" x-show="open" x-cloak />
        </button>
    </x-ui.container>

    <div
        id="mobile-menu"
        x-show="open"
        x-cloak
        class="max-h-[calc(100dvh-64px)] overflow-y-auto border-t border-line bg-canvas lg:hidden"
    >
        <x-ui.container class="py-4">
            <nav aria-label="Mobile">
                <ul class="divide-y divide-line">
                    @foreach ($navigation as $item)
                        <li>
                            <a
                                href="{{ $item['href'] }}"
                                @click="open = false"
                                class="flex h-12 items-center justify-between text-base font-medium text-primary"
                            >
                                {{ $item['label'] }}
                                <x-ui.icon name="arrow-right" class="size-4 text-muted" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <div class="mt-4 grid grid-cols-2 gap-2 pb-2">
                <x-ui.button :href="$links['login']" variant="secondary" size="lg">Login</x-ui.button>
                <x-ui.button href="#pricing" size="lg" x-on:click="open = false">Get started</x-ui.button>
            </div>
        </x-ui.container>
    </div>
</header>
