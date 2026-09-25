@props(['catalog'])

<x-ui.section id="pricing" labelledby="pricing-title">
    <x-ui.section-heading
        id="pricing-title"
        eyebrow="Pricing"
        title="Plans and pricing"
        lead="A fixed price per website. Pay monthly, or annually and get two months free. No long-term contract."
    />

    <x-pricing.table :catalog="$catalog" class="mt-10" />
</x-ui.section>
