<?php

namespace App\Http\Controllers;

use App\Legal\LegalDocumentCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class LegalDocumentController extends Controller
{
    public function current(Request $request, string $type, LegalDocumentCatalog $catalog): View|Response
    {
        abort_unless(in_array($type, LegalDocumentCatalog::TYPES, true), 404);
        $locale = app()->getLocale();
        $document = $catalog->current($type, $locale);

        $data = [
            'document' => $document,
            'type' => $type,
            'locale' => $locale,
            'unavailable' => $document === null,
        ];

        return response()->view('legal.document', $data, $document === null ? 404 : 200);
    }

    public function historical(string $uuid, LegalDocumentCatalog $catalog): View
    {
        $document = $catalog->historical($uuid);
        abort_unless($document, 404);

        return view('legal.document', [
            'document' => $document,
            'type' => $document->document_type,
            'locale' => $document->locale,
            'unavailable' => false,
            'historical' => true,
        ]);
    }
}
