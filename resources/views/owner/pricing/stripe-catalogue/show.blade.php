@php
    $tones = ['exact' => 'positive', 'missing' => 'warning', 'ambiguous' => 'danger', 'conflict' => 'danger', 'blocked' => 'neutral', 'skipped' => 'neutral'];
    $actions = collect($plan['actions']);
    $canLink = $operation->status === \App\Models\StripeCatalogueOperation::PREVIEWED && ! $expired && $currentMode?->value === $operation->mode && $actions->isNotEmpty();
    $canCreate = $canLink && $applyEnabled;
    $isLink = fn (array $action) => in_array($action['kind'], ['link_product', 'link_price'], true);
    $itemResults = $result['items'] ?? [];
@endphp

<x-layouts.owner :title="__('owner.stripe_catalogue.title')">
    <x-operator.page-header :title="__('owner.stripe_catalogue.preview_title')" :eyebrow="__('owner.stripe_catalogue.title')" :lead="$operation->id">
        <x-slot:actions>
            <x-ui.button :href="route('owner.pricing.stripe-catalogue.index')" size="sm" variant="ghost">{{ __('owner.stripe_catalogue.back') }}</x-ui.button>
        </x-slot:actions>
    </x-operator.page-header>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <x-ui.status-badge :tone="$operation->mode === 'test' ? 'positive' : 'warning'" variant="code">{{ $operation->mode === 'test' ? __('owner.stripe_catalogue.sandbox') : __('owner.stripe_catalogue.live') }}</x-ui.status-badge>
        <x-ui.status-badge tone="neutral">{{ __('owner.stripe_catalogue.statuses.'.$operation->status) }}</x-ui.status-badge>
        <span class="font-mono text-xs text-muted">@displayDateTime($operation->created_at)</span>
    </div>

    @if ($operation->status === \App\Models\StripeCatalogueOperation::PREVIEWED)
        <x-account.notice :tone="$expired ? 'warning' : 'neutral'" class="mb-4">{{ $expired ? __('owner.stripe_catalogue.expired') : __('owner.stripe_catalogue.dry_run') }}</x-account.notice>
    @endif

    <x-operator.section :title="__('owner.stripe_catalogue.summary')" id="summary">
        <dl class="grid grid-cols-[minmax(0,180px)_minmax(0,1fr)] gap-x-3 gap-y-2 text-sm">
            <dt class="text-muted">{{ __('owner.stripe_catalogue.prices') }}</dt>
            <dd class="tabular-nums">
                {{ $plan['summary']['total_prices'] }}
                @foreach ($plan['summary']['prices'] as $state => $count)
                    · {{ __('owner.stripe_catalogue.states.'.$state) }} {{ $count }}
                @endforeach
                @if (($plan['not_on_sale'] ?? 0) > 0) · {{ __('owner.stripe_catalogue.not_on_sale', ['count' => $plan['not_on_sale']]) }} @endif
            </dd>
            <dt class="text-muted">{{ __('owner.stripe_catalogue.products') }}</dt>
            <dd>@foreach ($plan['summary']['products'] as $state => $count){{ $loop->first ? '' : ' · ' }}{{ __('owner.stripe_catalogue.states.'.$state) }} {{ $count }}@endforeach</dd>
            <dt class="text-muted">{{ __('owner.stripe_catalogue.proposed') }}</dt>
            <dd>@forelse ($plan['summary']['actions'] as $kind => $count){{ $loop->first ? '' : ' · ' }}{{ __('owner.stripe_catalogue.kinds.'.$kind) }} {{ $count }}@empty — @endforelse</dd>
        </dl>
    </x-operator.section>

    <x-operator.section :title="__('owner.stripe_catalogue.products')" id="products">
        <div class="relative overflow-x-auto">
            <table class="w-full min-w-[640px] text-left text-sm">
                <thead class="text-[11px] font-semibold tracking-[0.08em] text-muted uppercase">
                    <tr class="border-b border-line">
                        <th scope="col" class="px-2 py-2">{{ __('owner.stripe_catalogue.columns.type') }}</th>
                        <th scope="col" class="px-2 py-2">{{ __('owner.stripe_catalogue.columns.state') }}</th>
                        <th scope="col" class="px-2 py-2">{{ __('owner.stripe_catalogue.columns.stripe_product') }}</th>
                        <th scope="col" class="px-2 py-2">{{ __('owner.stripe_catalogue.columns.note') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($plan['products'] as $type => $product)
                        <tr>
                            <td class="px-2 py-2">{{ $type }}</td>
                            <td class="px-2 py-2"><x-ui.status-badge :tone="$tones[$product['state']]">{{ __('owner.stripe_catalogue.states.'.$product['state']) }}</x-ui.status-badge></td>
                            <td class="px-2 py-2 font-mono text-xs break-all">{{ $product['stripe_product_id'] ?: '—' }}</td>
                            <td class="px-2 py-2 text-xs text-muted">
                                @if ($product['source']) {{ __('owner.stripe_catalogue.sources.'.$product['source']) }} @endif
                                @if ($product['reason']) {{ __('owner.stripe_catalogue.reasons.'.$product['reason']) }} @endif
                                @if ($product['suggestions'] !== [] && $canLink)
                                    <div class="mt-1">{{ __('owner.stripe_catalogue.similar_products') }}</div>
                                    @foreach ($product['suggestions'] as $suggestion)
                                        <form method="POST" action="{{ route('owner.pricing.stripe-catalogue.products.map', $type) }}" class="mt-1 flex flex-wrap items-center gap-2">
                                            @csrf
                                            <input type="hidden" name="stripe_product_id" value="{{ $suggestion['id'] }}">
                                            <span>{{ $suggestion['name'] }} <span class="font-mono">{{ $suggestion['id'] }}</span></span>
                                            <label class="flex items-center gap-1"><input type="checkbox" name="confirm" value="1" class="size-4"> {{ __('owner.stripe_catalogue.confirm_link_short') }}</label>
                                            <x-ui.button type="submit" size="sm" variant="secondary">{{ __('owner.stripe_catalogue.use_product') }}</x-ui.button>
                                        </form>
                                    @endforeach
                                @elseif ($product['suggestions'] !== [])
                                    <div class="mt-1">{{ __('owner.stripe_catalogue.similar_products') }} {{ collect($product['suggestions'])->map(fn ($s) => $s['name'].' ('.$s['id'].')')->implode(', ') }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-operator.section>

    <x-operator.section :title="__('owner.stripe_catalogue.prices')" id="prices">
        <div class="relative overflow-x-auto">
            <table class="w-full min-w-[860px] text-left text-sm">
                <thead class="text-[11px] font-semibold tracking-[0.08em] text-muted uppercase">
                    <tr class="border-b border-line">
                        <th scope="col" class="px-2 py-2">{{ __('owner.stripe_catalogue.columns.price') }}</th>
                        <th scope="col" class="px-2 py-2">{{ __('owner.stripe_catalogue.columns.amount') }}</th>
                        <th scope="col" class="px-2 py-2">{{ __('owner.stripe_catalogue.columns.state') }}</th>
                        <th scope="col" class="px-2 py-2">{{ __('owner.stripe_catalogue.columns.stripe_price') }}</th>
                        <th scope="col" class="px-2 py-2">{{ __('owner.stripe_catalogue.columns.note') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($plan['prices'] as $key => $price)
                        <tr>
                            <td class="px-2 py-2"><span class="block">{{ $price['label'] }}</span><span class="font-mono text-[11px] text-muted">{{ $key }}</span></td>
                            <td class="px-2 py-2 tabular-nums whitespace-nowrap">{{ number_format($price['amount']) }} <span class="text-xs text-muted">{{ $price['currency'] }} ({{ __('owner.pricing.minor_units', ['currency' => $price['currency']]) }})</span></td>
                            <td class="px-2 py-2"><x-ui.status-badge :tone="$tones[$price['state']]">{{ __('owner.stripe_catalogue.states.'.$price['state']) }}</x-ui.status-badge></td>
                            <td class="max-w-[220px] px-2 py-2 font-mono text-xs break-all">{{ $price['stripe_price_id'] ?: ($price['candidates'] !== [] ? implode(', ', $price['candidates']) : '—') }}</td>
                            <td class="px-2 py-2 text-xs text-muted">
                                @if ($price['via']) {{ __('owner.stripe_catalogue.via.'.$price['via']) }} @endif
                                @if ($price['reason']) {{ __('owner.stripe_catalogue.reasons.'.$price['reason']) }} @endif
                                @if ($price['details'] !== []) <span class="font-mono">{{ implode(', ', $price['details']) }}</span> @endif
                                @if ($price['state'] === 'ambiguous' && $canLink)
                                    @foreach ($price['candidates'] as $candidate)
                                        <form method="POST" action="{{ route('owner.pricing.stripe-catalogue.prices.map', $key) }}" class="mt-1 flex flex-wrap items-center gap-2">
                                            @csrf
                                            <input type="hidden" name="stripe_price_id" value="{{ $candidate }}">
                                            <span class="font-mono">{{ $candidate }}</span>
                                            <label class="flex items-center gap-1"><input type="checkbox" name="confirm" value="1" class="size-4"> {{ __('owner.stripe_catalogue.confirm_link_short') }}</label>
                                            <x-ui.button type="submit" size="sm" variant="secondary">{{ __('owner.stripe_catalogue.use_price') }}</x-ui.button>
                                        </form>
                                    @endforeach
                                @endif
                                @if ($price['action']) <span class="font-medium text-ink">→ {{ __('owner.stripe_catalogue.actions.'.$price['action']) }}</span> @endif
                                @if (isset($itemResults['create_price:'.$key]) || isset($itemResults['link_price:'.$key]))
                                    @php $r = $itemResults['create_price:'.$key] ?? $itemResults['link_price:'.$key]; @endphp
                                    <span class="block font-mono">{{ $r['status'] }}: {{ $r['code'] }} {{ $r['stripe_id'] ?? '' }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-operator.section>

    @if ($actions->isNotEmpty())
        <x-operator.section :title="__('owner.stripe_catalogue.proposals')" id="proposals">
            <form method="POST" action="{{ route('owner.pricing.stripe-catalogue.apply', $operation) }}" class="grid gap-3">
                @csrf
                <ul class="grid gap-1 text-sm">
                    @foreach ($actions as $action)
                        <li class="flex items-start gap-2">
                            @if ($isLink($action))
                                <input type="checkbox" name="items[]" value="{{ $action['id'] }}" @checked($canLink) @disabled(! $canLink) class="mt-0.5 size-4">
                            @else
                                {{-- Creating Stripe objects stays disabled unless creation was switched on separately. --}}
                                <input type="checkbox" name="items[]" value="{{ $action['id'] }}" @disabled(! $canCreate) class="mt-0.5 size-4">
                            @endif
                            <span>
                                <span class="font-medium">{{ __('owner.stripe_catalogue.kinds.'.$action['kind']) }}</span>
                                @if (! $isLink($action) && ! $canCreate) <span class="text-xs text-muted">({{ __('owner.stripe_catalogue.disabled') }})</span> @endif
                                <span class="font-mono text-xs text-muted">{{ $action['key'] ?? $action['product_type'] }}</span>
                                @if (isset($itemResults[$action['id']]))
                                    <span class="font-mono text-xs">— {{ $itemResults[$action['id']]['status'] }}: {{ $itemResults[$action['id']]['code'] }} {{ $itemResults[$action['id']]['stripe_id'] ?? '' }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>

                @if ($canLink)
                    <x-account.notice tone="neutral">{{ __('owner.stripe_catalogue.link_only_notice') }}</x-account.notice>
                    <x-account.notice tone="warning">{{ __('owner.stripe_catalogue.approval_'.$operation->mode) }}</x-account.notice>
                    <label class="flex items-start gap-2 text-sm">
                        <input type="checkbox" name="confirm" value="1" class="mt-0.5 size-4">
                        <span>{{ __('owner.stripe_catalogue.confirm') }}</span>
                    </label>
                    <x-form.field name="confirm_text" :label="__('owner.stripe_catalogue.confirm_text', ['text' => 'LINK '.strtoupper($operation->mode)]).' '.__('owner.stripe_catalogue.confirm_text_create', ['text' => 'APPLY '.strtoupper($operation->mode)])" maxlength="20" class="font-mono" autocomplete="off" />
                    <div><x-ui.button type="submit">{{ __('owner.stripe_catalogue.apply_selected') }}</x-ui.button></div>
                @elseif ($operation->status === \App\Models\StripeCatalogueOperation::PREVIEWED)
                    <x-account.notice tone="neutral">{{ __('owner.stripe_catalogue.cannot_apply') }}</x-account.notice>
                @endif
            </form>
        </x-operator.section>
    @endif

    @if ($result)
        <x-operator.section :title="__('owner.stripe_catalogue.result')" id="result">
            @if (isset($result['error']))
                <x-account.notice tone="danger">{{ __('owner.stripe_catalogue.errors.'.$result['error']) }} <span class="font-mono text-xs">{{ strtoupper($result['error']) }}</span></x-account.notice>
            @else
                <p class="text-sm">{{ __('owner.stripe_catalogue.result_counts', ['done' => $result['done'] ?? 0, 'failed' => $result['failed'] ?? 0]) }}</p>
            @endif
        </x-operator.section>
    @endif
</x-layouts.owner>
