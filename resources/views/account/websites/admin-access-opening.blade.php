{{--
    Hands the one-time token to the website in a POST body (not a URL) and
    leaves. Not cached, not framed, no referrer (see the controller).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('admin_access.opening.title') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-canvas px-4 font-sans text-ink antialiased">
    <main class="w-full max-w-md rounded-lg border border-line bg-surface p-8 text-center">
        <x-brand.icon class="mx-auto size-10" />
        <h1 class="mt-6 text-xl font-semibold text-primary">{{ __('admin_access.opening.title') }}</h1>
        <p class="mt-2 text-sm text-muted">{{ __('admin_access.opening.text', ['host' => $website->host()]) }}</p>

        <form id="wp-caregrid-access" method="POST" action="{{ $action }}" class="mt-6">
            <input type="hidden" name="request" value="{{ $requestUuid }}">
            <input type="hidden" name="token" value="{{ $token }}">
            <noscript><p class="mb-3 text-sm">{{ __('admin_access.opening.noscript') }}</p></noscript>
            <x-ui.button type="submit">{{ __('admin_access.opening.continue') }}</x-ui.button>
        </form>
    </main>
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">document.getElementById('wp-caregrid-access').submit();</script>
</body>
</html>
