{{--
    Eyebrow + H2 + optional lead paragraph.
    The `id` is applied to the heading so the section can reference it
    with aria-labelledby.
--}}
@props(['id', 'eyebrow' => null, 'title', 'lead' => null, 'align' => 'left', 'level' => 'h2'])

<div {{ $attributes->class(['max-w-2xl', 'mx-auto text-center' => $align === 'center']) }}>
    @if ($eyebrow)
        <p class="mb-4 text-xs font-semibold tracking-[0.12em] text-muted uppercase">{{ $eyebrow }}</p>
    @endif

    <{{ $level }} id="{{ $id }}" class="text-[28px] leading-[1.15] font-semibold tracking-[-0.015em] sm:text-[32px] lg:text-[40px]">
        {{ $title }}
    </{{ $level }}>

    @if ($lead)
        <p class="mt-4 text-base leading-relaxed text-muted sm:text-lg">{{ $lead }}</p>
    @endif
</div>
