@props(['entries'])

<x-ui.section labelledby="activity-title">
    <div class="grid gap-10 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-16">
        <x-ui.section-heading
            id="activity-title"
            :eyebrow="__('home.activity.eyebrow')"
            :title="__('home.activity.title')"
            :lead="__('home.activity.lead')"
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
