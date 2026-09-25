@props(['entries'])

<x-ui.section labelledby="activity-title">
    <div class="grid gap-10 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-16">
        <div>
            <x-ui.section-heading
                id="activity-title"
                eyebrow="Activity log"
                title="Everything is logged."
                lead="Every backup, update and check is recorded with its date and result. You can see what was done on your site, and when."
            />
        </div>

        <x-dashboard.frame title="Activity log" caption="Example activity log entries (demo data).">
            <ul class="divide-y divide-line">
                @foreach ($entries as $entry)
                    <x-activity.row :entry="$entry" />
                @endforeach
            </ul>
        </x-dashboard.frame>
    </div>
</x-ui.section>
