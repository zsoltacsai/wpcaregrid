<x-layouts.account :title="__('account.privacy.title')">
    <x-account.page-header :title="__('account.privacy.title')" :lead="__('account.privacy.lead')" />

    <x-account.panel class="mb-4">
        <p class="text-sm text-muted">{{ __('account.privacy.scope_notice') }}</p>
        <p class="mt-2 text-xs text-muted">{{ __('account.privacy.safety_notice') }}</p>
        <a class="mt-3 inline-block text-sm text-link underline" href="{{ route('account.legal-documents.index') }}">{{ __('account.legal_documents.title') }}</a>
    </x-account.panel>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-account.panel>
            <h2 class="font-semibold text-primary">{{ __('account.privacy.export_title') }}</h2>
            <p class="mt-2 text-sm text-muted">{{ __('account.privacy.export_description') }}</p>
            <form method="POST" action="{{ route('account.privacy.export.request') }}" class="mt-4 grid gap-3">
                @csrf
                <x-form.field name="current_password" type="password" :label="__('account.auth.password')" autocomplete="current-password" required />
                <x-ui.button type="submit" size="sm" class="self-start">{{ __('account.privacy.request_export') }}</x-ui.button>
            </form>
        </x-account.panel>

        <x-account.panel>
            <h2 class="font-semibold text-primary">{{ __('account.privacy.rectification_title') }}</h2>
            <p class="mt-2 text-sm text-muted">{{ __('account.privacy.rectification_description') }}</p>
            <form method="POST" action="{{ route('account.privacy.rectification.request') }}" class="mt-4 grid gap-3">
                @csrf
                <x-form.field name="current_password" type="password" :label="__('account.auth.password')" autocomplete="current-password" required />
                <x-ui.button type="submit" size="sm" class="self-start">{{ __('account.privacy.start_rectification') }}</x-ui.button>
            </form>
        </x-account.panel>
    </div>

    <section class="mt-6">
        <h2 class="mb-3 text-lg font-semibold text-primary">{{ __('account.privacy.history') }}</h2>
        <div class="space-y-3">
            @forelse ($cases as $case)
                <x-account.panel>
                    <dl class="grid gap-3 text-sm sm:grid-cols-3">
                        <div><dt class="text-xs text-muted">{{ __('account.privacy.case_type') }}</dt><dd>{{ __('privacy.types.'.$case->request_type->value) }}</dd></div>
                        <div><dt class="text-xs text-muted">{{ __('account.privacy.case_status') }}</dt><dd>{{ __('privacy.request_statuses.'.$case->status->value) }}</dd></div>
                        <div><dt class="text-xs text-muted">{{ __('account.privacy.requested_at') }}</dt><dd>@displayDateTime($case->requested_at)</dd></div>
                    </dl>
                    @if ($case->exportArtifact)
                        @php($artifact = $case->exportArtifact)
                        <dl class="mt-4 grid gap-3 border-t border-line pt-3 text-sm sm:grid-cols-3">
                            <div><dt class="text-xs text-muted">{{ __('account.privacy.export_status') }}</dt><dd>{{ __('account.privacy.export_states.'.$artifact->status->value) }}</dd></div>
                            <div><dt class="text-xs text-muted">{{ __('account.privacy.coverage') }}</dt><dd>{{ __('account.privacy.partial_coverage') }}</dd></div>
                            <div><dt class="text-xs text-muted">{{ __('account.privacy.expires_at') }}</dt><dd>{{ app(\App\Support\DisplayDateTime::class)->format($artifact->expires_at) ?? '—' }}</dd></div>
                        </dl>
                        @if ($downloadUrls[$case->uuid] ?? false)
                            <div class="mt-4 flex flex-wrap items-center gap-3">
                                <a class="text-sm font-medium text-action underline" href="{{ $downloadUrls[$case->uuid] }}">{{ __('account.privacy.download') }}</a>
                                <span class="text-xs text-muted">{{ __('account.privacy.link_expires', ['date' => app(\App\Support\DisplayDateTime::class)->format($artifact->download_expires_at)]) }}</span>
                            </div>
                            <form method="POST" action="{{ route('account.privacy.exports.link', $artifact) }}" class="mt-4 flex flex-wrap items-end gap-3">
                                @csrf
                                <div class="w-full max-w-sm"><x-form.field name="current_password" type="password" :label="__('account.auth.password')" autocomplete="current-password" required /></div>
                                <x-ui.button type="submit" size="sm">{{ __('account.privacy.renew_link') }}</x-ui.button>
                            </form>
                        @elseif ($artifact->status === \App\Privacy\PrivacyExportStatus::Available && $artifact->expires_at?->isFuture())
                            <p class="mt-3 text-sm text-muted">{{ __('account.privacy.link_expired') }}</p>
                            <form method="POST" action="{{ route('account.privacy.exports.link', $artifact) }}" class="mt-3 flex flex-wrap items-end gap-3">
                                @csrf
                                <div class="w-full max-w-sm"><x-form.field name="current_password" type="password" :label="__('account.auth.password')" autocomplete="current-password" required /></div>
                                <x-ui.button type="submit" size="sm">{{ __('account.privacy.renew_link') }}</x-ui.button>
                            </form>
                        @endif
                    @endif
                </x-account.panel>
            @empty
                <x-account.panel><p class="text-sm text-muted">{{ __('account.privacy.empty') }}</p></x-account.panel>
            @endforelse
        </div>
        <div class="mt-4">{{ $cases->links() }}</div>
    </section>
</x-layouts.account>
