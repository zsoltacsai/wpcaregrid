{{--
    seo: optional App\Content\ContentValues of a "seo.*" section (owner-managed
    title, description, canonical URL, robots, Open Graph text). It only
    changes this page's own tags. preview: a signed owner draft preview.
--}}
@props(['title' => null, 'description' => null, 'seo' => null, 'preview' => false])

@php
    $title = filled($seo['title'] ?? null) ? $seo['title'] : $title;
    $description = filled($seo['description'] ?? null) ? $seo['description'] : $description;
    $canonical = filled($seo['canonical_url'] ?? null) && \App\Content\SafeUrl::isOwnHost($seo['canonical_url']) ? $seo['canonical_url'] : url()->current();
    $robots = $preview ? 'noindex,nofollow' : (in_array($seo['robots'] ?? null, \App\Content\Schema\ContentSchema::ROBOTS, true) ? $seo['robots'] : 'index,follow');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title }}</title>
        <meta name="description" content="{{ $description }}">
        @if ($robots !== 'index,follow')
            <meta name="robots" content="{{ str_replace(',', ', ', $robots) }}">
        @endif
        <link rel="canonical" href="{{ $canonical }}">

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="WP CareGrid">
        <meta property="og:title" content="{{ filled($seo['og_title'] ?? null) ? $seo['og_title'] : $title }}">
        <meta property="og:description" content="{{ filled($seo['og_description'] ?? null) ? $seo['og_description'] : $description }}">
        <meta property="og:url" content="{{ $canonical }}">
        <meta property="og:locale" content="{{ str_replace('-', '_', app()->getLocale()) }}">

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
            {{ __('home.skip_link') }}
        </a>

        @if ($preview)
            <p role="status" class="sticky top-0 z-[60] bg-warning-soft px-4 py-2 text-center text-sm font-medium text-warning-ink">
                {{ __('owner.content.preview_banner') }}
            </p>
        @endif

        <x-site.header />

        <main id="main" tabindex="-1" class="overflow-x-clip focus:outline-none">
            {{ $slot }}
        </main>

        <x-site.footer />
    </body>
</html>
