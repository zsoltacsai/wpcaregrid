{{--
    Restore confirmation: one specific verified backup, an explicit scope,
    the website address typed out, and the safety backup explained first.
--}}
@use('App\Backups\BackupText')

<x-layouts.account :title="__('backups.restore.title')">
    <nav aria-label="Breadcrumb" class="mb-4 text-sm">
        <a href="{{ route('account.websites.backups.index', $website) }}" class="inline-flex items-center gap-1.5 rounded-sm font-medium text-muted hover:text-primary">
            <x-ui.icon name="arrow-right" class="size-4 rotate-180" /> {{ __('backups.account.title') }}
        </a>
    </nav>

    <x-account.page-header :title="__('backups.restore.title')" :lead="__('backups.restore.lead')" />

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
        <x-account.panel>
            @if ($refusal)
                <x-account.notice tone="warning">
                    <strong class="font-semibold">{{ __('backups.restore.not_possible') }}.</strong> {{ BackupText::refusal($refusal) }}
                </x-account.notice>
            @else
                <form method="POST" action="{{ route('account.websites.backups.restore.store', [$website, $backup]) }}" class="grid gap-6">
                    @csrf
                    <fieldset>
                        <legend class="text-sm font-medium text-primary">{{ __('backups.restore.scope') }}</legend>
                        <div class="mt-3 grid gap-3">
                            @foreach ($scopes as $scope)
                                <label class="flex cursor-pointer gap-3 rounded-md border border-line-strong p-4 hover:border-muted has-checked:border-accent has-checked:bg-accent-soft has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-accent-ink">
                                    <input type="radio" name="scope" value="{{ $scope->value }}" @checked(old('scope', $scopes[0]->value) === $scope->value) class="mt-1 accent-[var(--color-action)]">
                                    <span>
                                        <span class="block text-sm font-semibold text-primary">{{ __('backups.restore.scopes.'.$scope->value.'.label') }}</span>
                                        <span class="block text-sm text-muted">{{ __('backups.restore.scopes.'.$scope->value.'.text') }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('scope')<p class="mt-2 text-sm text-danger-ink">{{ $message }}</p>@enderror
                    </fieldset>

                    <x-form.field name="confirmation" :label="__('backups.restore.confirm_label', ['host' => $website->host()])" autocomplete="off" spellcheck="false" required />

                    <label class="flex items-start gap-3 text-sm">
                        <input type="checkbox" name="understood" value="1" class="mt-0.5 size-4 accent-[var(--color-action)]" @checked(old('understood')) required>
                        <span>{{ __('backups.restore.understood') }}</span>
                    </label>
                    @error('understood')<p class="-mt-4 text-sm text-danger-ink">{{ $message }}</p>@enderror

                    <div class="flex flex-wrap gap-3">
                        <x-ui.button type="submit" variant="danger" icon="refresh">{{ __('backups.restore.submit') }}</x-ui.button>
                        <x-ui.button :href="route('account.websites.backups.index', $website)" variant="secondary">{{ __('backups.restore.cancel') }}</x-ui.button>
                    </div>
                </form>
            @endif
        </x-account.panel>

        <aside class="grid gap-4">
            <x-account.panel :title="__('backups.restore.backup')" heading-id="restore-backup-title">
                <dl class="grid gap-4 text-sm">
                    <x-account.detail :label="__('backups.account.created')"><span class="font-mono">{{ $backup->created_at->translatedFormat('j M Y H:i') }}</span></x-account.detail>
                    <x-account.detail :label="__('backups.account.type')">{{ $backup->type->label() }}</x-account.detail>
                    <x-account.detail :label="__('backups.account.reason_column')">{{ $backup->reason->label() }}</x-account.detail>
                    <x-account.detail :label="__('backups.account.size')"><span class="font-mono">{{ BackupText::size($backup->total_size) }}</span></x-account.detail>
                    <x-account.detail :label="__('backups.account.status')"><x-ui.status-badge :tone="$backup->status->tone()">{{ $backup->status->label() }}</x-ui.status-badge></x-account.detail>
                </dl>
            </x-account.panel>
            <x-account.notice>{{ __('backups.restore.safety') }}</x-account.notice>
            <x-account.notice>{{ __('backups.restore.maintenance') }}</x-account.notice>
        </aside>
    </div>
</x-layouts.account>
