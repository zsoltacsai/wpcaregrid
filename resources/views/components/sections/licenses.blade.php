@props(['license'])

<x-ui.section labelledby="licenses-title">
    <div class="grid items-center gap-10 lg:grid-cols-2 lg:gap-16">
        <div>
            <x-ui.section-heading
                id="licenses-title"
                :eyebrow="__('home.licenses.eyebrow')"
                :title="__('home.licenses.title')"
                :lead="__('home.licenses.lead')"
            />

            <p class="mt-4 max-w-2xl text-sm text-muted">{{ __('home.licenses.limitation') }}</p>

            <p class="mt-8 flex max-w-2xl items-start gap-3 border-l-2 border-line-strong pl-4 text-sm font-medium text-ink">
                {{ __('home.licenses.responsibility') }}
            </p>
        </div>

        <x-dashboard.license-notice :product="$license['product']" :site="$license['site']" :detected="$license['detected']" />
    </div>
</x-ui.section>
