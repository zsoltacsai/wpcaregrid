{{-- Owner sign-in: its own page and guard, apart from customer and operator logins. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('owner.meta_title', ['page' => __('owner.auth.title')]) }}</title>
    <meta name="theme-color" content="#17212B">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen flex-col bg-primary px-4 py-10 font-sans text-ink antialiased">
    <main class="m-auto w-full max-w-[420px]">
        <div class="on-dark mb-6 flex items-center justify-between gap-4 text-on-dark">
            <x-brand.logo tone="dark" :descriptor="false" class="h-7" />
            <span class="font-mono text-[11px] tracking-[0.12em] text-on-dark-muted uppercase">{{ __('owner.area') }}</span>
        </div>

        <div class="rounded-md border border-line bg-surface p-6 sm:p-8">
            <h1 class="text-xl font-semibold text-primary">{{ __('owner.auth.title') }}</h1>
            <p class="mt-1 text-sm text-muted">{{ __('owner.auth.lead') }}</p>

            @if (session('status'))
                <x-account.notice tone="positive" class="mt-5">{{ session('status') }}</x-account.notice>
            @endif

            <form method="POST" action="{{ route('owner.login.store') }}" class="mt-6 grid gap-5" novalidate>
                @csrf
                <x-form.field name="email" type="email" :label="__('owner.auth.email')" autocomplete="username" required autofocus />
                <x-form.field name="password" type="password" :label="__('owner.auth.password')" autocomplete="current-password" required />
                <x-ui.button type="submit" size="lg" class="w-full">{{ __('owner.auth.submit') }}</x-ui.button>
            </form>
        </div>
    </main>
</body>
</html>
