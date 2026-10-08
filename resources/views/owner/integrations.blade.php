<x-layouts.owner :title="__('owner.integrations.title')">
    <x-operator.page-header :title="__('owner.integrations.title')" :lead="__('owner.integrations.lead')" />
    <p class="mb-5 max-w-3xl text-sm text-muted">{{ __('owner.integrations.warning') }}</p>
    <p class="mb-5 max-w-3xl rounded-md border border-line bg-surface p-3 text-sm text-muted">{{ __('owner.integrations.runtime_note') }}</p>

    <div class="space-y-4">
        @foreach ($fields as $id => $field)
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
