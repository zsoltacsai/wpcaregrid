<x-layouts.marketing :title="__('home.meta.title')" :description="__('home.meta.description')">
    <x-sections.hero
        :trust-indicators="$content->trustIndicators()"
        :sites="$demo->heroSites()"
        :update-run="$demo->latestUpdateRun()"
    />
    <x-sections.services :services="$content->services()" />
    <x-sections.why-maintenance :problems="$content->problems()" />
    <x-sections.verification :steps="$content->verificationSteps()" />
    <x-sections.pricing :catalog="$pricing" />
    <x-sections.dashboard
        :metrics="$demo->metrics()"
        :sites="$demo->sites()"
        :updates="$demo->pendingUpdates()"
        :licenses="$demo->licenses()"
    />
    <x-sections.licenses :license="$demo->expiredLicense()" />
    <x-sections.woocommerce :flow="$content->woocommerceFlow()" />
    <x-sections.development :services="$content->developmentServices()" />
    <x-sections.how-it-works :steps="$content->steps()" />
    <x-sections.activity-log :entries="$demo->activity()" />
    <x-sections.faq :faqs="$content->faqs()" />
    <x-sections.final-cta />
</x-layouts.marketing>
