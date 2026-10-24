<x-layouts.owner :title="__('owner.customers.title')">
    <x-operator.page-header :title="__('owner.customers.title')" :lead="__('owner.customers.lead')" />

    <div class="mb-4 flex justify-end">
        <x-ui.button :href="route('owner.vat-verifications.index')" variant="secondary" size="sm">
            {{ __('owner.customers.vat.queue') }}@if ($openVatReviewCount > 0) <span class="ml-1">({{ $openVatReviewCount }})</span>@endif
        </x-ui.button>
    </div>

    @php $select = 'mt-1 h-9 w-full rounded-sm border border-line-strong bg-surface px-2 text-sm text-ink hover:border-muted'; @endphp

    <form method="GET" action="{{ route('owner.customers.index') }}" role="search" class="rounded-md border border-line bg-surface p-3">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_repeat(4,minmax(0,1fr))]">
            <div class="sm:col-span-2 lg:col-span-1">
                <label for="q" class="block text-xs font-medium text-muted">{{ __('owner.customers.search') }}</label>
                <input id="q" name="q" type="search" value="{{ $filters['q'] }}" maxlength="100" placeholder="{{ __('owner.customers.search_placeholder') }}" class="{{ $select }} px-3 placeholder:text-muted">
            </div>
            <div>
                <label for="filter-subscription" class="block text-xs font-medium text-muted">{{ __('owner.customers.filters.subscription') }}</label>
                <select id="filter-subscription" name="subscription" class="{{ $select }}">
                    <option value="">{{ __('owner.all') }}</option>
                    <option value="none" @selected($filters['subscription'] === 'none')>{{ __('owner.customers.no_subscription') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected($filters['subscription'] === $status)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filter-country" class="block text-xs font-medium text-muted">{{ __('owner.customers.filters.country') }}</label>
                <select id="filter-country" name="country" class="{{ $select }}">
                    <option value="">{{ __('owner.all') }}</option>
                    @foreach ($countries as $code => $name)
                        <option value="{{ $code }}" @selected($filters['country'] === $code)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filter-type" class="block text-xs font-medium text-muted">{{ __('owner.customers.filters.type') }}</label>
                <select id="filter-type" name="type" class="{{ $select }}">
                    <option value="">{{ __('owner.all') }}</option>
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}" @selected($filters['type'] === $type)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filter-plan" class="block text-xs font-medium text-muted">{{ __('owner.customers.filters.plan') }}</label>
                <select id="filter-plan" name="plan" class="{{ $select }}">
                    <option value="">{{ __('owner.all') }}</option>
                    @foreach ($plans as $plan)
                        <option value="{{ $plan->value }}" @selected($filters['plan'] === $plan)>{{ __('owner.tiers.'.$plan->value) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="mt-3 flex flex-wrap items-center gap-3">
            <x-ui.button type="submit" size="sm" class="h-9">{{ __('owner.filter') }}</x-ui.button>
            <x-ui.button :href="route('owner.customers.index')" variant="ghost" size="sm" class="h-9">{{ __('owner.reset') }}</x-ui.button>
            <span class="ml-auto font-mono text-xs text-muted">{{ __('owner.results', ['count' => number_format($customers->total())]) }}</span>
        </div>
    </form>

    @if ($customers->isEmpty())
        <p class="mt-4 rounded-md border border-line bg-surface p-6 text-sm text-muted">{{ __('owner.customers.empty') }}</p>
    @else
        <div class="relative mt-4 hidden overflow-x-auto rounded-md border border-line bg-surface md:block">
            <table class="w-full min-w-[880px] text-left text-sm">
                <thead class="bg-canvas text-[11px] font-semibold tracking-[0.08em] text-muted uppercase">
                    <tr>
                        @foreach (['customer', 'email', 'company', 'country', 'subscription', 'websites', 'status', 'created'] as $column)
                            <th scope="col" class="px-3 py-2">{{ __('owner.customers.columns.'.$column) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($customers as $customer)
                        @php $subscription = $customer->subscriptions->first(fn ($s) => $s->grantsAccess()) ?? $customer->subscriptions->first(); @endphp
                        <tr class="hover:bg-canvas/60">
                            <td class="max-w-[180px] px-3 py-2"><a href="{{ route('owner.customers.show', $customer) }}" class="block truncate font-medium text-primary hover:underline">{{ $customer->fullName() }}</a>@if ($customer->synthetic_fixture_exists)<x-ui.status-badge tone="warning">{{ __('owner.synthetic_fixture_badge') }}</x-ui.status-badge>@endif</td>
                            <td class="max-w-[200px] truncate px-3 py-2 text-muted">{{ $customer->email }}</td>
                            <td class="max-w-[160px] truncate px-3 py-2">{{ $customer->company_name ?: '—' }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $customer->country_code }}</td>
                            <td class="px-3 py-2 text-xs whitespace-nowrap">
                                @if ($subscription)
                                    {{ $subscription->product_type->label() }} {{ $subscription->tierLabel() }}
                                    <x-ui.status-badge :status="$subscription->status" class="ml-1" />
                                @else
                                    <span class="text-muted">{{ __('owner.customers.no_subscription') }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 tabular-nums">{{ $customer->websites_count }}</td>
                            <td class="px-3 py-2"><x-ui.status-badge :tone="$customer->active ? 'positive' : 'neutral'">{{ __('owner.customers.states.'.($customer->active ? 'active' : 'inactive')) }}</x-ui.status-badge></td>
                            <td class="px-3 py-2 font-mono text-xs whitespace-nowrap text-muted">{{ app(\App\Support\DisplayDateTime::class)->date($customer->created_at) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <ul class="mt-4 grid grid-cols-1 gap-3 md:hidden">
            @foreach ($customers as $customer)
                @php $subscription = $customer->subscriptions->first(fn ($s) => $s->grantsAccess()) ?? $customer->subscriptions->first(); @endphp
                <li class="min-w-0 rounded-md border border-line bg-surface p-3">
                    <a href="{{ route('owner.customers.show', $customer) }}" class="block truncate font-medium text-primary hover:underline">{{ $customer->fullName() }}</a>
                    <p class="truncate text-xs text-muted">{{ $customer->email }}</p>
                    <p class="truncate text-xs text-muted">{{ $customer->company_name ?: '—' }} · {{ $customer->country_code }}</p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @if ($customer->synthetic_fixture_exists)<x-ui.status-badge tone="warning">{{ __('owner.synthetic_fixture_badge') }}</x-ui.status-badge>@endif
                        @if ($subscription)
                            <x-ui.status-badge :status="$subscription->status">{{ $subscription->product_type->label() }} {{ $subscription->tierLabel() }} · {{ $subscription->status->label() }}</x-ui.status-badge>
                        @endif
                        <x-ui.status-badge tone="neutral">{{ trans_choice('owner.customers.website_count', $customer->websites_count) }}</x-ui.status-badge>
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">{{ $customers->links() }}</div>
    @endif
</x-layouts.owner>
