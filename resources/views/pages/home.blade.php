<x-layouts.marketing :seo="$seo" :preview="$preview ?? false">
    <x-sections.hero
        :content="$content->section('home.hero')"
        :trust-indicators="$content->trustIndicators()"
        :sites="$demo->heroSites()"
        :update-run="$demo->latestUpdateRun()"
    />
    <x-sections.services :content="$content->section('home.services')" :services="$content->services()" />
    <x-sections.why-maintenance :content="$content->section('home.why')" :problems="$content->problems()" />
    <x-sections.verification :content="$content->section('home.update_process')" :steps="$content->verificationSteps()" />
    <x-sections.pricing :intro="$content->section('home.pricing_intro')" :catalog="$pricing" />
    <x-sections.dashboard
        :content="$content->section('home.dashboard')"
        :metrics="$demo->metrics()"
        :sites="$demo->sites()"
        :updates="$demo->pendingUpdates()"
        :licenses="$demo->licenses()"
    />
    <x-sections.licenses :content="$content->section('home.licenses')" :license="$demo->expiredLicense()" />
    <x-sections.woocommerce :content="$content->section('home.woocommerce')" :flow="$content->woocommerceFlow()" />
    <x-sections.development :content="$content->section('home.development')" :services="$content->developmentServices()" />
    <x-sections.how-it-works :content="$content->section('home.how')" :steps="$content->steps()" />
    <x-sections.activity-log :content="$content->section('home.activity')" :entries="$demo->activity()" />
    <x-sections.faq :content="$content->section('home.faq')" :faqs="$content->faqs()" />
    <x-sections.final-cta :content="$content->section('home.final_cta')" />
</x-layouts.marketing>
