@props([
    'title' => 'WP CareGrid — WordPress maintenance',
    'description' => 'WordPress and WooCommerce maintenance, backups, security checks and updates — with functional checks after important updates.',
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title }}</title>
        <meta name="description" content="{{ $description }}">
        <link rel="canonical" href="{{ url()->current() }}">

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="WP CareGrid">
        <meta property="og:title" content="{{ $title }}">
        <meta property="og:description" content="{{ $description }}">
        <meta property="og:url" content="{{ url()->current() }}">

        <meta name="theme-color" content="#F6F6F2">
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        <link rel="icon" href="{{ asset('favicon-32x32.png') }}" type="image/png" sizes="32x32">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen">
        <a
            href="#main"
            class="sr-only rounded-sm bg-primary px-4 py-2 text-sm font-medium text-white focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[60]"
        >
            Skip to content
        </a>

        <x-site.header />

        <main id="main" tabindex="-1" class="overflow-x-clip focus:outline-none">
            {{ $slot }}
        </main>

        <x-site.footer />
    </body>
</html>
