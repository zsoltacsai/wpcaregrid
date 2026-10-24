@use('App\Operator\BackupState')
@use('App\Operator\ConnectorState')
@use('App\Connector\HealthStatus')

<x-layouts.operator :title="__('operator.websites.title')">
    <x-operator.page-header :title="__('operator.websites.title')" :lead="__('operator.websites.lead')" />

    @php
        $select = 'h-9 w-full rounded-sm border border-line-strong bg-surface px-2 text-sm text-ink hover:border-muted';
        $labels = ['status' => 'operator.health', 'type' => null, 'backup' => 'operator.backup_states', 'security' => 'operator.security.states', 'connector' => 'operator.connector_states'];
    @endphp

    <form method="GET" action="{{ route('operator.websites.index') }}" role="search" class="rounded-md border border-line bg-surface p-3">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_repeat(5,minmax(0,1fr))]">
            <div class="sm:col-span-2 lg:col-span-1">
                <label for="q" class="block text-xs font-medium text-muted">{{ __('operator.websites.search') }}</label>
                <input id="q" name="q" type="search" value="{{ $filters['q'] }}" maxlength="100" placeholder="{{ __('operator.websites.search_placeholder') }}" class="{{ $select }} mt-1 px-3 placeholder:text-muted">
            </div>
            @foreach ($options as $name => $cases)
                <div>
                    <label for="filter-{{ $name }}" class="block text-xs font-medium text-muted">{{ __('operator.websites.filters.'.$name) }}</label>
                    <select id="filter-{{ $name }}" name="{{ $name }}" class="{{ $select }} mt-1">
                        <option value="">{{ __('operator.websites.all') }}</option>
                        @foreach ($cases as $case)
                            <option value="{{ $case->value }}" @selected($filters[$name] === $case)>{{ $labels[$name] ? __($labels[$name].'.'.$case->value) : $case->label() }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
        </div>
        <div class="mt-3 flex flex-wrap items-end gap-3">
            <div>
                <label for="sort" class="block text-xs font-medium text-muted">{{ __('operator.websites.sort') }}</label>
                <select id="sort" name="sort" class="{{ $select }} mt-1 w-auto">
                    @foreach (\App\Operator\WebsiteDirectory::SORTS as $sort)
                        <option value="{{ $sort }}" @selected($filters['sort'] === $sort)>{{ __('operator.websites.sorts.'.$sort) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="direction" class="sr-only">{{ __('operator.websites.sort') }}</label>
                <select id="direction" name="direction" class="{{ $select }} w-auto">
                    @foreach (['desc', 'asc'] as $direction)
                        <option value="{{ $direction }}" @selected($filters['direction'] === $direction)>{{ __('operator.websites.direction.'.$direction) }}</option>
                    @endforeach
                </select>
            </div>
            <x-ui.button type="submit" size="sm" class="h-9">{{ __('operator.websites.filter') }}</x-ui.button>
            <x-ui.button :href="route('operator.websites.index')" variant="ghost" size="sm" class="h-9">{{ __('operator.websites.reset') }}</x-ui.button>
            <span class="ml-auto self-center font-mono text-xs text-muted">{{ __('operator.websites.results', ['count' => number_format($websites->total())]) }}</span>
        </div>
    </form>

    @if ($websites->isEmpty())
        <p class="mt-4 rounded-md border border-line bg-surface p-6 text-sm text-muted">{{ __('operator.websites.empty') }}</p>
    @else
        @php
            $rows = collect($websites->items())->map(function ($website) use ($security) {
                $health = $website->health_status ?? HealthStatus::Unknown;
                $pending = $website->inventory?->updates_pending;

                return [
                    'website' => $website,
                    'health' => $health,
                    'connector' => ConnectorState::of($website),
                    'backup' => BackupState::fromStatus(\App\Backups\BackupStatus::tryFrom((string) $website->latest_backup_status)),
                    'security' => $security[$website->id],
                    'updates' => $website->inventory ? ($pending > 0 ? __('operator.websites.updates_pending', ['count' => $pending]) : __('operator.websites.no_updates')) : __('operator.websites.no_inventory'),
                    'pending' => (int) $pending,
                    'last_seen' => $website->activeConnector?->last_seen_at ?? $website->last_seen_at,
                    'customer' => $website->customer,
                ];
            });
        @endphp

        {{-- Tablet and desktop: a table. --}}
        <div class="mt-4 hidden relative overflow-x-auto rounded-md border border-line bg-surface md:block">
            <table class="w-full min-w-[960px] text-left text-sm">
                <thead class="bg-canvas text-[11px] font-semibold tracking-[0.08em] text-muted uppercase">
                    <tr>
                        @foreach (['website', 'customer', 'type', 'status', 'connector', 'backup', 'security', 'updates', 'last_seen'] as $column)
                            <th scope="col" class="px-3 py-2">{{ __('operator.websites.columns.'.$column) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($rows as $row)
                        <tr class="hover:bg-canvas/60">
                            <td class="px-3 py-2">
                                <a href="{{ route('operator.websites.show', $row['website']) }}" class="font-medium whitespace-nowrap text-primary hover:underline">{{ $row['website']->host() }}</a>
                            </td>
                            <td class="max-w-[200px] px-3 py-2">
                                <span class="block truncate text-primary">{{ $row['customer']?->company_name ?: $row['customer']?->fullName() }}</span>
                                <span class="block truncate text-xs text-muted">{{ $row['customer']?->email }}</span>
                            </td>
                            <td class="px-3 py-2 text-xs whitespace-nowrap">{{ $row['website']->type->label() }}</td>
                            <td class="px-3 py-2"><x-ui.status-badge :tone="$row['health']->tone()">{{ $row['health']->label() }}</x-ui.status-badge></td>
                            <td class="px-3 py-2"><x-ui.status-badge :tone="$row['connector']->tone()">{{ $row['connector']->label() }}</x-ui.status-badge></td>
                            <td class="px-3 py-2"><x-ui.status-badge :tone="$row['backup']->tone()">{{ $row['backup']->label() }}</x-ui.status-badge></td>
                            <td class="px-3 py-2"><x-ui.status-badge :tone="$row['security']->state->tone()">{{ $row['security']->state->label() }}</x-ui.status-badge></td>
                            <td @class(['px-3 py-2 text-xs whitespace-nowrap', 'font-medium text-warning-ink' => $row['pending'] > 0, 'text-muted' => $row['pending'] === 0])>{{ $row['updates'] }}</td>
                            <td class="px-3 py-2 font-mono text-xs whitespace-nowrap text-muted">{{ app(\App\Support\DisplayDateTime::class)->format($row['last_seen']) ?? __('operator.never') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Phones: one card per website, nothing wider than the screen. --}}
        <ul class="mt-4 grid grid-cols-1 gap-3 md:hidden">
            @foreach ($rows as $row)
                <li class="min-w-0 rounded-md border border-line bg-surface p-3">
                    <a href="{{ route('operator.websites.show', $row['website']) }}" class="block font-medium break-all text-primary hover:underline">{{ $row['website']->host() }}</a>
                    <p class="truncate text-xs text-muted">{{ $row['customer']?->company_name ?: $row['customer']?->email }} · {{ $row['website']->type->label() }}</p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <x-ui.status-badge :tone="$row['health']->tone()">{{ $row['health']->label() }}</x-ui.status-badge>
                        <x-ui.status-badge :tone="$row['connector']->tone()">{{ $row['connector']->label() }}</x-ui.status-badge>
                        <x-ui.status-badge :tone="$row['backup']->tone()">{{ __('operator.websites.columns.backup') }}: {{ $row['backup']->label() }}</x-ui.status-badge>
                    </div>
                    <p class="mt-2 flex flex-wrap justify-between gap-2 text-xs text-muted">
                        <span>{{ $row['updates'] }}</span>
                        <span class="font-mono">{{ app(\App\Support\DisplayDateTime::class)->format($row['last_seen']) ?? __('operator.never') }}</span>
                    </p>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">{{ $websites->links() }}</div>
    @endif
</x-layouts.operator>
