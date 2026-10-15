<x-layouts.owner :title="'Legal documents'">
    <x-operator.page-header title="Legal documents" lead="Immutable, locale-specific legal text versions and acceptance evidence. No draft is public; publication and enforcement require recorded review." />

    <section class="mb-6 rounded-md border border-warning/40 bg-warning-soft p-4 text-sm text-warning-ink">
        <strong>Legal content is not supplied or approved by this implementation.</strong>
        <p class="mt-1">Add only counsel-approved text. Recording a reference records the Owner's statement; the system does not verify legal advice. Acceptance enforcement remains disabled unless the exact approved version and mode are intentionally published.</p>
    </section>

    <section class="mb-6 rounded-md border border-line bg-surface p-5">
        <h2 class="font-semibold">Create a draft</h2>
        <form method="POST" action="{{ route('owner.legal-documents.store') }}" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @csrf
            <label class="text-sm">Document type<select name="document_type" required class="mt-1 block w-full rounded-sm border border-line-strong px-3 py-2">@foreach ($types as $type)<option value="{{ $type }}">{{ str_replace('_', ' ', ucfirst($type)) }}</option>@endforeach</select></label>
            <label class="text-sm">Locale<select name="locale" required class="mt-1 block w-full rounded-sm border border-line-strong px-3 py-2">@foreach ($locales as $code => $name)<option value="{{ $code }}">{{ $name }} ({{ strtoupper($code) }})</option>@endforeach</select></label>
            <label class="text-sm">Title<input name="title" maxlength="180" required class="mt-1 block w-full rounded-sm border border-line-strong px-3 py-2"></label>
            <label class="text-sm">Acceptance mode<select name="acceptance_mode" required class="mt-1 block w-full rounded-sm border border-line-strong px-3 py-2">@foreach ($modes as $mode)<option value="{{ $mode }}" @selected($mode === 'disabled')>{{ str_replace('_', ' ', $mode) }}</option>@endforeach</select></label>
            <div class="sm:col-span-2 lg:col-span-4"><x-ui.button type="submit" size="sm">Create draft</x-ui.button></div>
        </form>
        <p class="mt-3 text-xs text-muted">No template legal wording is inserted. New drafts start empty, with acceptance disabled. Locale versions are prepared and published independently.</p>
    </section>

    <section class="overflow-hidden rounded-md border border-line bg-surface">
        <div class="border-b border-line p-5"><h2 class="font-semibold">Document versions</h2><p class="mt-1 text-sm text-muted">{{ $versions->total() }} version records · acceptance events are append-only.</p></div>
        <div class="overflow-x-auto"><table class="w-full min-w-[900px] text-left text-sm">
            <thead class="bg-canvas text-xs text-muted"><tr><th class="p-3">Document</th><th class="p-3">Version</th><th class="p-3">State</th><th class="p-3">Effective UTC</th><th class="p-3">Approval reference</th><th class="p-3">Acceptances</th><th class="p-3">Actions</th></tr></thead>
            <tbody class="divide-y divide-line">
                @forelse ($versions as $version)
                    <tr>
                        <td class="p-3">{{ str_replace('_', ' ', ucfirst($version->document_type)) }} · {{ strtoupper($version->locale) }}<div class="text-xs text-muted">{{ $version->title }}</div></td>
                        <td class="p-3">v{{ $version->version }}<div class="font-mono text-xs text-muted">{{ $version->uuid }}</div></td>
                        <td class="p-3">{{ strtoupper($version->status) }}<div class="text-xs text-muted">{{ str_replace('_', ' ', $version->acceptance_mode) }}</div></td>
                        <td class="p-3">{{ $version->effective_at?->utc()->format('Y-m-d H:i').' UTC' ?? '—' }}</td>
                        <td class="p-3">{{ $version->approval_reference ?? 'WAIT_LEGAL' }}</td>
                        <td class="p-3">{{ $version->acceptances_count }}</td>
                        <td class="p-3"><a class="text-link underline" href="{{ route('owner.legal-documents.edit', $version) }}">Open</a></td>
                    </tr>
                @empty
                    <tr><td class="p-6 text-center text-muted" colspan="7">No legal document versions exist. Public routes show an unavailable state.</td></tr>
                @endforelse
            </tbody>
        </table></div>
        <div class="p-4">{{ $versions->links() }}</div>
    </section>
</x-layouts.owner>
