@props(['intro', 'catalog', 'headingLevel' => 'h2'])

<x-ui.section id="pricing" labelledby="pricing-title" :lang="$intro->fallbackLang()">
    <x-ui.section-heading
        id="pricing-title"
        :eyebrow="$intro['eyebrow']"
        :title="$intro['title']"
        :lead="$intro['description']"
        :level="$headingLevel"
    />

    <x-pricing.table :catalog="$catalog" class="mt-10" />
</x-ui.section>
