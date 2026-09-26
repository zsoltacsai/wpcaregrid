{{--
    Operator console layout: the product UI's navy sidebar, denser content,
    a wider working area. Navigation shows only sections the operator may open.
--}}
@use('App\Operator\Permission')
@props(['title'])

@php
    $operator = auth('operator')->user();
    $navigation = array_filter([
        ['route' => 'operator.dashboard', 'active' => 'operator.dashboard', 'label' => __('operator.nav.overview'), 'icon' => 'grid', 'permission' => Permission::WebsitesView],
        ['route' => 'operator.websites.index', 'active' => 'operator.websites.*', 'label' => __('operator.nav.websites'), 'icon' => 'globe', 'permission' => Permission::WebsitesView],
        ['route' => 'operator.backups.index', 'active' => 'operator.backups.*', 'label' => __('operator.nav.backups'), 'icon' => 'archive', 'permission' => Permission::BackupsView],
        ['route' => 'operator.security.index', 'active' => 'operator.security.*', 'label' => __('operator.nav.security'), 'icon' => 'shield', 'permission' => Permission::SecurityView],
        ['route' => 'operator.activity.index', 'active' => 'operator.activity.*', 'label' => __('operator.nav.activity'), 'icon' => 'list', 'permission' => Permission::AuditView],
    ], fn ($item) => $operator?->hasOperatorPermission($item['permission']));
    $refusal = session('operator_error');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ __('operator.meta_title', ['page' => $title]) }}</title>
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
                    <a href="{{ route('operator.dashboard') }}" class="rounded-sm" aria-label="{{ __('operator.console') }}">
                        <x-brand.logo tone="dark" :descriptor="false" class="h-7" />
                    </a>
                    <span class="hidden font-mono text-[11px] tracking-[0.12em] text-on-dark-muted uppercase lg:block">{{ __('operator.console') }}</span>
                    <button
                        type="button"
                        class="-mr-2 inline-flex size-10 items-center justify-center rounded-sm hover:bg-white/10 lg:hidden"
                        @click="open = !open"
                        aria-controls="operator-nav"
                        aria-expanded="false"
                        :aria-expanded="open.toString()"
                    >
                        <span class="sr-only" x-text="open ? @js(__('operator.nav.close_menu')) : @js(__('operator.nav.open_menu'))">{{ __('operator.nav.open_menu') }}</span>
                        <x-ui.icon name="menu" class="size-6" x-show="!open" />
                        <x-ui.icon name="close" class="size-6" x-show="open" x-cloak />
                    </button>
                </div>

                <div id="operator-nav" class="hidden border-t border-dark-line px-3 pb-4 lg:flex lg:flex-1 lg:flex-col lg:border-0" :class="open ? '!block' : ''">
                    <nav aria-label="{{ __('operator.nav.label') }}" class="pt-3 lg:pt-0">
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
                        <p class="truncate px-3 text-xs text-on-dark-muted">{{ __('operator.nav.signed_in_as', ['email' => $operator?->email]) }}</p>
                        <form method="POST" action="{{ route('operator.logout') }}" class="mt-2">
                            @csrf
                            <button type="submit" class="flex h-9 w-full items-center gap-3 rounded-sm px-3 text-sm font-medium text-on-dark-muted hover:bg-white/5 hover:text-on-dark">
                                <x-ui.icon name="logout" class="size-4" />
                                {{ __('operator.nav.logout') }}
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
                        <x-account.notice :tone="$refusal['kind'] === 'busy' || $refusal['kind'] === 'input' ? 'warning' : 'danger'" class="mb-5">
                            <strong class="font-semibold">{{ __('operator.kinds.'.$refusal['kind']) }}:</strong> {{ $refusal['message'] }}
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
