<x-layouts.owner :title="__('owner.offers.new_title')">
    <x-operator.page-header :title="__('owner.offers.new_title')" :lead="__('owner.offers.new_lead')" :eyebrow="__('owner.offers.title')" />

    @if (! $customer)
        <x-operator.section :title="__('owner.offers.step_customer')" id="offer-customer">
            <form method="GET" action="{{ route('owner.offers.create') }}" class="grid gap-4 md:max-w-xl">
                <x-form.select name="customer" :label="__('owner.offers.fields.customer')" :options="$customers->mapWithKeys(fn ($c) => [$c->uuid => $c->fullName().' · '.$c->email])->all()" />
                <div><x-ui.button type="submit">{{ __('owner.offers.continue') }}</x-ui.button></div>
            </form>
        </x-operator.section>
    @elseif ($websites->isEmpty())
        <x-account.notice tone="warning">{{ __('owner.offers.no_websites', ['name' => $customer->fullName()]) }}</x-account.notice>
        <p class="mt-4"><x-ui.button :href="route('owner.offers.create')" variant="ghost" size="sm">{{ __('owner.offers.other_customer') }}</x-ui.button></p>
    @else
        <form method="POST" action="{{ route('owner.offers.store') }}" class="grid gap-4" novalidate>
            @csrf
            <input type="hidden" name="customer" value="{{ $customer->uuid }}">
            <x-operator.section :title="__('owner.offers.sections.addressee')" id="offer-addressee">
                <dl class="mb-4 grid grid-cols-[minmax(0,110px)_minmax(0,1fr)] gap-x-3 gap-y-1 text-sm">
                    <dt class="text-muted">{{ __('owner.offers.fields.customer') }}</dt><dd>{{ $customer->fullName() }} · {{ $customer->email }}</dd>
                </dl>
                <x-form.select name="website" :label="__('owner.offers.fields.website')" :options="$websites->mapWithKeys(fn ($w) => [$w->uuid => $w->host()])->all()" :hint="__('owner.offers.hints.website')" />
            </x-operator.section>
            <x-operator.section :title="__('owner.offers.sections.offer')" id="offer-offer">
                @include('owner.offers._fields', ['offer' => null, 'amount' => ''])
            </x-operator.section>
            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit">{{ __('owner.offers.create') }}</x-ui.button>
                <x-ui.button :href="route('owner.offers.index')" variant="ghost">{{ __('owner.coupons.cancel') }}</x-ui.button>
            </div>
        </form>
    @endif
</x-layouts.owner>
