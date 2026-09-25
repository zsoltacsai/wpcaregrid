{{--
    Eyebrow + H2 + optional lead paragraph.
    The `id` is applied to the heading so the section can reference it
    with aria-labelledby.
--}}
@props(['id', 'eyebrow' => null, 'title', 'lead' => null, 'align' => 'left'])

<div {{ $attributes->class(['max-w-2xl', 'mx-auto text-center' => $align === 'center']) }}>
    @if ($eyebrow)
        <p class="mb-4 font-mono text-xs font-medium tracking-[0.08em] text-accent-ink uppercase">{{ $eyebrow }}</p>
    @endif

    <h2 id="{{ $id }}" class="text-[28px] leading-[1.15] font-semibold tracking-[-0.015em] sm:text-[32px] lg:text-[40px]">
        {{ $title }}
    </h2>

    @if ($lead)
        <p class="mt-4 text-base leading-relaxed text-muted sm:text-lg">{{ $lead }}</p>
    @endif
</div>
