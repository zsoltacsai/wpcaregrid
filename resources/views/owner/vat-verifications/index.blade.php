<x-layouts.owner :title="__('owner.customers.vat.queue')">
    <x-operator.page-header :title="__('owner.customers.vat.queue')" :lead="__('owner.customers.vat.evidence_only')" />

    @if (session('status'))
        <p role="status" class="mb-4 rounded-sm border border-line bg-canvas p-3 text-sm text-primary">{{ session('status') }}</p>
    @endif

    @if ($reviews->isEmpty())
        <p class="rounded-md border border-line bg-surface p-6 text-sm text-muted">{{ __('owner.customers.vat.queue_empty') }}</p>
    @else
        <div class="grid gap-3">
            @foreach ($reviews as $review)
                @php
                    $identity = $review->billingIdentity;
                    $customer = $identity?->customer;
                    $evidence = $identity?->latestVatValidation();
                @endphp
                @if ($customer && $identity)
                    <article class="rounded-md border border-line bg-surface p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <a href="{{ route('owner.customers.show', $customer) }}" class="font-semibold text-primary hover:underline">{{ $customer->fullName() }} · {{ $customer->email }}</a>
                                <dl class="mt-2 grid grid-cols-[minmax(0,140px)_minmax(0,1fr)] gap-x-3 gap-y-1 text-xs">
                                    <dt class="text-muted">{{ __('owner.customers.vat.review_status') }}</dt><dd>{{ __('owner.customers.vat.review_statuses.'.$review->status->value) }}</dd>
                                    <dt class="text-muted">{{ __('owner.customers.vat.country') }}</dt><dd>{{ $evidence?->country_code ?? $identity->country_code }}</dd>
                                    <dt class="text-muted">{{ __('owner.customers.vat.identifier') }}</dt><dd class="font-mono">{{ \App\Tax\Vat\VatIdentifierMasker::mask($evidence?->country_code ?? $identity->country_code, $evidence?->vat_identifier ?? $identity->tax_identifier) }}</dd>
                                    <dt class="text-muted">{{ __('owner.customers.vat.source') }}</dt><dd>{{ $evidence ? ($evidence->provider === 'eu_vies' ? __('owner.customers.vat.vies') : __('owner.customers.vat.local_check')) : '—' }}</dd>
                                    <dt class="text-muted">{{ __('owner.customers.vat.last_check') }}</dt><dd>{{ app(\App\Support\DisplayDateTime::class)->format($evidence?->checked_at) ?? '—' }}</dd>
                                </dl>
                            </div>
                            <span class="text-xs text-muted">@displayDateTime($review->created_at)</span>
                        </div>

                        @if (auth('owner')->user()?->can('owner.customers.manage'))
                            <form method="POST" action="{{ route('owner.vat-verifications.resolve', $review) }}" class="mt-4 grid gap-3 border-t border-line pt-3 sm:grid-cols-[1fr_1fr_auto]">
                                @csrf
                                <x-form.select name="decision" :label="__('owner.customers.vat.manual_decision')" :options="['verified' => __('owner.customers.vat.review_statuses.verified'), 'rejected' => __('owner.customers.vat.review_statuses.rejected'), 'not_required' => __('owner.customers.vat.review_statuses.not_required')]" required />
                                <x-form.select name="reason_code" :label="__('owner.customers.vat.reason')" :options="['documents_reviewed' => __('owner.customers.vat.reasons.documents_reviewed'), 'documents_incomplete' => __('owner.customers.vat.reasons.documents_incomplete'), 'not_applicable' => __('owner.customers.vat.reasons.not_applicable')]" required />
                                <div class="self-end"><x-ui.button type="submit" size="sm">{{ __('owner.customers.vat.save_review') }}</x-ui.button></div>
                            </form>
                            <p class="mt-2 text-xs text-muted">{{ __('owner.customers.vat.manual_is_separate') }}</p>
                        @endif
                    </article>
                @endif
            @endforeach
        </div>
        <div class="mt-4">{{ $reviews->links() }}</div>
    @endif
</x-layouts.owner>
