<x-ui.section labelledby="licenses-title">
    <div class="grid items-center gap-10 lg:grid-cols-2 lg:gap-16">
        <div>
            <x-ui.section-heading
                id="licenses-title"
                eyebrow="License management"
                title="Expired licenses don't go unnoticed."
                lead="Premium plugins and themes stop receiving updates when their license runs out. We check license status as part of maintenance and tell you when one needs renewing."
            />

            <p class="mt-8 flex max-w-2xl items-start gap-3 border-l-2 border-line-strong pl-4 text-sm text-ink">
                Third-party plugin and theme licenses remain the customer's responsibility.
            </p>
        </div>

        <x-dashboard.license-notice product="WP Rocket" site="petplaza.hu" detected="24 Sep 2026" />
    </div>
</x-ui.section>
