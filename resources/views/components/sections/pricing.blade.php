@props(['catalog', 'headingLevel' => 'h2'])

<x-ui.section id="pricing" labelledby="pricing-title">
    <x-ui.section-heading
        id="pricing-title"
        :eyebrow="__('home.pricing.eyebrow')"
        :title="__('home.pricing.title')"
        :lead="__('home.pricing.lead')"
        :level="$headingLevel"
    />

    <x-pricing.table :catalog="$catalog" class="mt-10" />
</x-ui.section>
