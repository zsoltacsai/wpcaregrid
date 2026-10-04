{{-- Invited operators and owners set their own password. Not indexed; no sign-in happens here. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('owner.invitation.title') }} · WP CareGrid</title>
    <meta name="theme-color" content="#17212B">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen flex-col bg-primary px-4 py-10 font-sans text-ink antialiased">
    <main class="m-auto w-full max-w-[420px]">
        <div class="on-dark mb-6 text-on-dark">
            <x-brand.logo tone="dark" :descriptor="false" class="h-7" />
        </div>

        <div class="rounded-md border border-line bg-surface p-6 sm:p-8">
            <h1 class="text-xl font-semibold text-primary">{{ __('owner.invitation.title') }}</h1>
            <p class="mt-1 text-sm text-muted">{{ __('owner.invitation.lead') }}</p>

            @if ($errors->any())
                <x-account.notice tone="danger" class="mt-5">{{ $errors->first() }}</x-account.notice>
            @endif

            <form method="POST" action="{{ route('staff.activate.store') }}" class="mt-6 grid gap-5" novalidate>
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <x-form.field name="email" type="email" :label="__('account.auth.email')" :value="$email" autocomplete="username" required :readonly="filled($email)" />
                <x-form.field name="password" type="password" :label="__('account.auth.password')" :hint="__('account.auth.password_rules')" autocomplete="new-password" required minlength="12" />
                <x-form.field name="password_confirmation" type="password" :label="__('account.auth.password_confirmation')" autocomplete="new-password" required minlength="12" />
                <x-ui.button type="submit" size="lg" class="w-full">{{ __('owner.invitation.submit') }}</x-ui.button>
            </form>
        </div>
    </main>
</body>
</html>
