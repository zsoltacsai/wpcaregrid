{{--
    Customer area layout: the same brand system as the public site, with the
    navy sidebar and green active item of the product UI.
--}}
@props(['title', 'localeForm' => null])

@php
    $navigation = [
        ['route' => 'account.dashboard', 'active' => 'account.dashboard', 'label' => __('account.nav.dashboard'), 'icon' => 'grid'],
        ['route' => 'account.websites.index', 'active' => 'account.websites.*', 'label' => __('account.nav.websites'), 'icon' => 'globe'],
        ['route' => 'account.subscription', 'active' => 'account.subscription*', 'label' => __('account.nav.subscription'), 'icon' => 'refresh'],
        ['route' => 'account.billing', 'active' => 'account.billing', 'label' => __('account.nav.billing'), 'icon' => 'credit-card'],
        ['route' => 'account.profile', 'active' => 'account.profile*', 'label' => __('account.nav.profile'), 'icon' => 'user'],
        ['route' => 'account.privacy.index', 'active' => 'account.privacy.*', 'label' => __('account.nav.privacy'), 'icon' => 'shield'],
    ];

    // Custom offers appear in the menu only for a customer who has been sent one.
    if (auth()->user()?->customer?->recoveryOffers()->visibleToCustomer()->exists()) {
        array_splice($navigation, 3, 0, [['route' => 'account.offers.index', 'active' => 'account.offers.*', 'label' => __('account.nav.offers'), 'icon' => 'clipboard']]);
    }
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ __('account.meta_title', ['page' => $title]) }}</title>
        <meta name="theme-color" content="#17212B">
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-canvas">
        <a href="#main" class="sr-only rounded-sm bg-primary px-4 py-2 text-sm font-medium text-white focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[60]">
            {{ __('home.skip_link') }}
        </a>

        <div x-data="{ open: false }" @keydown.escape.window="open = false" class="lg:grid lg:min-h-screen lg:grid-cols-[248px_minmax(0,1fr)]">
            {{-- Sidebar (lg) / top bar with menu (smaller screens) --}}
            <header class="on-dark sticky top-0 z-40 bg-primary text-on-dark lg:static lg:flex lg:flex-col">
                <div class="flex h-16 items-center justify-between gap-4 px-4 sm:px-6 lg:h-auto lg:px-6 lg:pt-6 lg:pb-8">
                    <a href="{{ app(\App\Localization\MarketingRoutes::class)->home() }}" class="rounded-sm" aria-label="{{ __('home.home_link') }}">
                        <x-brand.logo tone="dark" :descriptor="false" class="h-8" />
                    </a>
                    <button
                        type="button"
                        class="-mr-2 inline-flex size-10 items-center justify-center rounded-sm hover:bg-white/10 lg:hidden"
                        @click="open = !open"
                        aria-controls="account-nav"
                        aria-expanded="false"
                        :aria-expanded="open.toString()"
                    >
                        <span class="sr-only" x-text="open ? @js(__('account.nav.close_menu')) : @js(__('account.nav.open_menu'))">{{ __('account.nav.open_menu') }}</span>
                        <x-ui.icon name="menu" class="size-6" x-show="!open" />
                        <x-ui.icon name="close" class="size-6" x-show="open" x-cloak />
                    </button>
                </div>

                <div id="account-nav" class="hidden border-t border-dark-line px-4 pb-4 lg:flex lg:flex-1 lg:flex-col lg:border-0" :class="open ? '!block' : ''">
                    <nav aria-label="{{ __('account.nav.label') }}" class="pt-4 lg:pt-0">
                        <ul class="space-y-1">
                            @foreach ($navigation as $item)
                                @php $active = request()->routeIs($item['active']); @endphp
                                <li>
                                    <a
                                        href="{{ route($item['route']) }}"
                                        @if ($active) aria-current="page" @endif
                                        @class([
                                            'flex h-10 items-center gap-3 rounded-sm px-3 text-sm font-medium transition-colors',
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
                        <x-site.language-switcher :form="$localeForm" tone="dark" class="-ml-1" />
                        <p class="truncate px-3 text-xs text-on-dark-muted">{{ __('account.nav.signed_in_as', ['email' => auth()->user()->email]) }}</p>
                        <form method="POST" action="{{ route('logout') }}" class="mt-2">
                            @csrf
                            <button type="submit" class="flex h-10 w-full items-center gap-3 rounded-sm px-3 text-sm font-medium text-on-dark-muted hover:bg-white/5 hover:text-on-dark">
                                <x-ui.icon name="logout" class="size-4" />
                                {{ __('account.nav.logout') }}
                            </button>
                        </form>
                    </div>
                </div>
            </header>

            <main id="main" tabindex="-1" class="min-w-0 px-4 py-8 focus:outline-none sm:px-6 lg:px-10 lg:py-10">
                <div class="mx-auto max-w-[1040px]">
                    @if (session('status'))
                        <x-account.notice tone="positive" class="mb-6">{{ session('status') }}</x-account.notice>
                    @endif
                    @if (session('account_error'))
                        <x-account.notice tone="danger" class="mb-6">{{ session('account_error') }}</x-account.notice>
                    @endif
                    @if (session('backup_error'))
                        <x-account.notice tone="danger" class="mb-6">{{ session('backup_error') }}</x-account.notice>
                    @endif
                    @if ($errors->has('subscription'))
                        <x-account.notice tone="danger" class="mb-6">{{ $errors->first('subscription') }}</x-account.notice>
                    @endif

                    {{ $slot }}
                </div>
            </main>
        </div>
    </body>
</html>
