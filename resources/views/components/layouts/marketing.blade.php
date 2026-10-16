{{--
    seo: optional App\Content\ContentValues for this page's localized metadata.
    preview: a signed Owner draft preview, always rendered noindex.
--}}
@props(['title' => null, 'description' => null, 'seo' => null, 'preview' => false, 'indexable' => false, 'localeForm' => null, 'alternates' => [], 'structuredDataType' => null, 'faqs' => [], 'faqTitle' => null])

@php
    $analyticsAvailability = app(\App\Marketing\Analytics\MarketingAnalyticsPolicy::class)->forRequest(request());
    $analyticsPageFamily = request()->routeIs('home') ? 'home' : (request()->routeIs('pricing') ? 'pricing' : '');
    $analyticsPageTrackable = $indexable && ! $preview && $analyticsPageFamily !== '';
    $metadata = app(\App\Seo\MarketingMetadataFactory::class)->make(
        request(), $title, $description, $seo, $indexable, $preview, $alternates,
        $structuredDataType, $faqs, $faqTitle,
    );
@endphp

<!DOCTYPE html>
<html lang="{{ $metadata->locale }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $metadata->title }}</title>
        <meta name="description" content="{{ $metadata->description }}">
        <meta name="robots" content="{{ str_replace(',', ', ', $metadata->robots) }}">
        <link rel="canonical" href="{{ $metadata->canonical }}">
        @foreach ($metadata->alternates as $language => $url)
            <link rel="alternate" hreflang="{{ $language }}" href="{{ $url }}">
        @endforeach

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="WP CareGrid">
        <meta property="og:title" content="{{ $metadata->openGraphTitle }}">
        <meta property="og:description" content="{{ $metadata->openGraphDescription }}">
        <meta property="og:url" content="{{ $metadata->canonical }}">
        <meta property="og:locale" content="{{ $metadata->openGraphLocale }}">
        @foreach ($metadata->openGraphAlternates as $locale)
            <meta property="og:locale:alternate" content="{{ $locale === 'hu' ? 'hu_HU' : 'en_US' }}">
        @endforeach
        @if ($metadata->socialImage)
            <meta property="og:image" content="{{ $metadata->socialImage }}">
        @endif
        <meta name="twitter:card" content="{{ $metadata->twitterCard }}">
        <meta name="twitter:title" content="{{ $metadata->openGraphTitle }}">
        <meta name="twitter:description" content="{{ $metadata->openGraphDescription }}">
        @if ($metadata->socialImage)
            <meta name="twitter:image" content="{{ $metadata->socialImage }}">
        @endif
        @if ($metadata->structuredData !== [])
            <script type="application/ld+json">@json($metadata->structuredData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)</script>
        @endif

        <meta name="theme-color" content="#F6F6F2">
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        <link rel="icon" href="{{ asset('favicon-32x32.png') }}" type="image/png" sizes="32x32">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen">
        <div
            data-marketing-analytics
            data-enabled="{{ $analyticsAvailability->enabled && $analyticsPageTrackable ? 'true' : 'false' }}"
            data-measurement-id="{{ $analyticsAvailability->enabled && $analyticsPageTrackable ? $analyticsAvailability->measurementId : '' }}"
            data-consent-version="{{ $analyticsAvailability->consentVersion }}"
            data-page-family="{{ $analyticsPageTrackable ? $analyticsPageFamily : '' }}"
            data-locale="{{ $metadata->locale }}"
            data-canonical-path="{{ parse_url($metadata->canonical, PHP_URL_PATH) }}"
            data-canonical-location="{{ $metadata->canonical }}"
            hidden
            aria-hidden="true"
        ></div>
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

        @unless ($preview)
            <x-marketing.analytics-preferences />
        @endunless

        <x-site.footer />
    </body>
</html>
