<x-layouts.owner :title="$coupon->code">
    <x-operator.page-header :title="$coupon->code" :eyebrow="__('owner.coupons.title')" class="[&_h1]:font-mono">
        <x-slot:actions>
            <x-ui.status-badge :tone="$coupon->active ? 'positive' : 'neutral'">{{ __('owner.coupons.states.'.($coupon->active ? 'active' : 'inactive')) }}</x-ui.status-badge>
        </x-slot:actions>
    </x-operator.page-header>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-operator.section :title="__('owner.coupons.sections.discount')" id="coupon-discount">
            <dl class="grid grid-cols-[minmax(0,150px)_minmax(0,1fr)] gap-x-3 gap-y-2 text-sm">
                <dt class="text-muted">{{ __('owner.coupons.fields.percent_off') }}</dt><dd class="tabular-nums">{{ $coupon->percentLabel() }}%</dd>
                <dt class="text-muted">{{ __('owner.coupons.fields.duration') }}</dt><dd>{{ __('owner.coupons.durations_short.'.$coupon->duration, ['months' => $coupon->duration_months]) }}</dd>
                <dt class="text-muted">{{ __('owner.coupons.columns.used') }}</dt><dd class="tabular-nums">{{ $coupon->times_redeemed }}</dd>
                <dt class="text-muted">{{ __('owner.coupons.remaining') }}</dt><dd class="tabular-nums">{{ $coupon->remainingRedemptions() ?? __('owner.coupons.unlimited') }}</dd>
                <dt class="text-muted">{{ __('owner.coupons.created_at') }}</dt><dd class="font-mono text-xs">{{ $coupon->created_at->format('Y-m-d H:i') }}</dd>
                <dt class="text-muted">{{ __('owner.coupons.columns.updated') }}</dt><dd class="font-mono text-xs">{{ $coupon->updated_at->format('Y-m-d H:i') }}</dd>
                <dt class="text-muted">{{ __('owner.coupons.stripe_code') }}</dt><dd class="font-mono text-xs break-all">{{ $coupon->stripe_promotion_code_id ?: '—' }}</dd>
            </dl>
            @if ($coupon->makesEveryInvoiceFree())
                <p class="mt-3 text-xs text-muted">{{ __('owner.coupons.hints.free_forever') }}</p>
            @elseif ($coupon->isFullDiscount())
                <p class="mt-3 text-xs text-muted">{{ __('owner.coupons.hints.free_then_paid') }}</p>
            @endif
            <p class="mt-3 text-xs text-muted">{{ __('owner.coupons.fixed_note') }}</p>
        </x-operator.section>

        <x-operator.section :title="__('owner.coupons.sections.state')" id="coupon-state">
            <p class="text-sm text-muted">{{ $coupon->active ? __('owner.coupons.state_active_lead') : __('owner.coupons.state_inactive_lead') }}</p>
            <div class="mt-3 flex flex-wrap gap-2">
                @if ($coupon->active)
                    <form method="POST" action="{{ route('owner.coupons.deactivate', $coupon) }}">@csrf<x-ui.button type="submit" variant="secondary" size="sm" :disabled="! $stripeConfigured">{{ __('owner.coupons.deactivate') }}</x-ui.button></form>
                @else
                    <form method="POST" action="{{ route('owner.coupons.activate', $coupon) }}">@csrf<x-ui.button type="submit" size="sm" :disabled="! $stripeConfigured">{{ __('owner.coupons.activate') }}</x-ui.button></form>
                @endif
                @unless ($coupon->hasHistory())
                    <form method="POST" action="{{ route('owner.coupons.destroy', $coupon) }}" onsubmit="return confirm(@js(__('owner.coupons.confirm_delete', ['code' => $coupon->code])))">
                        @csrf
                        @method('DELETE')
                        <x-ui.button type="submit" variant="ghost" size="sm" :disabled="! $stripeConfigured">{{ __('owner.coupons.delete') }}</x-ui.button>
                    </form>
                @endunless
            </div>
            @unless ($stripeConfigured)
                <p class="mt-3 text-xs text-muted">{{ __('owner.coupons.stripe_needed') }}</p>
            @endunless
            <p class="mt-3 text-xs text-muted">{{ $coupon->hasHistory() ? __('owner.coupons.history_note') : __('owner.coupons.no_history_note') }}</p>
        </x-operator.section>
    </div>

    <x-operator.section :title="__('owner.coupons.sections.rules')" id="coupon-rules" class="mt-4">
        <form method="POST" action="{{ route('owner.coupons.update', $coupon) }}" novalidate>
            @csrf
            @method('PUT')
            @include('owner.coupons._rules')
            <div class="mt-4"><x-ui.button type="submit">{{ __('owner.coupons.save') }}</x-ui.button></div>
        </form>
    </x-operator.section>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <x-operator.section :title="__('owner.coupons.sections.uses')" id="coupon-uses">
            @if ($recent->isEmpty())
                <p class="text-sm text-muted">{{ __('owner.coupons.no_uses') }}</p>
            @else
                <ul class="divide-y divide-line text-sm">
                    @foreach ($recent as $intent)
                        <li class="flex flex-wrap justify-between gap-2 py-2">
                            <span>{{ $intent->product_type->label() }} {{ __('owner.tiers.'.$intent->plan->value) }} · {{ $intent->interval->label() }}</span>
                            <span class="font-mono text-xs text-muted">{{ $intent->status->value }} · {{ ($intent->completed_at ?? $intent->created_at)->format('Y-m-d H:i') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-operator.section>
        <x-operator.section :title="__('owner.customers.sections.changes')" id="coupon-changes">
            <x-owner.audit-list :events="$audit" />
        </x-operator.section>
    </div>

    <p class="mt-4"><x-ui.button :href="route('owner.coupons.index')" variant="ghost" size="sm">{{ __('owner.coupons.back') }}</x-ui.button></p>
</x-layouts.owner>
