<x-layouts.owner :title="$customer->fullName()">
    <x-operator.page-header :title="$customer->fullName()" :lead="$customer->company_name" :eyebrow="__('owner.customers.title')">
        <x-slot:actions>
            <x-ui.status-badge :tone="$customer->active ? 'positive' : 'neutral'">{{ __('owner.customers.states.'.($customer->active ? 'active' : 'inactive')) }}</x-ui.status-badge>
        </x-slot:actions>
    </x-operator.page-header>

    @php $address = (array) $customer->billing_address; @endphp

    <div class="grid gap-4 lg:grid-cols-2">
        <x-operator.section :title="__('owner.customers.sections.customer')" id="customer-info">
            <dl class="grid grid-cols-[minmax(0,140px)_minmax(0,1fr)] gap-x-3 gap-y-2 text-sm">
                <dt class="text-muted">{{ __('owner.customers.fields.email') }}</dt><dd class="break-all text-primary">{{ $customer->email }}</dd>
                <dt class="text-muted">{{ __('owner.customers.fields.type') }}</dt><dd>{{ $customer->customer_type ? __('owner.customers.types.'.$customer->customer_type) : '—' }}</dd>
                <dt class="text-muted">{{ __('owner.customers.fields.login') }}</dt><dd>{{ $customer->user?->hasVerifiedEmail() ? __('owner.customers.login_active') : __('owner.customers.login_pending') }}</dd>
                <dt class="text-muted">{{ __('owner.customers.fields.created') }}</dt><dd class="font-mono text-xs">{{ $customer->created_at->format('Y-m-d H:i') }}</dd>
            </dl>
        </x-operator.section>

        <x-operator.section :title="__('owner.customers.sections.billing')" id="customer-billing">
            <dl class="grid grid-cols-[minmax(0,140px)_minmax(0,1fr)] gap-x-3 gap-y-2 text-sm">
                <dt class="text-muted">{{ __('owner.customers.fields.company') }}</dt><dd class="break-words">{{ $customer->company_name ?: '—' }}</dd>
                <dt class="text-muted">{{ __('owner.customers.fields.tax_id') }}</dt><dd class="font-mono text-xs break-all">{{ $customer->tax_id ?: '—' }}</dd>
                <dt class="text-muted">{{ __('owner.customers.fields.country') }}</dt><dd>{{ $countries[$customer->country_code] ?? $customer->country_code }}</dd>
                <dt class="text-muted">{{ __('owner.customers.fields.address') }}</dt>
                <dd class="break-words">{{ collect([$address['line1'] ?? null, $address['line2'] ?? null, trim(($address['postal_code'] ?? '').' '.($address['city'] ?? ''))])->filter()->implode(', ') ?: '—' }}</dd>
                {{-- A reference for looking the customer up in Stripe; no payment data is stored or shown. --}}
                <dt class="text-muted">{{ __('owner.customers.fields.stripe_customer') }}</dt><dd class="font-mono text-xs break-all">{{ $customer->stripe_customer_id ?: '—' }}</dd>
            </dl>
        </x-operator.section>
    </div>

    <x-operator.section :title="__('owner.customers.sections.subscriptions')" id="customer-subscriptions" class="mt-4">
        @if ($customer->subscriptions->isEmpty())
            <p class="text-sm text-muted">{{ __('owner.customers.no_subscription') }}</p>
        @else
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[620px] text-left text-sm">
                    <thead class="text-[11px] font-semibold tracking-[0.08em] text-muted uppercase">
                        <tr class="border-b border-line">
                            @foreach (['plan', 'interval', 'amount', 'status', 'renews', 'website'] as $column)
                                <th scope="col" class="px-2 py-2">{{ __('owner.customers.subscription_columns.'.$column) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($customer->subscriptions as $subscription)
                            <tr>
                                <td class="px-2 py-2">{{ $subscription->product_type->label() }} {{ $subscription->planName() }}</td>
                                <td class="px-2 py-2">{{ $subscription->interval->label() }}</td>
                                <td class="px-2 py-2 tabular-nums">{{ $subscription->amount !== null && $subscription->currency ? $subscription->money()->format() : '—' }}</td>
                                <td class="px-2 py-2"><x-ui.status-badge :status="$subscription->status" /></td>
                                <td class="px-2 py-2 font-mono text-xs">{{ $subscription->nextBillingDate()?->format('Y-m-d') ?? ($subscription->cancel_at_period_end ? __('owner.customers.cancels') : '—') }}</td>
                                <td class="px-2 py-2 text-xs">{{ $customer->websites->firstWhere('subscription_id', $subscription->id)?->host() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-muted">{{ __('owner.customers.stripe_note') }}</p>
        @endif
    </x-operator.section>

    <x-operator.section :title="__('owner.customers.sections.websites')" id="customer-websites" class="mt-4">
        @if ($customer->websites->isEmpty())
            <p class="text-sm text-muted">{{ __('owner.customers.no_websites') }}</p>
        @else
            <ul class="divide-y divide-line">
                @foreach ($customer->websites as $website)
                    @php $report = $health[$website->id]; @endphp
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <a href="{{ route('owner.websites.show', $website) }}" class="min-w-0 font-medium break-all text-primary hover:underline">{{ $website->host() }}</a>
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="text-xs text-muted">{{ $website->type->label() }}</span>
                            <x-ui.status-badge :tone="$report->overall->tone()">{{ $report->overall->label() }}</x-ui.status-badge>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-operator.section>

    <x-operator.section :title="__('owner.customers.sections.edit')" id="customer-edit" class="mt-4">
        <p class="mb-4 text-sm text-muted">{{ __('owner.customers.edit_lead') }}</p>
        <form method="POST" action="{{ route('owner.customers.update', $customer) }}" class="grid gap-4 md:grid-cols-2" novalidate>
            @csrf
            @method('PUT')
            <x-form.field name="first_name" :label="__('owner.customers.fields.first_name')" :value="$customer->first_name" required maxlength="100" />
            <x-form.field name="last_name" :label="__('owner.customers.fields.last_name')" :value="$customer->last_name" required maxlength="100" />
            <x-form.select name="customer_type" :label="__('owner.customers.fields.type')" :options="['individual' => __('owner.customers.types.individual'), 'company' => __('owner.customers.types.company')]" :selected="$customer->customer_type ?? 'individual'" />
            <x-form.field name="company_name" :label="__('owner.customers.fields.company')" :value="$customer->company_name" maxlength="150" :optional="true" />
            <x-form.select name="country_code" :label="__('owner.customers.fields.country')" :options="$countries" :selected="$customer->country_code" :hint="__('owner.customers.country_hint')" />
            <x-form.field name="tax_id" :label="__('owner.customers.fields.tax_id')" :value="$customer->tax_id" maxlength="50" :optional="true" />
            <x-form.field name="address_line1" :label="__('owner.customers.fields.address_line1')" :value="$address['line1'] ?? null" maxlength="150" :optional="true" />
            <x-form.field name="address_line2" :label="__('owner.customers.fields.address_line2')" :value="$address['line2'] ?? null" maxlength="150" :optional="true" />
            <x-form.field name="postal_code" :label="__('owner.customers.fields.postal_code')" :value="$address['postal_code'] ?? null" maxlength="20" :optional="true" />
            <x-form.field name="city" :label="__('owner.customers.fields.city')" :value="$address['city'] ?? null" maxlength="100" :optional="true" />
            <div class="md:col-span-2">
                <x-ui.button type="submit">{{ __('owner.customers.save') }}</x-ui.button>
            </div>
        </form>
    </x-operator.section>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <x-operator.section :title="__('owner.customers.sections.activity')" id="customer-activity">
            <x-owner.event-list :events="$events" />
        </x-operator.section>
        <x-operator.section :title="__('owner.customers.sections.changes')" id="customer-changes">
            <x-owner.audit-list :events="$audit" />
        </x-operator.section>
    </div>
</x-layouts.owner>
