@props(['content', 'entries'])

<x-ui.section labelledby="activity-title" :lang="$content->fallbackLang()">
    <div class="grid gap-10 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-16">
        <x-ui.section-heading
            id="activity-title"
            :eyebrow="$content['eyebrow']"
            :title="$content['title']"
            :lead="$content['description']"
        />

        <x-dashboard.frame :title="__('home.activity.frame_title')" :caption="__('home.activity.caption')">
            <ul class="divide-y divide-line">
                @foreach ($entries as $entry)
                    <x-activity.activity-row :entry="$entry" />
                @endforeach
            </ul>
        </x-dashboard.frame>
    </div>
</x-ui.section>
