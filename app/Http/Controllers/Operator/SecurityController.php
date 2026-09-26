<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Security\SecurityScanner;
use Illuminate\Contracts\View\View;

/**
 * Security overview. Shows only what a scanner reports; without one it says
 * so. The Security/Malware milestone plugs a scanner in behind SecurityScanner.
 */
class SecurityController extends Controller
{
    public function __invoke(SecurityScanner $security): View
    {
        $websites = $security->isConfigured()
            ? Website::with('customer')->orderBy('url')->paginate(25)
            : null;

        return view('operator.security.index', [
            'configured' => $security->isConfigured(),
            'openFindings' => $security->openFindings(),
            'websites' => $websites,
            'summaries' => $websites ? $security->summaries($websites->items()) : [],
        ]);
    }
}
