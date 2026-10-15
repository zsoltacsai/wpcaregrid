<?php

namespace App\Http\Middleware;

use App\Legal\LegalDocumentCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCurrentLegalAcceptance
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->customer()->exists()) {
            return $next($request);
        }

        $document = app(LegalDocumentCatalog::class)->current('terms_of_service', app()->getLocale());
        if ($document?->acceptance_mode !== 'acceptance_required_before_account_use') {
            return $next($request);
        }

        $accepted = $user->legalDocumentAcceptances()
            ->where('legal_document_version_id', $document->id)
            ->where('content_sha256', $document->content_sha256)
            ->exists();
        if ($accepted || $request->routeIs('account.legal-documents.*')) {
            return $next($request);
        }

        return redirect()->route('account.legal-documents.index')->with('legal_acceptance_required', true);
    }
}
