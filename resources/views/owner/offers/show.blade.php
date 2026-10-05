@use('App\Offers\OfferStatus')

<x-layouts.owner :title="$offer->title">
    <x-operator.page-header :title="$offer->title" :eyebrow="__('owner.offers.title')">
        <x-slot:actions>
            <x-ui.status-badge :tone="$offer->status->tone()">{{ $offer->status->label() }}</x-ui.status-badge>
        </x-slot:actions>
    </x-operator.page-header>

    @if ($offer->status->isOpen() && $offer->validityPassed())
        <x-account.notice tone="warning" class="mb-4">{{ __('owner.offers.validity_passed_note') }}</x-account.notice>
    @endif
    @if ($offer->status === OfferStatus::Paid && $offer->paid_amount !== $offer->amount)
        <x-account.notice tone="danger" class="mb-4">{{ __('owner.offers.amount_mismatch') }}</x-account.notice>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <x-operator.section :title="__('owner.offers.sections.addressee')" id="offer-addressee">
            <dl class="grid grid-cols-[minmax(0,130px)_minmax(0,1fr)] gap-x-3 gap-y-2 text-sm">
                <dt class="text-muted">{{ __('owner.offers.fields.customer') }}</dt><dd><a class="text-primary hover:underline" href="{{ route('owner.customers.show', $offer->customer) }}">{{ $offer->customer->fullName() }}</a> · <span class="break-all">{{ $offer->customer->email }}</span></dd>
                <dt class="text-muted">{{ __('owner.offers.fields.website') }}</dt><dd><a class="text-primary hover:underline" href="{{ route('owner.websites.show', $offer->website) }}">{{ $offer->website->host() }}</a></dd>
                <dt class="text-muted">{{ __('owner.offers.fields.price') }}</dt><dd class="tabular-nums">{{ $offer->money()->format() }} <span class="font-mono text-xs text-muted">{{ $offer->currency }}</span></dd>
                <dt class="text-muted">{{ __('owner.offers.fields.valid_until') }}</dt><dd class="font-mono text-xs">{{ $offer->valid_until->format('Y-m-d H:i') }} {{ $timezone }}</dd>
                <dt class="text-muted">{{ __('owner.offers.fields.created_by') }}</dt><dd class="text-xs">{{ $offer->createdBy?->email ?? '—' }}</dd>
            </dl>
        </x-operator.section>

        <x-operator.section :title="__('owner.offers.sections.timeline')" id="offer-timeline">
            <dl class="grid grid-cols-[minmax(0,130px)_minmax(0,1fr)] gap-x-3 gap-y-2 text-sm">
                @foreach (['created_at' => 'created', 'offered_at' => 'offered', 'accepted_at' => 'accepted', 'declined_at' => 'declined', 'expired_at' => 'expired', 'paid_at' => 'paid', 'cancelled_at' => 'cancelled', 'completed_at' => 'completed'] as $field => $label)
                    @if ($offer->{$field})
                        <dt class="text-muted">{{ __('owner.offers.timeline.'.$label) }}</dt><dd class="font-mono text-xs">{{ $offer->{$field}->format('Y-m-d H:i') }}</dd>
                    @endif
                @endforeach
                @if ($offer->paid_amount !== null)
                    <dt class="text-muted">{{ __('owner.offers.timeline.paid_amount') }}</dt><dd class="tabular-nums">{{ (new \App\Pricing\Money($offer->paid_amount, $offer->money()->currency))->format() }}</dd>
                @endif
                @if ($offer->stripe_payment_intent_id)
                    <dt class="text-muted">{{ __('owner.offers.timeline.payment_intent') }}</dt><dd class="font-mono text-xs break-all">{{ $offer->stripe_payment_intent_id }}</dd>
                @endif
            </dl>
        </x-operator.section>
    </div>

    <x-operator.section :title="__('owner.offers.sections.offer')" id="offer-offer" class="mt-4">
        @if ($offer->status->isEditable())
            <form method="POST" action="{{ route('owner.offers.update', $offer) }}" novalidate>
                @csrf
                @method('PUT')
                @if ($offer->status === OfferStatus::Draft)
                    <div class="mb-4 md:max-w-md">
                        <x-form.select name="website" :label="__('owner.offers.fields.website')" :options="$websites->mapWithKeys(fn ($w) => [$w->uuid => $w->host()])->all()" :selected="$offer->website->uuid" />
                    </div>
                @endif
                @include('owner.offers._fields')
                <div class="mt-4"><x-ui.button type="submit" variant="secondary">{{ __('owner.offers.save') }}</x-ui.button></div>
            </form>
            @if ($offer->status === OfferStatus::Offered)
                <p class="mt-3 text-xs text-muted">{{ __('owner.offers.edit_offered_note') }}</p>
            @endif
        @else
            <h3 class="text-xs font-semibold tracking-[0.08em] text-muted uppercase">{{ __('owner.offers.fields.scope') }}</h3>
            <p class="mt-1 text-sm whitespace-pre-line">{{ $offer->scope }}</p>
            @if ($offer->internal_notes)
                <h3 class="mt-4 text-xs font-semibold tracking-[0.08em] text-muted uppercase">{{ __('owner.offers.fields.internal_notes') }}</h3>
                <p class="mt-1 text-sm whitespace-pre-line text-muted">{{ $offer->internal_notes }}</p>
            @endif
            <p class="mt-3 text-xs text-muted">{{ __('owner.offers.frozen_note') }}</p>
        @endif
    </x-operator.section>

    <x-operator.section :title="__('owner.offers.sections.actions')" id="offer-actions" class="mt-4">
        <div class="flex flex-wrap items-start gap-4">
            @if ($offer->status === OfferStatus::Draft)
                <form method="POST" action="{{ route('owner.offers.send', $offer) }}" class="grid gap-2">
                    @csrf
                    <label class="flex items-start gap-2 rounded-sm border border-warning/40 bg-warning-soft px-3 py-2 text-sm text-warning-ink">
                        <input type="checkbox" name="confirm" value="1" class="mt-0.5 size-4">
                        <span>{{ __('owner.offers.confirm_send') }}</span>
                    </label>
                    <div><x-ui.button type="submit">{{ __('owner.offers.send') }}</x-ui.button></div>
                </form>
            @endif
            @if ($offer->status === OfferStatus::Paid)
                <form method="POST" action="{{ route('owner.offers.complete', $offer) }}">@csrf<x-ui.button type="submit">{{ __('owner.offers.complete') }}</x-ui.button></form>
            @endif
            @if (in_array($offer->status, [OfferStatus::Draft, OfferStatus::Offered, OfferStatus::Accepted], true))
                <form method="POST" action="{{ route('owner.offers.cancel', $offer) }}" onsubmit="return confirm(@js(__('owner.offers.confirm_cancel')))">@csrf<x-ui.button type="submit" variant="ghost">{{ __('owner.offers.cancel') }}</x-ui.button></form>
            @endif
            @if ($offer->status->isFinal() || $offer->status === OfferStatus::Paid)
                <p class="text-sm text-muted">{{ __('owner.offers.no_more_actions.'.$offer->status->value) }}</p>
            @endif
        </div>
    </x-operator.section>

    <x-operator.section :title="__('owner.customers.sections.changes')" id="offer-changes" class="mt-4">
        <x-owner.audit-list :events="$audit" />
    </x-operator.section>

    <p class="mt-4"><x-ui.button :href="route('owner.offers.index')" variant="ghost" size="sm">{{ __('owner.offers.back') }}</x-ui.button></p>
</x-layouts.owner>
