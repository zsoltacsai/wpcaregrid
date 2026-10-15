<x-layouts.account :title="__('account.legal_documents.title')">
    <x-account.page-header :title="__('account.legal_documents.title')" :lead="__('account.legal_documents.lead')" />
    <x-account.panel class="mb-5"><p class="text-sm text-muted">{{ __('account.legal_documents.unknown_notice') }}</p></x-account.panel>

    <section class="space-y-3">
        <h2 class="text-lg font-semibold text-primary">{{ __('account.legal_documents.current') }} · {{ strtoupper($locale) }}</h2>
        @forelse ($current as $type => $document)
            @php($acceptance = $acceptances->get($document->id))
            <x-account.panel>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div><h3 class="font-semibold">{{ $document->title }}</h3><p class="mt-1 text-xs text-muted">{{ str_replace('_', ' ', $type) }} · v{{ $document->version }} · {{ $document->effective_at?->utc()->format('Y-m-d').' UTC' }} · {{ $acceptance ? __('account.legal_documents.accepted') : ($document->acceptance_mode === 'notice_only' ? __('account.legal_documents.notice_only') : __('account.legal_documents.not_recorded')) }}</p></div>
                    <a class="text-sm text-link underline" href="{{ route(match ($type) { 'terms_of_service' => 'legal.terms', 'privacy_notice' => 'legal.privacy', 'cookie_notice' => 'legal.cookies', default => 'legal.dpa' }) }}">{{ __('account.legal_documents.open') }}</a>
                </div>
                @if ($document->acceptance_mode === 'acceptance_required_before_account_use' && ! $acceptance)
                    <form class="mt-4" method="POST" action="{{ route('account.legal-documents.accept', $document) }}">
                        @csrf
                        <input type="hidden" name="content_sha256" value="{{ $document->content_sha256 }}">
                        <label class="text-sm"><input type="checkbox" name="confirm" value="1" required> {{ __('account.legal_documents.confirm_acceptance') }}</label>
                        <div class="mt-3"><x-ui.button type="submit" size="sm">{{ __('account.legal_documents.accept') }}</x-ui.button></div>
                    </form>
                @endif
                @if ($document->acceptance_mode === 'acceptance_required_before_checkout' && ! $acceptance)<p class="mt-4 text-sm text-muted">{{ __('account.legal_documents.checkout_acceptance') }}</p>@endif
            </x-account.panel>
        @empty
            <x-account.panel><p class="text-sm text-muted">{{ __('account.legal_documents.none') }}</p></x-account.panel>
        @endforelse
    </section>

    <section class="mt-8 space-y-3">
        <h2 class="text-lg font-semibold text-primary">{{ __('account.legal_documents.history') }}</h2>
        @forelse ($history as $acceptance)
            <x-account.panel><p class="text-sm">{{ $acceptance->documentVersion?->title ?? __('account.legal_documents.historical') }} · {{ $acceptance->document_type }} · {{ strtoupper($acceptance->locale) }} · v{{ $acceptance->documentVersion?->version ?? '—' }}</p><p class="mt-1 text-xs text-muted">{{ __('account.legal_documents.accepted_at') }} {{ $acceptance->accepted_at?->utc()->format('Y-m-d H:i').' UTC' }} · SHA-256 {{ $acceptance->content_sha256 }}</p>@if($acceptance->documentVersion?->isPubliclyVisible())<a class="mt-2 inline-block text-sm text-link underline" href="{{ route('legal.history', $acceptance->documentVersion->uuid) }}">{{ __('account.legal_documents.open_history') }}</a>@endif</x-account.panel>
        @empty
            <x-account.panel><p class="text-sm text-muted">{{ __('account.legal_documents.no_history') }}</p></x-account.panel>
        @endforelse
    </section>
</x-layouts.account>
