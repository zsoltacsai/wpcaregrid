{{--
    Approved WP CareGrid logo, inlined from design/brand/logo.
    layout: horizontal | stacked   tone: light (for light backgrounds) | dark (for #17212B)
    Set the size with a height class, e.g. class="h-8".
--}}
@props(['layout' => 'horizontal', 'tone' => 'light', 'descriptor' => true])

<span {{ $attributes->merge(['class' => 'inline-block [&>svg]:h-full [&>svg]:w-auto']) }}>
    {!! \App\Support\BrandAsset::logo($layout, $tone, $descriptor) !!}
</span>
