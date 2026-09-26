{{-- Audit entries in a table that scrolls on its own on small screens (never the page). --}}
@props(['events', 'showWebsite' => true])

<div {{ $attributes->merge(['class' => 'relative overflow-x-auto']) }}>
    <table class="w-full min-w-[640px] text-left">
        <thead class="text-[11px] font-semibold tracking-[0.08em] text-muted uppercase">
            <tr class="border-b border-line">
                <th scope="col" class="px-3 py-2">{{ __('operator.activity.columns.time') }}</th>
                @if ($showWebsite)<th scope="col" class="px-3 py-2">{{ __('operator.activity.columns.website') }}</th>@endif
                <th scope="col" class="px-3 py-2">{{ __('operator.activity.columns.actor') }}</th>
                <th scope="col" class="px-3 py-2">{{ __('operator.activity.columns.event') }}</th>
                <th scope="col" class="px-3 py-2">{{ __('operator.activity.columns.result') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-line">
            @foreach ($events as $event)
                <x-operator.event-row :event="$event" :show-website="$showWebsite" />
            @endforeach
        </tbody>
    </table>
</div>
