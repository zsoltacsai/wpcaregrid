<x-layouts.owner :title="__('owner.pricing.edit_title')">
    <x-operator.page-header
        :title="$key->productType->label().' '.__('owner.tiers.'.$key->tier->value).' · '.$key->interval->label()"
        :eyebrow="__('owner.pricing.title').' · '.__('owner.pricing.market_names.'.$key->market)"
        :lead="$key->value()"
    />

    @php $published = $definition?->isPublished(); @endphp

    <div class="grid gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <x-operator.section :title="$definition?->draft ? __('owner.pricing.draft') : __('owner.pricing.new_draft')" id="price-draft">
            <form method="POST" action="{{ route('owner.pricing.prices.update', $key->value()) }}" class="grid gap-4" novalidate>
                @csrf
                @method('PUT')
                {{-- The market's currency only: the server refuses any other value. --}}
                <x-form.select name="currency" :label="__('owner.pricing.fields.currency')" :options="[$key->currency() => $key->currency()]" :selected="$key->currency()" :hint="__('owner.pricing.currency_hint')" />
                <x-form.field name="amount" inputmode="decimal" :label="__('owner.pricing.fields.amount').' ('.$key->currency().')'" :value="$amount" required :hint="__('owner.pricing.amount_hint', ['decimals' => $decimals])" />
                <x-form.field name="stripe_price_id" :label="__('owner.pricing.fields.stripe_price_id')" :value="$values['stripe_price_id']" maxlength="100" class="font-mono" :hint="__('owner.pricing.stripe_hint')" />
                <label class="flex items-start gap-2 text-sm">
                    <input type="hidden" name="active" value="0">
                    <input type="checkbox" name="active" value="1" @checked(old('active', $values['active'])) class="mt-0.5 size-4">
                    <span><span class="block font-medium text-primary">{{ __('owner.pricing.fields.active') }}</span><span class="block text-xs text-muted">{{ __('owner.pricing.active_hint') }}</span></span>
                </label>
                <div>
                    <x-ui.button type="submit" variant="secondary">{{ __('owner.pricing.save_draft') }}</x-ui.button>
                </div>
            </form>

            @if ($definition?->draft)
                <div class="mt-5 border-t border-line pt-4">
                    <form method="POST" action="{{ route('owner.pricing.prices.publish', $key->value()) }}" class="grid gap-3">
                        @csrf
                        <label class="flex items-start gap-2 rounded-sm border border-warning/40 bg-warning-soft px-3 py-2 text-sm text-warning-ink">
                            <input type="checkbox" name="confirm" value="1" class="mt-0.5 size-4">
                            <span>{{ __('owner.pricing.confirm_publish', ['count' => $subscriptions]) }}</span>
                        </label>
                        <div class="flex flex-wrap gap-2">
                            <x-ui.button type="submit">{{ __('owner.pricing.publish') }}</x-ui.button>
                        </div>
                    </form>
                    <form method="POST" action="{{ route('owner.pricing.prices.discard', $key->value()) }}" class="mt-2">
                        @csrf
                        <x-ui.button type="submit" size="sm" variant="ghost">{{ __('owner.pricing.discard') }}</x-ui.button>
                    </form>
                    <p class="mt-2 text-xs text-muted">{{ $stripeConfigured ? __('owner.pricing.publish_checks_stripe') : __('owner.pricing.publish_without_stripe') }}</p>
                </div>
            @endif
        </x-operator.section>

        <x-operator.section :title="__('owner.pricing.current')" id="price-current">
            <dl class="grid grid-cols-[minmax(0,130px)_minmax(0,1fr)] gap-x-3 gap-y-2 text-sm">
                <dt class="text-muted">{{ __('owner.pricing.columns.source') }}</dt>
                <dd>{{ $published ? __('owner.pricing.sources.owner') : ($configRow ? __('owner.pricing.sources.config') : '—') }}</dd>
                @if ($published)
                    <dt class="text-muted">{{ __('owner.pricing.columns.amount') }}</dt><dd class="tabular-nums">{{ number_format($definition->amount) }} <span class="text-xs text-muted">{{ __('owner.pricing.minor_units', ['currency' => $definition->currency]) }}</span></dd>
                    <dt class="text-muted">{{ __('owner.pricing.columns.stripe') }}</dt><dd class="font-mono text-xs break-all">{{ $definition->stripe_price_id ?: '—' }}</dd>
                    <dt class="text-muted">{{ __('owner.pricing.columns.state') }}</dt><dd>{{ $definition->active ? __('owner.pricing.states.on_sale') : __('owner.pricing.states.inactive') }}</dd>
                    <dt class="text-muted">{{ __('owner.pricing.published_at') }}</dt><dd class="font-mono text-xs">@displayDateTime($definition->published_at)</dd>
                    @if ($definition->retired_stripe_price_ids)
                        <dt class="text-muted">{{ __('owner.pricing.retired') }}</dt><dd class="font-mono text-xs break-all">{{ implode(', ', $definition->retired_stripe_price_ids) }}</dd>
                    @endif
                @elseif ($configRow)
                    <dt class="text-muted">{{ __('owner.pricing.columns.amount') }}</dt><dd class="tabular-nums">{{ number_format($configRow['amount']) }} <span class="text-xs text-muted">{{ __('owner.pricing.minor_units', ['currency' => $configRow['currency']]) }}</span></dd>
                    <dt class="text-muted">{{ __('owner.pricing.columns.stripe') }}</dt><dd class="font-mono text-xs break-all">{{ $configRow['stripe_price_id'] ?: '—' }}</dd>
                @endif
                <dt class="text-muted">{{ __('owner.pricing.subscriptions') }}</dt><dd class="tabular-nums">{{ $subscriptions }}</dd>
            </dl>
            <p class="mt-4 text-xs text-muted">{{ __('owner.pricing.future_only') }}</p>
        </x-operator.section>
    </div>

    <p class="mt-4"><x-ui.button :href="route('owner.pricing.index', ['market' => $key->market])" variant="ghost" size="sm">{{ __('owner.pricing.back') }}</x-ui.button></p>
</x-layouts.owner>
