{{--
    seo: optional App\Content\ContentValues of a "seo.*" section (owner-managed
    title, description, canonical URL, robots, Open Graph text). It only
    changes this page's own tags. preview: a signed owner draft preview.
--}}
@props(['title' => null, 'description' => null, 'seo' => null, 'preview' => false, 'indexable' => false, 'localeForm' => null, 'alternates' => []])

@php
    $title = filled($seo['title'] ?? null) ? $seo['title'] : $title;
    $description = filled($seo['description'] ?? null) ? $seo['description'] : $description;
    $canonicalPath = match (request()->route()?->getName()) {
        'home' => '/'.(string) request()->route('locale').'/',
        'pricing' => '/'.(string) request()->route('locale').'/pricing',
        default => request()->getPathInfo(),
    };
    $canonicalFallback = app(\App\Seo\CanonicalUrl::class)->forPath($canonicalPath);
    $canonicalOverride = $seo['canonical_url'] ?? null;
    $canonical = $canonicalFallback;
    if (filled($canonicalOverride)) {
        if (is_string($canonicalOverride) && hash_equals($canonicalFallback, $canonicalOverride)
            && \App\Content\SafeUrl::isOwnHost($canonicalOverride)) {
            $canonical = $canonicalOverride;
        } elseif (request()->routeIs('home', 'pricing')) {
            \Illuminate\Support\Facades\Log::warning('Ignored SEO canonical override that does not match the localized route.', [
                'route' => request()->route()?->getName(),
                'locale' => app()->getLocale(),
            ]);
        }
    }
    $mayIndex = app(\App\Seo\IndexingPolicy::class)->allowsPublicMarketingIndexing($indexable, request());
    $requestedRobots = in_array($seo['robots'] ?? null, \App\Content\Schema\ContentSchema::ROBOTS, true) ? $seo['robots'] : null;
    $robots = $preview
        ? 'noindex,nofollow'
        : (! $mayIndex
            ? (str_starts_with((string) $requestedRobots, 'noindex,') ? $requestedRobots : 'noindex,nofollow')
            : ($requestedRobots ?? 'index,follow'));
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title }}</title>
        <meta name="description" content="{{ $description }}">
        <meta name="robots" content="{{ str_replace(',', ', ', $robots) }}">
        <link rel="canonical" href="{{ $canonical }}">
        @foreach ($alternates as $language => $url)
            <link rel="alternate" hreflang="{{ $language }}" href="{{ $url }}">
        @endforeach

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

        <x-site.header :locale-form="$localeForm" />

        <main id="main" tabindex="-1" class="overflow-x-clip focus:outline-none">
            {{ $slot }}
        </main>

        <x-site.footer />
    </body>
</html>
