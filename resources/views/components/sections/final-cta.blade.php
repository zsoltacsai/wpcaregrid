<x-ui.section tone="dark" labelledby="cta-title" class="border-t border-primary">
    <div class="flex flex-col gap-8 lg:flex-row lg:items-center lg:justify-between">
        <div class="max-w-2xl">
            <h2 id="cta-title" class="text-[28px] leading-[1.15] font-semibold tracking-[-0.015em] text-on-dark sm:text-[32px] lg:text-[40px]">
                Keep your WordPress maintained.
            </h2>
            <p class="mt-4 text-base text-on-dark-muted sm:text-lg">
                Choose a plan and let us handle the routine technical work.
            </p>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row">
            <x-ui.button href="#pricing" size="lg" icon="arrow-right">View plans</x-ui.button>
            <x-ui.button :href="config('marketing.links.contact')" variant="inverse-outline" size="lg">Contact us</x-ui.button>
        </div>
    </div>
</x-ui.section>
