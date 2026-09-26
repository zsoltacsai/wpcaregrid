@use('App\Http\Controllers\Operator\ActivityController')

<x-layouts.operator :title="__('operator.activity.title')">
    <x-operator.page-header :title="__('operator.activity.title')" :lead="__('operator.activity.lead')" />

    @php $select = 'h-9 rounded-sm border border-line-strong bg-surface px-2 text-sm text-ink hover:border-muted'; @endphp

    <form method="GET" action="{{ route('operator.activity.index') }}" class="flex flex-wrap items-end gap-3 rounded-md border border-line bg-surface p-3">
        <div class="min-w-0 flex-1 basis-48">
            <label for="q" class="block text-xs font-medium text-muted">{{ __('operator.activity.website') }}</label>
            <input id="q" name="q" type="search" value="{{ $q }}" maxlength="100" placeholder="{{ __('operator.activity.search_placeholder') }}" class="{{ $select }} mt-1 w-full px-3 placeholder:text-muted">
        </div>
        <div>
            <label for="group" class="block text-xs font-medium text-muted">{{ __('operator.activity.group') }}</label>
            <select id="group" name="group" class="{{ $select }} mt-1">
                <option value="">{{ __('operator.activity.all') }}</option>
                @foreach (ActivityController::GROUPS as $option)
                    <option value="{{ $option }}" @selected($group === $option)>{{ __('operator.activity.groups.'.$option) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="actor" class="block text-xs font-medium text-muted">{{ __('operator.activity.actor') }}</label>
            <select id="actor" name="actor" class="{{ $select }} mt-1">
                <option value="">{{ __('operator.activity.all') }}</option>
                @foreach (ActivityController::ACTORS as $option)
                    <option value="{{ $option }}" @selected($actor === $option)>{{ __('operator.actors.'.$option) }}</option>
                @endforeach
            </select>
        </div>
        <x-ui.button type="submit" size="sm" class="h-9">{{ __('operator.activity.filter') }}</x-ui.button>
    </form>

    <div class="mt-4 rounded-md border border-line bg-surface">
        @if ($events->isEmpty())
            <p class="p-6 text-sm text-muted">{{ __('operator.activity.empty') }}</p>
        @else
            <x-operator.events-table :events="$events" />
        @endif
    </div>
    <div class="mt-4">{{ $events->links() }}</div>
</x-layouts.operator>
