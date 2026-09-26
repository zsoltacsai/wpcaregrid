{{-- Operator sign-in: a separate page and guard from the customer login. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('operator.meta_title', ['page' => __('operator.auth.title')]) }}</title>
    <meta name="theme-color" content="#17212B">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen flex-col bg-primary px-4 py-10 font-sans text-ink antialiased">
    <main class="m-auto w-full max-w-[420px]">
        <div class="on-dark mb-6 flex items-center justify-between gap-4 text-on-dark">
            <x-brand.logo tone="dark" :descriptor="false" class="h-7" />
            <span class="font-mono text-[11px] tracking-[0.12em] text-on-dark-muted uppercase">{{ __('operator.console') }}</span>
        </div>

        <div class="rounded-md border border-line bg-surface p-6 sm:p-8">
            <h1 class="text-xl font-semibold text-primary">{{ __('operator.auth.title') }}</h1>
            <p class="mt-1 text-sm text-muted">{{ __('operator.auth.lead') }}</p>

            @if (session('status'))
                <x-account.notice tone="positive" class="mt-5">{{ session('status') }}</x-account.notice>
            @endif

            <form method="POST" action="{{ route('operator.login.store') }}" class="mt-6 grid gap-5" novalidate>
                @csrf
                <x-form.field name="email" type="email" :label="__('operator.auth.email')" autocomplete="username" required autofocus />
                <x-form.field name="password" type="password" :label="__('operator.auth.password')" autocomplete="current-password" required />
                <x-ui.button type="submit" size="lg" class="w-full">{{ __('operator.auth.submit') }}</x-ui.button>
            </form>
        </div>

        <p class="on-dark mt-6 text-center text-sm">
            <a href="{{ route('login') }}" class="rounded-sm text-on-dark-muted underline underline-offset-4 hover:text-on-dark">{{ __('operator.auth.customer_login') }}</a>
        </p>
    </main>
</body>
</html>
