<x-layouts.owner :title="__('owner.integrations.title')">
    <x-operator.page-header :title="__('owner.integrations.title')" :lead="__('owner.integrations.lead')" />
    <p class="mb-5 max-w-3xl text-sm text-muted">{{ __('owner.integrations.warning') }}</p>
    <p class="mb-5 max-w-3xl rounded-md border border-line bg-surface p-3 text-sm text-muted">{{ __('owner.integrations.runtime_note') }}</p>

    <section class="mb-5 max-w-3xl rounded-md border {{ $appKeyConfigured ? 'border-positive/30 bg-positive/5' : 'border-danger/40 bg-danger/5' }} p-4" aria-label="{{ __('owner.integrations.app_key.title') }}">
        <h2 class="font-semibold">{{ __('owner.integrations.app_key.title') }}</h2>
        @if ($appKeyConfigured)
            <p class="mt-1 text-sm">{{ __('owner.integrations.app_key.configured') }}</p>
        @else
            <p class="mt-1 text-sm font-semibold text-danger-ink">{{ __('owner.integrations.app_key.missing') }}</p>
            <p class="mt-1 text-sm text-danger-ink">{{ __('owner.integrations.app_key.missing_warning') }}</p>
        @endif
        <p class="mt-2 text-xs text-muted">{{ __('owner.integrations.app_key.never_displayed') }}</p>
    </section>

    <section class="mb-5 max-w-3xl rounded-md border border-line bg-surface p-5" aria-labelledby="ai-providers-heading">
        <h2 id="ai-providers-heading" class="text-lg font-semibold">{{ __('owner.integrations.ai.title') }}</h2>
        <p class="mt-2 text-sm text-muted">{{ __('owner.integrations.ai.boundary') }}</p>
        <div class="mt-4 space-y-4">
            @foreach ($aiProviders as $providerId => $provider)
                <article class="rounded-md border border-line p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 class="font-semibold">{{ $provider['label'] }}</h3>
                        <span class="text-sm">{{ $provider['state']['configured'] ? __('owner.integrations.ai.configured') : __('owner.integrations.not_configured') }}</span>
                    </div>
                    <p class="mt-1 text-sm text-muted">{{ __('owner.integrations.ai.health.'.$provider['state']['health_status']) }}</p>
                    @if ($provider['state']['checked_at'])
                        <p class="mt-1 text-xs text-muted">{{ __('owner.integrations.ai.last_checked') }}: <time datetime="{{ $provider['state']['checked_at'] }}">{{ $provider['state']['checked_at'] }} UTC</time></p>
                    @endif
                    @if ($provider['state']['configured'])
                        <form method="POST" action="{{ route('owner.integrations.ai.health', $providerId) }}" class="mt-3">
                            @csrf
                            <button type="submit" class="h-9 rounded-sm border border-line-strong px-3 text-sm font-medium">{{ __('owner.integrations.ai.test_connection') }}</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('owner.integrations.store') }}" class="mt-4 grid gap-3 sm:grid-cols-2">
                        @csrf
                        <input type="hidden" name="id" value="{{ $provider['credential_id'] }}">
                        <label class="block text-sm font-medium">
                            {{ $provider['state']['configured'] ? __('owner.integrations.ai.replace_secret') : __('owner.integrations.ai.new_secret') }}
                            <input type="password" name="value" value="" autocomplete="new-password" spellcheck="false" maxlength="8192" required class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 font-mono text-sm text-ink">
                        </label>
                        <label class="block text-sm font-medium">
                            {{ __('owner.integrations.ai.current_password') }}
                            <input type="password" name="current_password" value="" autocomplete="current-password" required class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm text-ink">
                        </label>
                        <button type="submit" class="h-10 w-fit rounded-sm bg-primary px-4 text-sm font-semibold text-white">{{ $provider['state']['configured'] ? __('owner.integrations.ai.replace') : __('owner.integrations.ai.save') }}</button>
                    </form>
                    @if ($provider['state']['configured'])
                        <form method="POST" action="{{ route('owner.integrations.destroy', $provider['credential_id']) }}" class="mt-3 flex flex-wrap items-end gap-3">
                            @csrf
                            @method('DELETE')
                            <label class="block text-sm font-medium">
                                {{ __('owner.integrations.ai.current_password') }}
                                <input type="password" name="current_password" value="" autocomplete="current-password" required class="mt-1 block h-10 rounded-sm border border-line-strong bg-surface px-3 text-sm text-ink">
                            </label>
                            <button type="submit" class="h-10 text-sm font-medium text-danger-ink underline">{{ __('owner.integrations.ai.remove') }}</button>
                        </form>
                        <p class="mt-2 text-xs text-muted">{{ __('owner.integrations.ai.remove_note') }}</p>
                    @endif
                </article>
            @endforeach
        </div>
    </section>

    <div class="space-y-4">
        @foreach ($fields as $id => $field)
            @if ($id === 'platform_backup_s3_access_key')
                <section class="rounded-md border border-primary/30 bg-primary/5 p-5">
                    <h2 class="font-semibold">{{ __('owner.integrations.platform_backup_s3.title') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('owner.integrations.platform_backup_s3.description') }}</p>
                </section>
            @endif
            <section class="rounded-md border border-line bg-surface p-5">
                @php($runtimeSupported = $field['runtime_supported'] ?? true)
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="font-semibold">{{ $field['label'] }}</h2>
                    <x-ui.status-badge :tone="$configured[$id]['owner_managed'] || $configured[$id]['environment_fallback'] ? 'positive' : 'neutral'">
                        @if ($configured[$id]['owner_managed'])
                            {{ __('owner.integrations.owner_managed') }}
                        @elseif ($configured[$id]['environment_fallback'])
                            {{ __('owner.integrations.environment_managed') }}
                        @else
                            {{ __('owner.integrations.not_configured') }}
                        @endif
                    </x-ui.status-badge>
                </div>
                @if (isset($field['description_key']))
                    <p class="mt-1 text-sm text-muted">{{ __($field['description_key']) }}</p>
                    @if ($configured[$id]['owner_managed'])
                        <p class="mt-2 font-mono text-sm text-muted" aria-label="{{ __('owner.integrations.szamlazz_agent.masked') }}">•••••••• <span class="font-sans">{{ __('owner.integrations.szamlazz_agent.masked') }}</span></p>
                    @endif
                @endif
                <p class="mt-1 text-sm text-muted">{{ __('owner.integrations.empty_means_keep') }}</p>
                @if ($configured[$id]['owner_managed'] && $configured[$id]['environment_fallback'])
                    <p class="mt-2 rounded-md border border-warning/40 bg-warning-soft p-3 text-sm text-warning-ink" role="status">{{ __('owner.integrations.environment_fallback_will_remain') }}</p>
                @elseif (! $configured[$id]['owner_managed'] && $configured[$id]['environment_fallback'])
                    <p class="mt-2 text-sm text-warning-ink">{{ __('owner.integrations.environment_fallback_active') }}</p>
                @endif
                @unless ($runtimeSupported)
                    @if ($configured[$id]['owner_managed'] || $configured[$id]['environment_fallback'])
                        <p class="mt-2 text-sm text-warning-ink">{{ __('owner.integrations.configured_not_consumed') }}</p>
                    @endif
                    <p class="mt-2 text-sm text-warning-ink">{{ __('owner.integrations.transport_unavailable') }}</p>
                @endunless

                <form method="POST" action="{{ route('owner.integrations.store') }}" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                    @csrf
                    <input type="hidden" name="id" value="{{ $id }}">
                    <label class="block min-w-0 flex-1 text-sm font-medium">
                        {{ isset($field['credential_label_key']) ? __($field['credential_label_key']) : __('owner.integrations.new_value') }}
                        <input type="password" name="value" value="" autocomplete="new-password" spellcheck="false" maxlength="8192" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 font-mono text-sm text-ink" required>
                    </label>
                    <button type="submit" class="h-10 rounded-sm bg-primary px-4 text-sm font-semibold text-white">{{ isset($field['replace_label_key']) && $configured[$id]['owner_managed'] ? __($field['replace_label_key']) : (isset($field['save_label_key']) ? __($field['save_label_key']) : __('owner.integrations.save')) }}</button>
                </form>

                @if ($configured[$id]['owner_managed'])
                    <form method="POST" action="{{ route('owner.integrations.destroy', $id) }}" class="mt-3" onsubmit="return confirm(@js(__('owner.integrations.confirm_clear')))">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-sm font-medium text-danger-ink underline">{{ isset($field['clear_label_key']) ? __($field['clear_label_key']) : __('owner.integrations.clear') }}</button>
                    </form>
                @endif
            </section>
        @endforeach
    </div>

    <p class="mt-6 rounded border border-line bg-surface p-4 text-sm text-muted">{{ __('owner.integrations.unsupported') }}</p>
</x-layouts.owner>
