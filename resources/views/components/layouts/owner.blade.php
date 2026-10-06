{{--
    Owner administration layout: the operator console's navy sidebar and
    dense working area, with the owner's sections. Never indexed or cached.
--}}
@props(['title'])

@php
    $owner = auth('owner')->user();
    $navigation = [
        ['route' => 'owner.dashboard', 'active' => 'owner.dashboard', 'label' => __('owner.nav.dashboard'), 'icon' => 'grid'],
        ['route' => 'owner.customers.index', 'active' => 'owner.customers.*', 'label' => __('owner.nav.customers'), 'icon' => 'user'],
        ['route' => 'owner.websites.index', 'active' => 'owner.websites.*', 'label' => __('owner.nav.websites'), 'icon' => 'globe'],
        ['route' => 'owner.operators.index', 'active' => 'owner.operators.*', 'label' => __('owner.nav.operators'), 'icon' => 'key'],
        ['route' => 'owner.pricing.index', 'active' => 'owner.pricing.*', 'label' => __('owner.nav.pricing'), 'icon' => 'credit-card'],
        ['route' => 'owner.coupons.index', 'active' => 'owner.coupons.*', 'label' => __('owner.nav.coupons'), 'icon' => 'receipt'],
        ['route' => 'owner.offers.index', 'active' => 'owner.offers.*', 'label' => __('owner.nav.offers'), 'icon' => 'shield'],
        ['route' => 'owner.content.index', 'active' => 'owner.content.*', 'label' => __('owner.nav.content'), 'icon' => 'list'],
        ['route' => 'owner.seo.index', 'active' => 'owner.seo.*', 'label' => __('owner.nav.seo'), 'icon' => 'search'],
        ['route' => 'owner.system', 'active' => 'owner.system', 'label' => __('owner.nav.system'), 'icon' => 'cog'],
        ['route' => 'owner.integrations.index', 'active' => 'owner.integrations.*', 'label' => __('owner.nav.integrations'), 'icon' => 'key'],
        ['route' => 'owner.dns.cloudflare.index', 'active' => 'owner.dns.cloudflare.*', 'label' => __('owner.nav.dns'), 'icon' => 'globe'],
    ];
    $refusal = session('owner_error');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ __('owner.meta_title', ['page' => $title]) }}</title>
        <meta name="theme-color" content="#17212B">
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-canvas">
        <a href="#main" class="sr-only rounded-sm bg-primary px-4 py-2 text-sm font-medium text-white focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[60]">
            {{ __('home.skip_link') }}
        </a>

        <div x-data="{ open: false }" @keydown.escape.window="open = false" class="lg:grid lg:min-h-screen lg:grid-cols-[220px_minmax(0,1fr)]">
            <header class="on-dark sticky top-0 z-40 bg-primary text-on-dark lg:static lg:flex lg:flex-col">
                <div class="flex h-14 items-center justify-between gap-4 px-4 sm:px-6 lg:h-auto lg:flex-col lg:items-start lg:gap-2 lg:px-5 lg:pt-5 lg:pb-6">
                    <a href="{{ route('owner.dashboard') }}" class="rounded-sm" aria-label="{{ __('owner.area') }}">
                        <x-brand.logo tone="dark" :descriptor="false" class="h-7" />
                    </a>
                    <span class="hidden font-mono text-[11px] tracking-[0.12em] text-on-dark-muted uppercase lg:block">{{ __('owner.area') }}</span>
                    <button
                        type="button"
                        class="-mr-2 inline-flex size-10 items-center justify-center rounded-sm hover:bg-white/10 lg:hidden"
                        @click="open = !open"
                        aria-controls="owner-nav"
                        aria-expanded="false"
                        :aria-expanded="open.toString()"
                    >
                        <span class="sr-only" x-text="open ? @js(__('owner.nav.close_menu')) : @js(__('owner.nav.open_menu'))">{{ __('owner.nav.open_menu') }}</span>
                        <x-ui.icon name="menu" class="size-6" x-show="!open" />
                        <x-ui.icon name="close" class="size-6" x-show="open" x-cloak />
                    </button>
                </div>

                <div id="owner-nav" class="hidden border-t border-dark-line px-3 pb-4 lg:flex lg:flex-1 lg:flex-col lg:border-0" :class="open ? '!block' : ''">
                    <nav aria-label="{{ __('owner.nav.label') }}" class="pt-3 lg:pt-0">
                        <ul class="space-y-0.5">
                            @foreach ($navigation as $item)
                                @php $active = request()->routeIs($item['active']); @endphp
                                <li>
                                    <a
                                        href="{{ route($item['route']) }}"
                                        @if ($active) aria-current="page" @endif
                                        @class([
                                            'flex h-9 items-center gap-3 rounded-sm px-3 text-sm font-medium transition-colors',
                                            'bg-action text-white' => $active,
                                            'text-on-dark-muted hover:bg-white/5 hover:text-on-dark' => ! $active,
                                        ])
                                    >
                                        <x-ui.icon :name="$item['icon']" class="size-4" />
                                        {{ $item['label'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </nav>

                    <div class="mt-6 border-t border-dark-line pt-4 lg:mt-auto">
                        <p class="truncate px-3 text-xs text-on-dark-muted">{{ __('owner.nav.signed_in_as', ['email' => $owner?->email]) }}</p>
                        <form method="POST" action="{{ route('owner.logout') }}" class="mt-2">
                            @csrf
                            <button type="submit" class="flex h-9 w-full items-center gap-3 rounded-sm px-3 text-sm font-medium text-on-dark-muted hover:bg-white/5 hover:text-on-dark">
                                <x-ui.icon name="logout" class="size-4" />
                                {{ __('owner.nav.logout') }}
                            </button>
                        </form>
                    </div>
                </div>
            </header>

            <main id="main" tabindex="-1" class="min-w-0 px-4 py-6 focus:outline-none sm:px-6 lg:px-8 lg:py-8">
                <div class="mx-auto max-w-[1280px]">
                    @if (session('status'))
                        <x-account.notice tone="positive" class="mb-5">{{ session('status') }}</x-account.notice>
                    @endif
                    @if (is_array($refusal))
                        <x-account.notice :tone="in_array($refusal['kind'], ['busy', 'input'], true) ? 'warning' : 'danger'" class="mb-5">
                            {{ $refusal['message'] }}
                            <span class="ml-1 font-mono text-[11px] opacity-75">{{ $refusal['code'] }}</span>
                        </x-account.notice>
                    @endif
                    @if ($errors->any())
                        <x-account.notice tone="danger" class="mb-5">{{ $errors->first() }}</x-account.notice>
                    @endif

                    {{ $slot }}
                </div>
            </main>
        </div>
    </body>
</html>
