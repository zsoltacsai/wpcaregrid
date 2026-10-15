<x-layouts.owner :title="'Legal document draft'">
    <x-operator.page-header :title="str_replace('_', ' ', ucfirst($document->document_type)).' · '.strtoupper($document->locale).' · v'.$document->version" lead="Draft content is private. Approved and published content cannot be edited; create a successor version instead." />

    <div class="mb-5 rounded-md border border-warning/40 bg-warning-soft p-4 text-sm text-warning-ink">
        <strong>Legal review gate</strong>
        <p class="mt-1">Only record a non-secret reference to qualified review that actually occurred. This application cannot verify the reference or decide that the content is legally sufficient. Do not paste privileged legal advice into this record.</p>
    </div>

    <section class="rounded-md border border-line bg-surface p-5">
        <dl class="mb-5 grid gap-3 text-sm sm:grid-cols-3">
            <div><dt class="text-xs text-muted">State</dt><dd>{{ strtoupper($document->status) }}</dd></div>
            <div><dt class="text-xs text-muted">Effective date</dt><dd>{{ $document->effective_at?->utc()->format('Y-m-d H:i').' UTC' ?? 'Not set' }}</dd></div>
            <div><dt class="text-xs text-muted">Acceptance records</dt><dd>{{ $acceptances }}</dd></div>
            @if ($document->content_sha256)<div class="sm:col-span-3"><dt class="text-xs text-muted">Immutable content SHA-256</dt><dd class="break-all font-mono text-xs">{{ $document->content_sha256 }}</dd></div>@endif
        </dl>

        @if ($document->status === 'draft')
            <form method="POST" action="{{ route('owner.legal-documents.update', $document) }}" class="grid gap-4">
                @csrf @method('PUT')
                <label class="text-sm">Title<input name="title" maxlength="180" value="{{ old('title', $document->title) }}" required class="mt-1 block w-full rounded-sm border border-line-strong px-3 py-2"></label>
                <label class="text-sm">Body (safe HTML subset; scripts and unsafe links are removed)<textarea name="body" rows="18" required class="mt-1 block w-full rounded-sm border border-line-strong px-3 py-2 font-mono text-sm">{{ old('body', $document->body) }}</textarea></label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="text-sm">Acceptance mode<select name="acceptance_mode" required class="mt-1 block w-full rounded-sm border border-line-strong px-3 py-2">@foreach ($modes as $mode)<option value="{{ $mode }}" @selected(old('acceptance_mode', $document->acceptance_mode) === $mode)>{{ str_replace('_', ' ', $mode) }}</option>@endforeach</select></label>
                    <label class="text-sm">Effective time (UTC)<input type="datetime-local" name="effective_at" value="{{ old('effective_at', $document->effective_at?->utc()->format('Y-m-d\TH:i')) }}" required class="mt-1 block w-full rounded-sm border border-line-strong px-3 py-2"></label>
                </div>
                <fieldset><legend class="text-sm font-medium">Applicability metadata</legend><div class="mt-2 flex flex-wrap gap-4 text-sm">@foreach (['all', 'individual', 'business', 'processor'] as $audience)<label><input type="checkbox" name="audiences[]" value="{{ $audience }}" @checked(in_array($audience, old('audiences', $document->audiences ?? ['all']), true))> {{ str_replace('_', ' ', $audience) }}</label>@endforeach</div></fieldset>
                <div><x-ui.button type="submit" size="sm">Save draft</x-ui.button></div>
            </form>
            <form method="POST" action="{{ route('owner.legal-documents.approve', $document) }}" class="mt-8 border-t border-line pt-5">
                @csrf
                <label class="block text-sm">Non-secret qualified legal review reference<input name="approval_reference" maxlength="128" required pattern="[A-Za-z0-9._:/# -]+" class="mt-1 block w-full max-w-xl rounded-sm border border-line-strong px-3 py-2"></label>
                <p class="mt-2 text-xs text-muted">Submitting records an Owner attestation that the cited legal review exists. It freezes this content and its acceptance policy.</p>
                <button class="mt-3 rounded-sm border border-line-strong px-3 py-2 text-sm">Record reviewed approval</button>
            </form>
        @elseif ($document->status === 'approved')
            <h2 class="font-semibold">Review recorded</h2><p class="mt-2 text-sm">Reference: {{ $document->approval_reference }} · recorded {{ $document->approved_at?->utc()->format('Y-m-d H:i').' UTC' }} · approver #{{ $document->approved_by_user_id }}</p>
            <div class="mt-4 rounded-sm border border-line p-4 text-sm"><strong>Publication confirmation</strong><p class="mt-1">This version becomes visible only after publication and its effective time. Acceptance mode: {{ $document->acceptance_mode }}. Current affected-customer count is not computed.</p><form method="POST" action="{{ route('owner.legal-documents.publish', $document) }}" class="mt-3">@csrf<label class="text-sm"><input type="checkbox" name="confirm_publish" value="1" required> I confirm this exact version is approved for publication.</label><div><button class="mt-3 rounded-sm bg-primary px-4 py-2 text-sm font-semibold text-white">Publish version</button></div></form></div>
            <div class="mt-4 rounded-sm border border-line p-4 text-sm"><h2 class="font-semibold">Private preview</h2><div class="prose mt-2 max-w-none">{!! \App\Content\HtmlSanitizer::clean($document->body) !!}</div></div>
        @else
            <h2 class="font-semibold">{{ strtoupper($document->status) }} version</h2><p class="mt-2 text-sm">Published {{ $document->published_at?->utc()->format('Y-m-d H:i').' UTC' ?? '—' }} · checksum {{ $document->content_sha256 }}.</p>
            <div class="mt-4 rounded-sm border border-line p-4 text-sm"><div class="prose max-w-none">{!! \App\Content\HtmlSanitizer::clean($document->body) !!}</div></div>
            @if ($document->status === 'published')<form method="POST" action="{{ route('owner.legal-documents.retire', $document) }}" class="mt-5">@csrf<label class="text-sm"><input type="checkbox" name="confirm_retire" value="1" required> Confirm retirement from current-version resolution.</label><div><button class="mt-3 rounded-sm border border-line-strong px-3 py-2 text-sm">Retire version</button></div></form>@endif
        @endif
    </section>
</x-layouts.owner>
