<x-layouts.owner :title="__('owner.coupons.new_title')">
    <x-operator.page-header :title="__('owner.coupons.new_title')" :lead="__('owner.coupons.new_lead')" :eyebrow="__('owner.coupons.title')" />

    <form method="POST" action="{{ route('owner.coupons.store') }}" class="grid gap-4" novalidate x-data="{ duration: @js(old('duration', 'once')), percent: @js(old('percent_off', '')) }">
        @csrf
        <x-operator.section :title="__('owner.coupons.sections.discount')" id="coupon-discount">
            <div class="grid gap-4 md:grid-cols-2">
                <x-form.field name="code" :label="__('owner.coupons.fields.code')" required maxlength="32" class="font-mono" autocapitalize="characters" :hint="__('owner.coupons.hints.code')" />
                <x-form.field name="percent_off" inputmode="decimal" :label="__('owner.coupons.fields.percent_off')" required maxlength="6" x-model="percent" :hint="__('owner.coupons.hints.percent_off')" />
                <x-form.select name="duration" :label="__('owner.coupons.fields.duration')" :options="['once' => __('owner.coupons.durations.once'), 'repeating' => __('owner.coupons.durations.repeating'), 'forever' => __('owner.coupons.durations.forever')]" :selected="old('duration', 'once')" x-model="duration" :hint="__('owner.coupons.hints.duration')" />
                <div x-show="duration === 'repeating'" x-cloak>
                    <x-form.field name="duration_months" type="number" min="1" max="36" inputmode="numeric" :label="__('owner.coupons.fields.duration_months')" :hint="__('owner.coupons.hints.duration_months')" />
                </div>
            </div>
            <p class="mt-3 text-xs text-muted" x-show="percent.trim() === '100' && duration === 'forever'" x-cloak>{{ __('owner.coupons.hints.free_forever') }}</p>
            <p class="mt-3 text-xs text-muted" x-show="percent.trim() === '100' && duration !== 'forever'" x-cloak>{{ __('owner.coupons.hints.free_then_paid') }}</p>
            <p class="mt-3 text-xs text-muted">{{ __('owner.coupons.fixed_note') }}</p>
        </x-operator.section>

        <x-operator.section :title="__('owner.coupons.sections.rules')" id="coupon-rules">
            @include('owner.coupons._rules', ['coupon' => null])
        </x-operator.section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit">{{ __('owner.coupons.create') }}</x-ui.button>
            <x-ui.button :href="route('owner.coupons.index')" variant="ghost">{{ __('owner.coupons.cancel') }}</x-ui.button>
        </div>
    </form>
</x-layouts.owner>
