@props(['faqs'])

<x-ui.section id="faq" tone="surface" labelledby="faq-title">
    <div class="grid gap-10 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-16">
        <div>
            <x-ui.section-heading id="faq-title" :eyebrow="__('home.faq.eyebrow')" :title="__('home.faq.title')" />
            <p class="mt-4 text-base text-muted">
                {{ __('home.faq.contact_prompt') }}
                <a href="{{ config('marketing.links.contact') }}" class="rounded-sm font-medium text-primary underline decoration-line-strong underline-offset-4 hover:decoration-primary">{{ __('home.faq.contact_link') }}</a>.
            </p>
        </div>

        <div x-data="{ active: null }" class="border-t border-line">
            @foreach ($faqs as $faq)
                <x-faq.faq-item id="faq-{{ $loop->iteration }}" :question="$faq['question']">
                    {{ $faq['answer'] }}
                </x-faq.faq-item>
            @endforeach
        </div>
    </div>
</x-ui.section>
