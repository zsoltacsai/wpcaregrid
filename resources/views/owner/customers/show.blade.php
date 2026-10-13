<x-layouts.owner :title="$customer->fullName()">
    <x-operator.page-header :title="$customer->fullName()" :lead="$customer->company_name" :eyebrow="__('owner.customers.title')">
        <x-slot:actions>
            @if ($customer->syntheticFixture)<x-ui.status-badge tone="warning">{{ __('owner.synthetic_fixture_badge') }}</x-ui.status-badge>@endif
            @if (! $customer->syntheticFixture && $customer->active && $customer->websites->isNotEmpty() && strtoupper($customer->country_code) === 'HU')
                <x-ui.button :href="route('owner.offers.create', ['customer' => $customer->uuid])" variant="secondary" size="sm">{{ __('owner.offers.new') }}</x-ui.button>
            @endif
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
                <dt class="text-muted">{{ __('owner.customers.fields.tax_id') }}</dt><dd class="font-mono text-xs break-all">{{ $maskedVatIdentifier }}</dd>
                <dt class="text-muted">{{ __('owner.customers.fields.country') }}</dt><dd>{{ $countries[$customer->country_code] ?? $customer->country_code }}</dd>
                <dt class="text-muted">{{ __('owner.customers.fields.address') }}</dt>
                <dd class="break-words">{{ collect([$address['line1'] ?? null, $address['line2'] ?? null, trim(($address['postal_code'] ?? '').' '.($address['city'] ?? ''))])->filter()->implode(', ') ?: '—' }}</dd>
                {{-- A reference for looking the customer up in Stripe; no payment data is stored or shown. --}}
                <dt class="text-muted">{{ __('owner.customers.fields.stripe_customer') }}</dt><dd class="font-mono text-xs break-all">{{ $customer->stripe_customer_id ?: '—' }}</dd>
            </dl>
        </x-operator.section>
    </div>

    <x-operator.section :title="__('owner.customers.vat.title')" id="customer-vat-verification" class="mt-4">
        <p class="mb-3 text-xs text-muted">{{ __('owner.customers.vat.evidence_only') }}</p>
        @if (session('vat_recheck_status'))
            @php $recheckStatus = match (session('vat_recheck_status')) { 'valid' => 'valid', 'invalid', 'malformed' => 'invalid', 'unavailable' => 'unavailable', default => 'pending_review' }; @endphp
            <p role="status" class="mb-3 rounded-sm border border-line bg-canvas p-3 text-sm">{{ __('owner.customers.vat.statuses.'.$recheckStatus) }}</p>
        @endif
        @if ($billingIdentity)
            <dl class="grid grid-cols-[minmax(0,150px)_minmax(0,1fr)] gap-x-3 gap-y-2 text-sm">
                <dt class="text-muted">{{ __('owner.customers.vat.status') }}</dt>
                <dd>{{ $latestVatValidation ? __('owner.customers.vat.statuses.'.match ($latestVatValidation->status->value) { 'valid' => 'valid', 'invalid', 'malformed' => 'invalid', 'unavailable' => 'unavailable', default => 'pending_review' }) : __('owner.customers.vat.statuses.not_verified') }}</dd>
                <dt class="text-muted">{{ __('owner.customers.vat.country') }}</dt><dd>{{ $latestVatValidation?->country_code ?? $billingIdentity->country_code }}</dd>
                <dt class="text-muted">{{ __('owner.customers.vat.identifier') }}</dt><dd class="font-mono text-xs">{{ $maskedVatIdentifier }}</dd>
                <dt class="text-muted">{{ __('owner.customers.vat.source') }}</dt><dd>{{ $latestVatValidation ? ($latestVatValidation->provider === 'eu_vies' ? __('owner.customers.vat.vies') : __('owner.customers.vat.local_check')) : '—' }}</dd>
                <dt class="text-muted">{{ __('owner.customers.vat.last_check') }}</dt><dd>{{ $latestVatValidation?->checked_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                <dt class="text-muted">{{ __('owner.customers.vat.last_successful_check') }}</dt><dd>{{ $lastSuccessfulVatValidation?->checked_at?->format('Y-m-d H:i') ?? '—' }}</dd>
            </dl>

            @if ($vatHistory->isNotEmpty())
                <div class="mt-4 border-t border-line pt-3">
                    <h3 class="text-xs font-semibold text-primary">{{ __('owner.customers.vat.history') }}</h3>
                    <ul class="mt-2 divide-y divide-line text-xs">
                        @foreach ($vatHistory as $evidence)
                            @php $historyStatus = match ($evidence->status->value) { 'valid' => 'valid', 'invalid', 'malformed' => 'invalid', 'unavailable' => 'unavailable', default => 'pending_review' }; @endphp
                            <li class="flex flex-wrap justify-between gap-2 py-2"><span>{{ $evidence->country_code }} · {{ $evidence->provider === 'eu_vies' ? __('owner.customers.vat.vies') : __('owner.customers.vat.local_check') }} · {{ __('owner.customers.vat.statuses.'.$historyStatus) }} · {{ \App\Tax\Vat\VatIdentifierMasker::mask($evidence->country_code, $evidence->vat_identifier) }}</span><time datetime="{{ $evidence->checked_at?->toIso8601String() }}">{{ $evidence->checked_at?->format('Y-m-d H:i') }}</time></li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (auth('owner')->user()?->can('owner.customers.manage'))
                <form method="POST" action="{{ route('owner.customers.vat-verification.recheck', $customer) }}" class="mt-4 border-t border-line pt-3">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" size="sm">{{ __('owner.customers.vat.recheck') }}</x-ui.button>
                </form>
            @endif
        @else
            <p class="text-sm text-muted">{{ __('owner.customers.vat.no_identity') }}</p>
        @endif

        @if ($reviewCases->isNotEmpty())
            <div class="mt-4 border-t border-line pt-3">
                <h3 class="text-xs font-semibold text-primary">{{ __('owner.customers.vat.review_history') }}</h3>
                <ul class="mt-2 divide-y divide-line text-xs">
                    @foreach ($reviewCases as $review)
                        <li class="py-2">{{ __('owner.customers.vat.review_statuses.'.$review->status->value) }} · {{ $review->created_at?->format('Y-m-d H:i') }} @if ($review->resolved_at) · {{ __('owner.customers.vat.resolved') }} @endif</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </x-operator.section>

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
                                <td class="px-2 py-2">{{ $subscription->interval?->label() ?? "—" }}</td>
                                <td class="px-2 py-2 tabular-nums">
                                    <div>{{ $subscription->currentRecurringMoney()?->format() ?? ($subscription->amount !== null && $subscription->currency ? $subscription->money()->format() : '—') }}</div>
                                    @if ($subscription->currentRecurringMoney())<div class="text-xs text-muted">{{ __('owner.customers.current_recurring') }}</div>@endif
                                    @if ($subscription->basePriceMoney() && $subscription->currentRecurringMoney())<div class="text-xs text-muted">{{ __('account.subscription.base_amount') }}: {{ $subscription->basePriceMoney()->format() }}</div>@endif
                                    @if ($subscription->initial_discount_snapshot_confirmed_at && $subscription->initial_discount_duration === 'once')
                                        <div class="mt-1 text-xs text-muted">{{ __('owner.customers.initial_invoice_discount') }}: {{ $subscription->initial_discount_type === 'percent' ? ($subscription->initialDiscountPercentLabel().'%' ) : __('account.subscription.discount_unknown') }}</div>
                                    @endif
                                </td>
                                <td class="px-2 py-2"><x-ui.status-badge :status="$subscription->status" /></td>
                                <td class="px-2 py-2 font-mono text-xs">{{ $subscription->nextBillingDate()?->format('Y-m-d') ?? ($subscription->cancel_at_period_end ? __('owner.customers.cancels') : '—') }}</td>
                                <td class="px-2 py-2 text-xs">{{ $customer->websites->firstWhere('subscription_id', $subscription->id)?->host() ?? '—' }}</td>
                            </tr>
                            @if ($subscription->pending_change_status || $subscription->reconciliation_status !== 'pending')
                                <tr>
                                    <td colspan="6" class="px-2 pb-3 text-xs text-muted">
                                        @if ($subscription->pending_change_status)
                                            {{ __('owner.customers.pending_change') }}:
                                            {{ $subscription->pending_plan ? __('owner.tiers.'.$subscription->pending_plan) : '—' }}
                                            · {{ $subscription->pending_interval ?? '—' }}
                                            · {{ $subscription->pending_change_status }}
                                            · {{ $subscription->pending_change_effective_at?->format('Y-m-d') ?? '—' }}
                                            <br>
                                        @endif
                                        {{ __('owner.customers.reconciliation') }}: {{ $subscription->reconciliation_status }}
                                        · {{ $subscription->last_reconciled_at?->format('Y-m-d H:i') ?? '—' }}
                                        @if ($subscription->reconciliation_error_category)
                                            · {{ $subscription->reconciliation_error_category }}
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-muted">{{ __('owner.customers.stripe_note') }}</p>
            @foreach ($customer->subscriptions as $subscription)
                @if ($subscription->billingInvoices->isNotEmpty())
                    <div class="mt-4 border-t border-line pt-3">
                        <h3 class="text-xs font-semibold text-primary">{{ __('owner.customers.invoice_history') }} · {{ $subscription->planName() }}</h3>
                        <ul class="mt-2 divide-y divide-line text-xs">
                            @foreach ($subscription->billingInvoices as $invoice)
                                <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <span>{{ $invoice->stripe_created_at?->format('Y-m-d') ?? '—' }} · {{ __('account.subscription.invoice_statuses.'.$invoice->invoice_status, [], app()->getLocale()) }}</span>
                                    <span class="tabular-nums">{{ $invoice->money('amount_paid')?->format() ?? __('account.subscription.amount_unknown') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endforeach
        @endif
    </x-operator.section>

    @if ($customer->billingEmailOutbox->isNotEmpty())
        <x-operator.section :title="__('owner.customers.billing_emails')" id="customer-billing-emails" class="mt-4">
            <ul class="divide-y divide-line text-xs">
                @foreach ($customer->billingEmailOutbox as $delivery)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span>{{ $delivery->created_at?->format('Y-m-d H:i') }} · {{ $delivery->notification }}</span>
                        <span>{{ $delivery->status }}{{ $delivery->failure_category ? ' · '.$delivery->failure_category : '' }}</span>
                    </li>
                @endforeach
            </ul>
        </x-operator.section>
    @endif

    @php
        $internal = $customer->subscriptions->filter(fn ($s) => $s->isInternal());
        $open = $internal->filter(fn ($s) => in_array($s->status, [\App\Billing\SubscriptionStatus::Pending, \App\Billing\SubscriptionStatus::Active], true));
        $stamp = fn ($date) => $date?->format('Y-m-d\TH:i');
    @endphp
    <x-operator.section :title="__('owner.probono.section')" id="customer-internal-plan" class="mt-4">
        <p class="mb-4 text-sm text-muted">{{ __('owner.probono.lead') }}</p>

        @foreach ($internal as $plan)
            @php $isOpen = $open->contains('id', $plan->id); @endphp
            <div class="mb-4 rounded-md border border-line p-3" id="internal-plan-{{ $plan->uuid }}">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-semibold text-primary">{{ $plan->planName() }}</span>
                    <span class="text-xs text-muted">{{ $plan->product_type->label() }}</span>
                    <x-ui.status-badge :status="$plan->status" />
                    <span class="text-xs text-muted">
                        {{ $plan->internal_starts_at ? __('owner.probono.starts_on', ['date' => $plan->internal_starts_at->format('Y-m-d')]) : __('owner.probono.since', ['date' => ($plan->activated_at ?? $plan->created_at)->format('Y-m-d')]) }},
                        {{ $plan->internal_ends_at ? __('owner.probono.until', ['date' => $plan->internal_ends_at->format('Y-m-d')]) : __('owner.probono.no_end') }}
                    </span>
                    <span class="text-xs text-muted">· {{ __('owner.probono.website') }}: {{ $customer->websites->firstWhere('subscription_id', $plan->id)?->host() ?? __('owner.probono.no_website') }}</span>
                </div>

                @if ($isOpen)
                    <form method="POST" action="{{ route('owner.customers.internal-plans.update', [$customer, $plan]) }}" class="mt-3 grid gap-3 md:grid-cols-2" novalidate>
                        @csrf
                        @method('PUT')
                        <x-form.field :name="'starts_at'" type="datetime-local" :label="__('owner.probono.starts_at')" :value="$stamp($plan->internal_starts_at)" :optional="true" />
                        <x-form.field :name="'ends_at'" type="datetime-local" :label="__('owner.probono.ends_at')" :value="$stamp($plan->internal_ends_at)" :optional="true" :hint="__('owner.probono.dates_hint', ['zone' => config('app.timezone')])" />
                        <div class="md:col-span-2">
                            <label for="note-{{ $plan->uuid }}" class="block text-sm font-medium text-primary">{{ __('owner.probono.note') }} <span class="font-normal text-muted">({{ __('owner.optional') }})</span></label>
                            <textarea id="note-{{ $plan->uuid }}" name="note" rows="2" maxlength="1000" class="mt-2 block w-full rounded-sm border border-line-strong bg-surface px-4 py-3 text-[15px] text-ink">{{ $plan->internal_note }}</textarea>
                            <p class="mt-1 text-xs text-muted">{{ __('owner.probono.note_hint') }}</p>
                        </div>
                        <div class="md:col-span-2"><x-ui.button type="submit" variant="secondary" size="sm">{{ __('owner.probono.save') }}</x-ui.button></div>
                    </form>
                    <form method="POST" action="{{ route('owner.customers.internal-plans.end', [$customer, $plan]) }}" class="mt-2" onsubmit="return confirm(@js(__('owner.probono.end_confirm')))">
                        @csrf
                        <x-ui.button type="submit" variant="ghost" size="sm">{{ __('owner.probono.end') }}</x-ui.button>
                    </form>
                @elseif ($plan->internal_note)
                    <p class="mt-2 text-xs text-muted">{{ $plan->internal_note }}</p>
                @endif
            </div>
        @endforeach

        @if ($internal->isEmpty())
            <p class="mb-4 text-sm text-muted">{{ __('owner.probono.none') }}</p>
        @endif

        @if ($customer->active)
            <form method="POST" action="{{ route('owner.customers.internal-plans.store', $customer) }}" class="grid gap-3 border-t border-line pt-4 md:grid-cols-2" novalidate>
                @csrf
                <div class="md:col-span-2">
                    <h3 class="text-sm font-semibold text-primary">{{ __('owner.probono.assign') }}</h3>
                    <p class="text-xs text-muted">{{ __('owner.probono.assign_lead') }}</p>
                </div>
                <x-form.select name="product_type" :label="__('owner.probono.product')" :options="collect(\App\Pricing\ProductType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()" :selected="$customer->websites->first()?->type?->value ?? 'wordpress'" />
                <div></div>
                <x-form.field name="starts_at" type="datetime-local" :label="__('owner.probono.starts_at')" :optional="true" />
                <x-form.field name="ends_at" type="datetime-local" :label="__('owner.probono.ends_at')" :optional="true" :hint="__('owner.probono.dates_hint', ['zone' => config('app.timezone')])" />
                <div class="md:col-span-2">
                    <label for="note-new" class="block text-sm font-medium text-primary">{{ __('owner.probono.note') }} <span class="font-normal text-muted">({{ __('owner.optional') }})</span></label>
                    <textarea id="note-new" name="note" rows="2" maxlength="1000" class="mt-2 block w-full rounded-sm border border-line-strong bg-surface px-4 py-3 text-[15px] text-ink">{{ old('note') }}</textarea>
                    <p class="mt-1 text-xs text-muted">{{ __('owner.probono.note_hint') }}</p>
                </div>
                <label class="flex items-start gap-2 rounded-sm border border-warning/40 bg-warning-soft px-3 py-2 text-sm text-warning-ink md:col-span-2">
                    <input type="checkbox" name="confirm" value="1" class="mt-0.5 size-4">
                    <span>{{ __('owner.probono.confirm') }}</span>
                </label>
                <div class="md:col-span-2"><x-ui.button type="submit">{{ __('owner.probono.assign') }}</x-ui.button></div>
            </form>
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
            <x-form.field name="tax_id" :label="__('owner.customers.fields.tax_id')" value="" maxlength="50" :optional="true" :hint="__('owner.customers.vat.replace_hint')" />
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
