<x-layouts.owner :title="__('owner.stripe_catalogue.title')">
    <x-operator.page-header :title="__('owner.stripe_catalogue.title')" :lead="__('owner.stripe_catalogue.lead')" :eyebrow="__('owner.pricing.title')">
        <x-slot:actions>
            <x-ui.button :href="route('owner.pricing.index')" size="sm" variant="ghost">{{ __('owner.stripe_catalogue.back') }}</x-ui.button>
        </x-slot:actions>
    </x-operator.page-header>

    <x-operator.section :title="__('owner.stripe_catalogue.environment')" id="environment">
        @if ($refusal)
            <x-account.notice tone="danger">{{ __('owner.stripe_catalogue.errors.'.$refusal) }} <span class="font-mono text-xs">{{ strtoupper($refusal) }}</span></x-account.notice>
        @else
            <dl class="grid grid-cols-[minmax(0,180px)_minmax(0,1fr)] gap-x-3 gap-y-2 text-sm">
                <dt class="text-muted">{{ __('owner.stripe_catalogue.mode') }}</dt>
                <dd><x-ui.status-badge :tone="$mode->value === 'test' ? 'positive' : 'warning'" variant="code">{{ $mode->value === 'test' ? __('owner.stripe_catalogue.sandbox') : __('owner.stripe_catalogue.live') }}</x-ui.status-badge></dd>
                <dt class="text-muted">{{ __('owner.stripe_catalogue.stack') }}</dt>
                <dd>{{ $staging ? __('owner.stripe_catalogue.stack_staging') : __('owner.stripe_catalogue.stack_other') }}</dd>
                <dt class="text-muted">{{ __('owner.stripe_catalogue.changes') }}</dt>
                <dd>
                    <x-ui.status-badge :tone="$applyEnabled ? 'warning' : 'neutral'">{{ $applyEnabled ? __('owner.stripe_catalogue.changes_on') : __('owner.stripe_catalogue.changes_off') }}</x-ui.status-badge>
                    @if ($mode->value === 'live')
                        <x-ui.status-badge :tone="$liveApproved ? 'warning' : 'neutral'" class="ml-1">{{ $liveApproved ? __('owner.stripe_catalogue.live_approved') : __('owner.stripe_catalogue.live_not_approved') }}</x-ui.status-badge>
                    @endif
                </dd>
            </dl>
            <p class="mt-3 text-xs text-muted">{{ __('owner.stripe_catalogue.rules') }}</p>
            <form method="POST" action="{{ route('owner.pricing.stripe-catalogue.preview') }}" class="mt-4">
                @csrf
                <x-ui.button type="submit">{{ __('owner.stripe_catalogue.load') }}</x-ui.button>
                <span class="ml-2 text-xs text-muted">{{ __('owner.stripe_catalogue.load_hint') }}</span>
            </form>
        @endif
    </x-operator.section>

    <x-operator.section :title="__('owner.stripe_catalogue.history')" id="history">
        @if ($operations->isEmpty())
            <p class="text-sm text-muted">{{ __('owner.stripe_catalogue.no_history') }}</p>
        @else
            <table class="w-full text-left text-sm">
                <thead class="text-[11px] font-semibold tracking-[0.08em] text-muted uppercase">
                    <tr class="border-b border-line">
                        <th scope="col" class="px-2 py-2">{{ __('owner.stripe_catalogue.columns.when') }}</th>
                        <th scope="col" class="px-2 py-2">{{ __('owner.stripe_catalogue.mode') }}</th>
                        <th scope="col" class="px-2 py-2">{{ __('owner.stripe_catalogue.columns.status') }}</th>
                        <th scope="col" class="px-2 py-2"><span class="sr-only">{{ __('owner.stripe_catalogue.open') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($operations as $operation)
                        <tr>
                            <td class="px-2 py-2 font-mono text-xs">@displayDateTime($operation->created_at)</td>
                            <td class="px-2 py-2 font-mono text-xs uppercase">{{ $operation->mode }}</td>
                            <td class="px-2 py-2">{{ __('owner.stripe_catalogue.statuses.'.$operation->status) }}</td>
                            <td class="px-2 py-2 text-right"><x-ui.button :href="route('owner.pricing.stripe-catalogue.show', $operation)" size="sm" variant="ghost">{{ __('owner.stripe_catalogue.open') }}</x-ui.button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-operator.section>
</x-layouts.owner>
