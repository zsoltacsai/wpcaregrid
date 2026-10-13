<x-layouts.owner :title="__('owner.m11_lifecycle_validation.title')">
    <x-operator.page-header :title="__('owner.m11_lifecycle_validation.title')" :lead="__('owner.m11_lifecycle_validation.lead')" />
    <div class="mb-5 rounded-md border border-warning/40 bg-warning-soft p-4 text-sm text-warning-ink">
        <p>{{ __('owner.m11_lifecycle_validation.warning') }}</p>
        <p class="mt-2">{{ $available ? __('owner.m11_lifecycle_validation.enabled') : __('owner.m11_lifecycle_validation.disabled') }}</p>
    </div>
    @if (session('status'))<p class="mb-4 rounded-md border border-positive/30 bg-positive-soft p-3 text-sm">{{ session('status') }}</p>@endif
    @if ($errors->any())<div class="mb-4 rounded-md border border-danger/30 bg-danger-soft p-3 text-sm" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if ($available)
        <form method="POST" action="{{ route('owner.billing.m11-lifecycle-validation.run') }}" class="space-y-4 rounded-md border border-line bg-surface p-5">
            @csrf
            <label class="block text-sm">{{ __('owner.m11_lifecycle_validation.email') }}
                <input name="email" type="email" required autocomplete="off" value="{{ old('email') }}" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 sm:max-w-md">
            </label>
            <label class="block text-sm">{{ __('owner.m11_lifecycle_validation.scenario') }}
                <select name="scenario" required class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 sm:max-w-md">
                    @foreach ($scenarios as $scenario)<option value="{{ $scenario }}" @selected(old('scenario') === $scenario)>{{ __('owner.m11_lifecycle_validation.scenarios.'.$scenario) }}</option>@endforeach
                </select>
            </label>
            <label class="block text-sm">{{ __('owner.m11_lifecycle_validation.phrase', ['phrase' => $confirmation]) }}
                <input name="confirmation_phrase" required autocomplete="off" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 sm:max-w-md">
            </label>
            <button class="h-10 rounded-sm border border-line-strong px-4 text-sm font-semibold">{{ __('owner.m11_lifecycle_validation.run_button') }}</button>
        </form>
    @endif
    <section class="mt-8 space-y-3">
        <h2 class="text-lg font-semibold">{{ __('owner.m11_lifecycle_validation.history') }}</h2>
        @forelse ($runs as $run)
            <article class="rounded-md border border-line bg-surface p-4 text-sm">
                <dl class="grid gap-2 sm:grid-cols-2">
                    <div><dt class="text-muted">{{ __('owner.m11_lifecycle_validation.run_id') }}</dt><dd class="font-mono">{{ $run->subject_key }}</dd></div>
                    <div><dt class="text-muted">{{ __('owner.m11_lifecycle_validation.at') }}</dt><dd>{{ $run->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') }}</dd></div>
                    <div><dt class="text-muted">{{ __('owner.m11_lifecycle_validation.scenario_label') }}</dt><dd>{{ data_get($run->data, 'scenario') }}</dd></div>
                    <div><dt class="text-muted">{{ __('owner.m11_lifecycle_validation.outcome') }}</dt><dd>{{ data_get($run->data, 'outcome') }} · {{ data_get($run->data, 'reason') }}</dd></div>
                    @if (data_get($run->data, 'subscription_uuid'))<div><dt class="text-muted">{{ __('owner.m11_lifecycle_validation.subscription') }}</dt><dd class="font-mono">{{ data_get($run->data, 'subscription_uuid') }}</dd></div>@endif
                    @if (data_get($run->data, 'simulated_status'))
                        <div><dt class="text-muted">{{ __('owner.m11_lifecycle_validation.simulated_state') }}</dt><dd>{{ data_get($run->data, 'simulated_status') }} · {{ __('owner.m11_lifecycle_validation.commercial_access') }}: {{ data_get($run->data, 'simulated_commercial_access') ? __('owner.m11_lifecycle_validation.yes') : __('owner.m11_lifecycle_validation.no') }} · {{ __('owner.m11_lifecycle_validation.restore_entitlement') }}: {{ data_get($run->data, 'simulated_restore_entitlement') ? __('owner.m11_lifecycle_validation.yes') : __('owner.m11_lifecycle_validation.no') }}</dd></div>
                    @endif
                </dl>
            </article>
        @empty
            <p class="rounded-md border border-line bg-surface p-4 text-sm">{{ __('owner.m11_lifecycle_validation.no_runs') }}</p>
        @endforelse
    </section>
</x-layouts.owner>
