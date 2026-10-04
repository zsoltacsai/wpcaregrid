@props(['content', 'license'])

<x-ui.section labelledby="licenses-title" :lang="$content->fallbackLang()">
    <div class="grid items-center gap-10 lg:grid-cols-2 lg:gap-16">
        <div>
            <x-ui.section-heading
                id="licenses-title"
                :eyebrow="$content['eyebrow']"
                :title="$content['title']"
                :lead="$content['description']"
            />

            <p class="mt-4 max-w-2xl text-sm text-muted">{{ $content['limitation'] }}</p>

            <p class="mt-8 flex max-w-2xl items-start gap-3 border-l-2 border-line-strong pl-4 text-sm font-medium text-ink">
                {{ $content['disclaimer'] }}
            </p>
        </div>

        <x-dashboard.license-notice :product="$license['product']" :site="$license['site']" :detected="$license['detected']" />
    </div>
</x-ui.section>
