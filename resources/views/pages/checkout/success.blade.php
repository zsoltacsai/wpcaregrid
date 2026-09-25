{{--
    Where Stripe sends the customer after checkout. Showing this page does
    not mark anything as paid; the Stripe webhook does that.
--}}
<x-layouts.marketing :title="__('checkout.meta.success_title')" :description="__('home.meta.description')">
    <section class="py-16 sm:py-20 lg:py-24" aria-labelledby="success-title">
        <x-ui.container class="max-w-[720px]">
            <div class="rounded-lg border border-line bg-surface p-6 sm:p-10">
                <x-ui.icon-tile name="check-circle" tone="positive" />
                <h1 id="success-title" class="mt-6 text-[28px] leading-[1.15] font-semibold tracking-[-0.015em] sm:text-[32px]">
                    {{ __('checkout.success.title') }}
                </h1>
                <p class="mt-3 text-lg text-ink">{{ __('checkout.success.lead') }}</p>
                <p class="mt-4 text-base leading-relaxed text-muted">{{ __('checkout.success.body') }}</p>

                @if ($intent)
                    <dl class="mt-8 grid gap-4 border-t border-line pt-6 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-muted">{{ __('checkout.success.plan') }}</dt>
                            <dd class="mt-1 font-medium text-primary">
                                {{ $intent->product_type->label() }} {{ __("pricing.plans.{$intent->product_type->value}.{$intent->plan->value}.name") }}
                                · {{ $intent->interval->label() }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted">{{ __('checkout.success.order') }}</dt>
                            <dd class="mt-1 font-mono text-primary">{{ strtoupper(substr($intent->uuid, 0, 8)) }}</dd>
                        </div>
                    </dl>
                    <p class="mt-4 text-sm text-muted">{{ __('checkout.success.email_note', ['email' => $intent->email]) }}</p>
                @endif

                <div class="mt-8">
                    <x-ui.button :href="route('home')" variant="secondary">{{ __('checkout.success.home') }}</x-ui.button>
                </div>
            </div>
        </x-ui.container>
    </section>
</x-layouts.marketing>
