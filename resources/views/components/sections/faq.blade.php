@props(['content', 'faqs'])

<x-ui.section id="faq" tone="surface" labelledby="faq-title" :lang="$content->fallbackLang()">
    @php
        $contactUrl = $content->url('contact_url');
        $contactParts = parse_url($contactUrl) ?: [];
        $contactHost = strtolower((string) ($contactParts['host'] ?? ''));
        $contactPath = rtrim((string) ($contactParts['path'] ?? ''), '/');
        if ($contactPath === '/contact' && ($contactHost === '' || in_array($contactHost, ['wpcaregrid.com', 'www.wpcaregrid.com'], true))) {
            $contactUrl = config('marketing.links.contact');
        }
    @endphp
    <div class="grid gap-10 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-16">
        <div>
            <x-ui.section-heading id="faq-title" :eyebrow="$content['eyebrow']" :title="$content['title']" />
            <p class="mt-4 text-base text-muted">
                {{ $content['contact_prompt'] }}
                <a href="{{ app(\App\Localization\MarketingRoutes::class)->localize($contactUrl) }}" class="rounded-sm font-medium text-primary underline decoration-line-strong underline-offset-4 hover:decoration-primary">{{ $content['contact_label'] }}</a>.
            </p>
        </div>

        <div x-data="{ active: null }" class="border-t border-line">
            @foreach ($faqs as $faq)
                <x-faq.faq-item id="faq-{{ $loop->iteration }}" :question="$faq['question']">
                    {{-- Sanitised in HomepageContent::faqs() (HtmlSanitizer: paragraphs, emphasis, lists, safe links). --}}
                    {!! $faq['answer'] !!}
                </x-faq.faq-item>
            @endforeach
        </div>
    </div>
</x-ui.section>
