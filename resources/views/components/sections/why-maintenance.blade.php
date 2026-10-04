@props(['content', 'problems'])

<x-ui.section labelledby="why-title" :lang="$content->fallbackLang()">
    <div class="grid gap-10 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-16">
        <x-ui.section-heading
            id="why-title"
            :eyebrow="$content['eyebrow']"
            :title="$content['title']"
            :lead="$content['body']"
        />

        <ul class="grid gap-4 md:grid-cols-3 lg:grid-cols-1">
            @foreach ($problems as $problem)
                <li class="flex flex-col gap-4 rounded-md border border-line bg-surface p-6 lg:flex-row lg:items-start">
                    <x-ui.icon-tile :name="$problem['icon']" :tone="$problem['tone']" />
                    <div>
                        <h3 class="text-base font-semibold">{{ $problem['title'] }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-muted">{{ $problem['text'] }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
</x-ui.section>
