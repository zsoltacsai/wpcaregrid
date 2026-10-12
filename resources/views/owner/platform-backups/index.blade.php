<x-layouts.owner :title="__('owner.platform_backups.title')">
    <x-operator.page-header :title="__('owner.platform_backups.title')" :lead="__('owner.platform_backups.lead')" />

    @if (session('status'))
        <p role="status" class="mt-4 rounded-md border border-positive/30 bg-positive/10 px-4 py-3 text-sm">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p role="alert" class="mt-4 rounded-md border border-danger/30 bg-danger/10 px-4 py-3 text-sm">{{ session('error') }}</p>
    @endif
    @error('settings') <p role="alert" class="mt-4 text-sm text-danger">{{ $message }}</p> @enderror

    <section class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="{{ __('owner.platform_backups.overview') }}">
        <article class="rounded-md border border-line bg-surface p-4"><h2 class="text-xs font-medium text-muted">{{ __('owner.platform_backups.readiness') }}</h2><p class="mt-2 text-lg font-semibold">{{ __('owner.platform_backups.states.'.$readiness) }}</p><p class="mt-1 text-xs text-muted">{{ __('owner.platform_backups.rpo_notice') }}</p></article>
        <article class="rounded-md border border-line bg-surface p-4"><h2 class="text-xs font-medium text-muted">{{ __('owner.platform_backups.primary_destination') }}</h2><p class="mt-2 text-lg font-semibold">{{ __('owner.platform_backups.states.'.$destinationState) }}</p><p class="mt-1 text-xs text-muted">{{ __('owner.platform_backups.disk', ['disk' => $diskName]) }}</p></article>
        <article class="rounded-md border border-line bg-surface p-4"><h2 class="text-xs font-medium text-muted">{{ __('owner.platform_backups.last_verified') }}</h2><p class="mt-2 text-sm font-semibold">{{ $latestVerified?->verified_at?->toIso8601String() ?? __('owner.never') }}</p><p class="mt-1 text-xs text-muted">{{ $latestVerified?->uuid ?? '—' }}</p></article>
        <article class="rounded-md border border-line bg-surface p-4"><h2 class="text-xs font-medium text-muted">{{ __('owner.platform_backups.next_scheduled') }}</h2><p class="mt-2 text-sm font-semibold">{{ $nextRun }}</p><p class="mt-1 text-xs text-muted">{{ __('owner.platform_backups.utc') }}</p></article>
    </section>

    <section class="mt-6 rounded-md border border-line bg-surface p-4 sm:p-6">
        <h2 class="text-base font-semibold">{{ __('owner.platform_backups.configuration') }}</h2>
        <p class="mt-1 text-sm text-muted">{{ __('owner.platform_backups.no_auto_delete') }}</p>
        <form method="POST" action="{{ route('owner.platform-backups.settings.update') }}" class="mt-5 space-y-5">
            @csrf @method('PUT')
        <fieldset class="mt-5 rounded border border-line p-3">
            <legend class="px-1 text-sm font-medium">{{ __('owner.platform_backups.destination_configuration') }}</legend>
            <p class="mb-3 text-xs text-muted">{{ __('owner.platform_backups.destination_help') }}</p>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block text-sm"><span class="font-medium">{{ __('owner.platform_backups.storage_driver') }}</span><select name="settings[backups.primary.storage_driver]" class="mt-1 block w-full rounded border-line bg-canvas"><option value="s3" @selected($settings['backups.primary.storage_driver'] === 's3')>S3</option><option value="local" @selected($settings['backups.primary.storage_driver'] === 'local')>Local (local/test only)</option></select></label>
                <label class="block text-sm"><span class="font-medium">{{ __('owner.platform_backups.endpoint') }}</span><input type="url" name="settings[backups.primary.endpoint]" value="{{ $settings['backups.primary.endpoint'] }}" maxlength="253" class="mt-1 block w-full rounded border-line bg-canvas" placeholder="https://fsn1.your-objectstorage.com"></label>
                <label class="block text-sm"><span class="font-medium">{{ __('owner.platform_backups.region') }}</span><input name="settings[backups.primary.region]" value="{{ $settings['backups.primary.region'] }}" maxlength="64" class="mt-1 block w-full rounded border-line bg-canvas"></label>
                <label class="block text-sm"><span class="font-medium">{{ __('owner.platform_backups.bucket') }}</span><input name="settings[backups.primary.bucket]" value="{{ $settings['backups.primary.bucket'] }}" maxlength="63" class="mt-1 block w-full rounded border-line bg-canvas"></label>
                <label class="flex items-center gap-2 text-sm"><input type="hidden" name="settings[backups.primary.path_style]" value="0"><input type="checkbox" name="settings[backups.primary.path_style]" value="1" @checked($settings['backups.primary.path_style'])>{{ __('owner.platform_backups.path_style') }}</label>
            </div>
            <div class="mt-4 grid gap-2 text-sm sm:grid-cols-2">
                @foreach (['platform_backup_s3_access_key', 'platform_backup_s3_secret_key'] as $credentialId)
                    @php($credential = $credentialStatus[$credentialId] ?? ['owner_managed' => false, 'environment_fallback' => false])
                    <p>{{ $credentialId === 'platform_backup_s3_access_key' ? 'Access key' : 'Secret key' }}:
                        @if ($credential['owner_managed']) {{ __('owner.integrations.owner_managed') }}
                        @elseif ($credential['environment_fallback']) {{ __('owner.integrations.environment_managed') }}
                        @else {{ __('owner.integrations.not_configured') }} @endif
                    </p>
                @endforeach
            </div>
        </fieldset>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block text-sm"><span class="font-medium">{{ __('owner.platform_backups.primary_frequency') }}</span><select name="settings[backups.primary.frequency]" class="mt-1 block w-full rounded border-line bg-canvas">@foreach (['6h', '12h', 'daily', 'weekly'] as $frequency)<option value="{{ $frequency }}" @selected($settings['backups.primary.frequency'] === $frequency)>{{ __('owner.platform_backups.frequencies.'.$frequency) }}</option>@endforeach</select></label>
                <fieldset class="rounded border border-line p-3"><legend class="px-1 text-sm font-medium">{{ __('owner.platform_backups.primary_destination') }}</legend><label class="flex items-center gap-2 text-sm"><input type="hidden" name="settings[backups.primary.enabled]" value="0"><input type="checkbox" name="settings[backups.primary.enabled]" value="1" @checked($settings['backups.primary.enabled'])>{{ __('owner.platform_backups.primary_enabled') }}</label><p class="mt-2 text-xs text-muted">{{ __('owner.platform_backups.disable_warning') }}</p></fieldset>
            </div>
            <fieldset class="rounded border border-line p-3"><legend class="px-1 text-sm font-medium">{{ __('owner.platform_backups.retention') }}</legend><div class="grid gap-3 sm:grid-cols-3">@foreach (['daily' => [7,365], 'weekly' => [4,104], 'monthly' => [12,120]] as $class => [$min,$max])<label class="block text-sm"><span>{{ __('owner.platform_backups.retention_classes.'.$class) }}</span><input type="number" min="{{ $min }}" max="{{ $max }}" name="settings[backups.retention.{{ $class }}]" value="{{ $settings['backups.retention.'.$class] }}" class="mt-1 block w-full rounded border-line bg-canvas"></label>@endforeach</div></fieldset>
            <div class="rounded border border-line p-3"><h3 class="text-sm font-medium">{{ __('owner.platform_backups.secondary_destination') }}</h3><p class="mt-1 text-sm text-muted">{{ __('owner.platform_backups.drive_unavailable') }}</p><input type="hidden" name="settings[backups.secondary.enabled]" value="0"><input type="hidden" name="settings[backups.secondary.frequency]" value="{{ $settings['backups.secondary.frequency'] }}"></div>
            <fieldset class="rounded border border-line p-3"><legend class="px-1 text-sm font-medium">{{ __('owner.platform_backups.app_key_escrow') }}</legend>
                <p class="mb-2 text-sm">{{ __('owner.platform_backups.app_key_status') }}: <strong>{{ $appKeyConfigured ? __('owner.integrations.app_key.configured') : __('owner.integrations.app_key.missing') }}</strong></p>
                @if (! $appKeyConfigured)<p role="alert" class="mb-2 text-sm text-danger-ink">{{ __('owner.platform_backups.app_key_missing') }}</p>@endif
                @if ($lastEscrowAttestation && $settings['backups.app_key_escrow_attested'])
                    <p class="mb-2 text-xs text-muted">{{ __('owner.platform_backups.escrow_verified_by', ['owner' => $lastEscrowAttestation->actor_user_id ?? '—', 'at' => $lastEscrowAttestation->created_at?->toIso8601String() ?? '—']) }}</p>
                @endif
                <label class="flex items-start gap-2 text-sm"><input type="hidden" name="settings[backups.app_key_escrow_attested]" value="0"><input type="checkbox" name="settings[backups.app_key_escrow_attested]" value="1" @checked($settings['backups.app_key_escrow_attested'])><span>{{ __('owner.platform_backups.escrow_attestation') }}</span></label>
            </fieldset>
            <label class="block text-sm"><span>{{ __('owner.platform_backups.confirmation') }}</span><input name="confirmation" autocomplete="off" class="mt-1 block w-full max-w-md rounded border-line bg-canvas" aria-describedby="confirm-help"><span id="confirm-help" class="mt-1 block text-xs text-muted">{{ __('owner.platform_backups.confirmation_help') }}</span></label>
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="warning_ack" value="1"><span>{{ __('owner.platform_backups.warning_ack') }}</span></label>
            <button class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white">{{ __('owner.platform_backups.save') }}</button>
        </form>
    </section>

    <section class="mt-6 rounded-md border border-line bg-surface p-4 sm:p-6">
        <h2 class="text-base font-semibold">{{ __('owner.platform_backups.connection_test') }}</h2>
        <p class="mt-1 text-sm text-muted">
            @if (! $providerConfigured)
                {{ __('owner.platform_backups.connection_not_configured') }}
            @elseif ($lastConnectionTest && $connectionTestMatchesCurrentDestination)
                {{ __('owner.platform_backups.connection_last') }}:
                {{ data_get($lastConnectionTest->data, 'result') === 'passed' ? __('owner.platform_backups.connection_result_passed') : __('owner.platform_backups.connection_result_failed') }}
                · {{ $lastConnectionTest->created_at?->toIso8601String() }}
            @elseif ($lastConnectionTest)
                {{ __('owner.platform_backups.connection_outdated') }}
            @else
                {{ __('owner.platform_backups.connection_not_tested') }}
            @endif
        </p>
        <p class="mt-1 text-xs text-muted">{{ __('owner.platform_backups.connection_explainer') }}</p>
        <form method="POST" action="{{ route('owner.platform-backups.test-connection') }}" class="mt-3">
            @csrf
            <button id="platform-backup-connection-test" class="rounded border border-line-strong px-4 py-2 text-sm font-semibold" @disabled(! $providerConfigured)>{{ __('owner.platform_backups.connection_test') }}</button>
        </form>
    </section>

    <section class="mt-6 rounded-md border border-warning/30 bg-warning/5 p-4 sm:p-6">
        <h2 class="text-base font-semibold">{{ __('owner.platform_backups.manual_backup') }}</h2>
        <p class="mt-1 text-sm text-muted">{{ __('owner.platform_backups.manual_warning') }}</p>
        @if (! $encryptionKeyConfigured)
            <p role="status" class="mt-2 text-sm text-warning-ink">{{ __('owner.platform_backups.encryption_key_missing') }}</p>
        @endif
        <form method="POST" action="{{ route('owner.platform-backups.run') }}" class="mt-4 flex flex-wrap items-end gap-3">
            @csrf
            <label class="block text-sm"><span>{{ __('owner.platform_backups.type_confirmation') }}</span><input name="confirmation" required autocomplete="off" class="mt-1 block w-full rounded border-line bg-canvas"></label>
            <button id="platform-backup-request" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white" @disabled(! $configured)>{{ __('owner.platform_backups.request_backup') }}</button>
        </form>
    </section>

    <section class="mt-6 rounded-md border border-line bg-surface p-4 sm:p-6">
        <h2 class="text-base font-semibold">{{ __('owner.platform_backups.history') }}</h2>
        <div class="mt-4 overflow-x-auto"><table class="w-full min-w-[760px] text-left text-sm"><thead><tr class="border-b border-line text-xs text-muted"><th class="py-2">{{ __('owner.platform_backups.id') }}</th><th>{{ __('owner.platform_backups.created') }}</th><th>{{ __('owner.platform_backups.size') }}</th><th>{{ __('owner.platform_backups.status') }}</th><th>{{ __('owner.platform_backups.verification') }}</th><th>{{ __('owner.platform_backups.retention_class') }}</th></tr></thead><tbody>
            @forelse ($backups as $backup)<tr class="border-b border-line/70"><td class="py-2 font-mono text-xs">{{ $backup->uuid }}</td><td>{{ $backup->created_for_backup_at?->toIso8601String() }}</td><td>{{ $backup->size_bytes ? number_format($backup->size_bytes) : '—' }}</td><td>{{ $backup->status }} / {{ $backup->deliveries->first()?->upload_status ?? 'legacy' }}</td><td>{{ $backup->verification_status }} / {{ $backup->deliveries->first()?->verification_status ?? 'unknown' }}</td><td>{{ $backup->retention_class }}</td></tr>@empty<tr><td colspan="6" class="py-4 text-muted">{{ __('owner.platform_backups.empty') }}</td></tr>@endforelse
        </tbody></table></div>
    </section>
</x-layouts.owner>
