<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Legal\LegalDocumentCatalog;
use App\Legal\LegalDocumentWorkflow;
use App\Localization\Locales;
use App\Models\LegalDocumentVersion;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LegalDocumentController extends Controller
{
    public function index(): View
    {
        $versions = LegalDocumentVersion::query()->with(['creator:id,name', 'approver:id,name', 'publisher:id,name'])->withCount('acceptances')
            ->orderBy('document_type')->orderBy('locale')->orderByDesc('version')->paginate(50);

        return view('owner.legal-documents.index', [
            'versions' => $versions,
            'types' => LegalDocumentCatalog::TYPES,
            'locales' => Locales::supported(),
            'modes' => LegalDocumentCatalog::ACCEPTANCE_MODES,
        ]);
    }

    public function store(Request $request, LegalDocumentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'document_type' => ['required', Rule::in(LegalDocumentCatalog::TYPES)],
            'locale' => ['required', Rule::in(Locales::codes())],
            'title' => ['required', 'string', 'max:180'],
            'acceptance_mode' => ['required', Rule::in(LegalDocumentCatalog::ACCEPTANCE_MODES)],
        ]);
        $data['body'] = '';
        $document = $workflow->createDraft($data, $request->user('owner'));

        return to_route('owner.legal-documents.edit', $document)->with('status', "Draft {$document->document_type} {$document->locale} v{$document->version} created.");
    }

    public function edit(LegalDocumentVersion $legalDocument): View
    {
        return view('owner.legal-documents.edit', [
            'document' => $legalDocument,
            'types' => LegalDocumentCatalog::TYPES,
            'locales' => Locales::supported(),
            'modes' => LegalDocumentCatalog::ACCEPTANCE_MODES,
            'acceptances' => $legalDocument->acceptances()->count(),
        ]);
    }

    public function update(Request $request, LegalDocumentVersion $legalDocument, LegalDocumentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:200000'],
            'acceptance_mode' => ['required', Rule::in(LegalDocumentCatalog::ACCEPTANCE_MODES)],
            'audiences' => ['required', 'array', 'min:1', 'max:4'],
            'audiences.*' => ['required', Rule::in(['all', 'individual', 'business', 'processor'])],
            'effective_at' => ['required', 'date_format:Y-m-d\TH:i'],
        ]);
        $data['audiences'] = array_values(array_unique($data['audiences']));
        $workflow->updateDraft($legalDocument, $data, $request->user('owner'));

        return to_route('owner.legal-documents.edit', $legalDocument)->with('status', 'Draft saved.');
    }

    public function approve(Request $request, LegalDocumentVersion $legalDocument, LegalDocumentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['approval_reference' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:\/# -]+$/']]);
        $workflow->approve($legalDocument, $data['approval_reference'], $request->user('owner'));

        return to_route('owner.legal-documents.edit', $legalDocument)->with('status', 'Approval reference recorded. The application does not validate external legal advice.');
    }

    public function publish(Request $request, LegalDocumentVersion $legalDocument, LegalDocumentWorkflow $workflow): RedirectResponse
    {
        $request->validate(['confirm_publish' => ['accepted']]);
        $workflow->publish($legalDocument, $request->user('owner'));

        return to_route('owner.legal-documents.edit', $legalDocument)->with('status', 'Approved version published according to its UTC effective date.');
    }

    public function retire(Request $request, LegalDocumentVersion $legalDocument, LegalDocumentWorkflow $workflow): RedirectResponse
    {
        $request->validate(['confirm_retire' => ['accepted']]);
        $workflow->retire($legalDocument, $request->user('owner'));

        return to_route('owner.legal-documents.edit', $legalDocument)->with('status', 'Version retired from current-version resolution; historical public access remains.');
    }
}
