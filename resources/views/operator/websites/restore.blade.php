{{--
    Operator restore confirmation. Same safety model as the customer area:
    one verified backup of this website, an explicit scope, the address typed
    out, a safety backup first (RestoreService), post-restore checks after.
--}}
@use('App\Backups\BackupText')
@use('App\Operator\Refusal')

<x-layouts.operator :title="__('operator.restore.title')">
    <x-operator.page-header :title="__('operator.restore.title')" :lead="__('operator.restore.lead', ['host' => $website->host()])">
        <x-slot:eyebrow>
            <a href="{{ route('operator.websites.show', $website) }}#backups" class="underline underline-offset-2 hover:text-primary">{{ __('operator.restore.back') }}</a>
        </x-slot:eyebrow>
    </x-operator.page-header>

    <div class="grid items-start gap-5 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
        <x-operator.section>
            @if ($refusal)
                <x-account.notice tone="warning">
                    <strong class="font-semibold">{{ __('operator.kinds.'.Refusal::kind($refusal)) }}:</strong> {{ Refusal::message($refusal) }}
                    <span class="ml-1 font-mono text-[11px]">{{ $refusal }}</span>
                </x-account.notice>
            @else
                <form method="POST" action="{{ route('operator.websites.backups.restore.store', [$website, $backup]) }}" class="grid gap-5">
                    @csrf
                    <fieldset>
                        <legend class="text-sm font-medium text-primary">{{ __('backups.restore.scope') }}</legend>
                        <div class="mt-2 grid gap-2">
                            @foreach ($scopes as $scope)
                                <label class="flex cursor-pointer gap-3 rounded-sm border border-line-strong p-3 hover:border-muted has-checked:border-accent has-checked:bg-accent-soft">
                                    <input type="radio" name="scope" value="{{ $scope->value }}" @checked(old('scope', $scopes[0]->value) === $scope->value) class="mt-1 accent-[var(--color-action)]">
                                    <span>
                                        <span class="block text-sm font-semibold text-primary">{{ __('backups.restore.scopes.'.$scope->value.'.label') }}</span>
                                        <span class="block text-xs text-muted">{{ __('backups.restore.scopes.'.$scope->value.'.text') }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <x-form.field name="confirmation" :label="__('backups.restore.confirm_label', ['host' => $website->host()])" autocomplete="off" spellcheck="false" required />

                    <label class="flex items-start gap-3 text-sm">
                        <input type="checkbox" name="understood" value="1" class="mt-0.5 size-4 accent-[var(--color-action)]" @checked(old('understood')) required>
                        <span>{{ __('backups.restore.understood') }}</span>
                    </label>

                    <div class="flex flex-wrap gap-3">
                        <x-ui.button type="submit" variant="danger" icon="refresh">{{ __('backups.restore.submit') }}</x-ui.button>
                        <x-ui.button :href="route('operator.websites.show', $website)" variant="secondary">{{ __('backups.restore.cancel') }}</x-ui.button>
                    </div>
                </form>
            @endif
        </x-operator.section>

        <aside class="grid gap-3">
            <x-operator.section :title="__('backups.restore.backup')">
                <dl class="grid gap-3 text-sm">
                    <x-account.detail :label="__('operator.backups.created')"><span class="font-mono">@displayDateTime($backup->created_at)</span></x-account.detail>
                    <x-account.detail :label="__('operator.backups.type')">{{ $backup->type->label() }}</x-account.detail>
                    <x-account.detail :label="__('operator.backups.reason')">{{ $backup->reason->label() }}</x-account.detail>
                    <x-account.detail :label="__('operator.backups.size')"><span class="font-mono">{{ BackupText::size($backup->total_size) }}</span></x-account.detail>
                    <x-account.detail :label="__('operator.backups.status')"><x-ui.status-badge :tone="$backup->status->tone()">{{ $backup->status->label() }}</x-ui.status-badge></x-account.detail>
                    <x-account.detail :label="__('operator.backups.verified')">@if ($backup->verified_at)<span class="font-mono">@displayDateTime($backup->verified_at)</span>@endif</x-account.detail>
                </dl>
            </x-operator.section>
            <x-account.notice>{{ __('backups.restore.safety') }}</x-account.notice>
            <x-account.notice>{{ __('backups.restore.maintenance') }}</x-account.notice>
        </aside>
    </div>
</x-layouts.operator>
