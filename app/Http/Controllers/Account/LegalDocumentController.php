<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Legal\LegalDocumentCatalog;
use App\Legal\LegalDocumentWorkflow;
use App\Models\LegalDocumentVersion;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class LegalDocumentController extends Controller
{
    public function index(Request $request, LegalDocumentCatalog $catalog): View
    {
        $user = $request->user();
        $customer = $request->attributes->get('customer');
        $locale = app()->getLocale();
        $current = $catalog->currentForLocale($locale);
        $acceptances = $user->legalDocumentAcceptances()->get()->keyBy('legal_document_version_id');
        $history = $user->legalDocumentAcceptances()->with('documentVersion')
            ->orderByDesc('accepted_at')->limit(100)->get();

        return view('account.legal-documents', compact('current', 'acceptances', 'history', 'locale', 'customer'));
    }

    public function accept(Request $request, LegalDocumentVersion $legalDocument, LegalDocumentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'confirm' => ['accepted'],
            'content_sha256' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        abort_unless(hash_equals((string) $legalDocument->content_sha256, $data['content_sha256']), 409);

        $workflow->accept($legalDocument, $request->user(), $request->attributes->get('customer'), 'customer_legal_area');

        return to_route('account.legal-documents.index')->with('status', 'Acceptance recorded for this exact published version.');
    }
}
