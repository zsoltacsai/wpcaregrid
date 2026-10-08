@php echo '<?xml version="1.0" encoding="UTF-8"?>'; @endphp
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($entries as $entry)
    <url>
        <loc>{{ $entry['location'] }}</loc>
        @foreach ($entry['alternates'] as $locale => $href)
            @php echo '<xhtml:link rel="alternate" hreflang="'.e($locale).'" href="'.e($href).'" />'; @endphp
        @endforeach
    </url>
@endforeach
</urlset>
