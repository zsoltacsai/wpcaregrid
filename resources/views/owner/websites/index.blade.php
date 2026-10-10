@use('App\Operator\WebsiteDirectory')

<x-layouts.owner :title="__('owner.websites.title')">
    <x-operator.page-header :title="__('owner.websites.title')" :lead="__('owner.websites.lead')" />

    @php
        $select = 'mt-1 h-9 w-full rounded-sm border border-line-strong bg-surface px-2 text-sm text-ink hover:border-muted';
        $labels = ['type' => null, 'backup' => 'operator.backup_states', 'security' => 'operator.security.states', 'connector' => 'operator.connector_states'];
    @endphp

    <form method="GET" action="{{ route('owner.websites.index') }}" role="search" class="rounded-md border border-line bg-surface p-3">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_repeat(4,minmax(0,1fr))]">
            <div class="sm:col-span-2 lg:col-span-1">
                <label for="q" class="block text-xs font-medium text-muted">{{ __('owner.websites.search') }}</label>
                <input id="q" name="q" type="search" value="{{ $filters['q'] }}" maxlength="100" placeholder="{{ __('owner.websites.search_placeholder') }}" class="{{ $select }} px-3 placeholder:text-muted">
            </div>
            @foreach ($options as $name => $cases)
                <div>
                    <label for="filter-{{ $name }}" class="block text-xs font-medium text-muted">{{ __('owner.websites.filters.'.$name) }}</label>
                    <select id="filter-{{ $name }}" name="{{ $name }}" class="{{ $select }}">
                        <option value="">{{ __('owner.all') }}</option>
                        @foreach ($cases as $case)
                            <option value="{{ $case->value }}" @selected($filters[$name] === $case)>{{ $labels[$name] ? __($labels[$name].'.'.$case->value) : $case->label() }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
        </div>
        <div class="mt-3 flex flex-wrap items-end gap-3">
            <div>
                <label for="sort" class="block text-xs font-medium text-muted">{{ __('owner.websites.sort') }}</label>
                <select id="sort" name="sort" class="{{ $select }} w-auto">
                    @foreach (WebsiteDirectory::SORTS as $sort)
                        <option value="{{ $sort }}" @selected($filters['sort'] === $sort)>{{ __('operator.websites.sorts.'.$sort) }}</option>
                    @endforeach
                </select>
            </div>
            <x-ui.button type="submit" size="sm" class="h-9">{{ __('owner.filter') }}</x-ui.button>
            <x-ui.button :href="route('owner.websites.index')" variant="ghost" size="sm" class="h-9">{{ __('owner.reset') }}</x-ui.button>
            <span class="ml-auto self-center font-mono text-xs text-muted">{{ __('owner.results', ['count' => number_format($websites->total())]) }}</span>
        </div>
    </form>

    @if ($websites->isEmpty())
        <p class="mt-4 rounded-md border border-line bg-surface p-6 text-sm text-muted">{{ __('owner.websites.empty') }}</p>
    @else
        @php
            $rows = collect($websites->items())->map(fn ($website) => [
                'website' => $website,
                'report' => $health[$website->id],
                'connector' => \App\Operator\ConnectorState::of($website),
                'backup' => \App\Operator\BackupState::fromStatus(\App\Backups\BackupStatus::tryFrom((string) $website->latest_backup_status)),
                'security' => $security[$website->id],
                'pending' => $website->inventory?->updates_pending,
                'last_seen' => $website->activeConnector?->last_seen_at ?? $website->last_seen_at,
            ]);
        @endphp

        <div class="relative mt-4 hidden overflow-x-auto rounded-md border border-line bg-surface md:block">
            <table class="w-full min-w-[1000px] text-left text-sm">
                <thead class="bg-canvas text-[11px] font-semibold tracking-[0.08em] text-muted uppercase">
                    <tr>
                        @foreach (['url', 'customer', 'type', 'plan', 'health', 'connector', 'backup', 'security', 'updates', 'last_seen'] as $column)
                            <th scope="col" class="px-3 py-2">{{ __('owner.websites.columns.'.$column) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($rows as $row)
                        <tr class="hover:bg-canvas/60">
                            <td class="px-3 py-2"><a href="{{ route('owner.websites.show', $row['website']) }}" class="font-medium whitespace-nowrap text-primary hover:underline">{{ $row['website']->host() }}</a></td>
                            <td class="max-w-[180px] px-3 py-2">
                                @if ($row['website']->customer)
                                    <a href="{{ route('owner.customers.show', $row['website']->customer) }}" class="block truncate text-primary hover:underline">{{ $row['website']->customer->company_name ?: $row['website']->customer->fullName() }}</a>
                                @endif
                                @if ($row['website']->synthetic_fixture_exists)<x-ui.status-badge tone="warning">{{ __('owner.synthetic_fixture_badge') }}</x-ui.status-badge>@endif
                            </td>
                            <td class="px-3 py-2 text-xs whitespace-nowrap">{{ $row['website']->type->label() }}</td>
                            <td class="px-3 py-2 text-xs whitespace-nowrap">{{ $row['website']->subscription ? $row['website']->subscription->tierLabel() : '—' }}</td>
                            <td class="px-3 py-2"><x-ui.status-badge :tone="$row['report']->overall->tone()">{{ $row['report']->overall->label() }}</x-ui.status-badge></td>
                            <td class="px-3 py-2"><x-ui.status-badge :tone="$row['connector']->tone()">{{ $row['connector']->label() }}</x-ui.status-badge></td>
                            <td class="px-3 py-2"><x-ui.status-badge :tone="$row['backup']->tone()">{{ $row['backup']->label() }}</x-ui.status-badge></td>
                            <td class="px-3 py-2"><x-ui.status-badge :tone="$row['security']->state->tone()">{{ $row['security']->state->label() }}</x-ui.status-badge></td>
                            <td class="px-3 py-2 text-xs whitespace-nowrap">{{ $row['pending'] === null ? '—' : $row['pending'] }}</td>
                            <td class="px-3 py-2 font-mono text-xs whitespace-nowrap text-muted">{{ $row['last_seen']?->format('Y-m-d H:i') ?? __('owner.never') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <ul class="mt-4 grid grid-cols-1 gap-3 md:hidden">
            @foreach ($rows as $row)
                <li class="min-w-0 rounded-md border border-line bg-surface p-3">
                    <a href="{{ route('owner.websites.show', $row['website']) }}" class="block font-medium break-all text-primary hover:underline">{{ $row['website']->host() }}</a>
                    <p class="truncate text-xs text-muted">{{ $row['website']->customer?->company_name ?: $row['website']->customer?->email }} · {{ $row['website']->type->label() }}</p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @if ($row['website']->synthetic_fixture_exists)<x-ui.status-badge tone="warning">{{ __('owner.synthetic_fixture_badge') }}</x-ui.status-badge>@endif
                        <x-ui.status-badge :tone="$row['report']->overall->tone()">{{ $row['report']->overall->label() }}</x-ui.status-badge>
                        <x-ui.status-badge :tone="$row['connector']->tone()">{{ $row['connector']->label() }}</x-ui.status-badge>
                        <x-ui.status-badge :tone="$row['backup']->tone()">{{ __('owner.websites.columns.backup') }}: {{ $row['backup']->label() }}</x-ui.status-badge>
                    </div>
                    <p class="mt-2 font-mono text-xs text-muted">{{ $row['last_seen']?->format('Y-m-d H:i') ?? __('owner.never') }}</p>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">{{ $websites->links() }}</div>
    @endif
</x-layouts.owner>
