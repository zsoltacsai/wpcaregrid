<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\SecurityIncident;
use App\Models\SecurityScan;
use App\Models\Website;
use App\Security\IncidentStatus;
use App\Security\SecurityScanner;
use App\Security\Vulnerabilities\VulnerabilitySource;
use Illuminate\Contracts\View\View;

/**
 * Security overview: websites with open findings (most serious first),
 * open incidents and recent scans. Shows only what scans reported.
 */
class SecurityController extends Controller
{
    public function __invoke(SecurityScanner $security, VulnerabilitySource $vulnerabilities): View
    {
        $websites = $security->isConfigured()
            ? Website::with('customer')->where(fn ($q) => $q->where('security_open_findings', '>', 0)->orWhereIn('security_status', ['critical', 'attention']))
                ->orderByRaw("case security_status when 'critical' then 0 when 'attention' then 1 else 2 end")->orderByDesc('security_open_findings')->paginate(25)
            : null;

        return view('operator.security.index', [
            'configured' => $security->isConfigured(),
            'openFindings' => $security->openFindings(),
            'websites' => $websites,
            'summaries' => $websites ? $security->summaries($websites->items()) : [],
            'incidents' => SecurityIncident::with('website')->whereNotIn('status', [IncidentStatus::Resolved->value, IncidentStatus::Closed->value])->latest('id')->limit(20)->get(),
            'scans' => SecurityScan::with('website')->latest('id')->limit(15)->get(),
            'vulnerabilitySource' => $vulnerabilities->available() ? $vulnerabilities->name() : null,
            'unscanned' => Website::whereNull('security_scanned_at')->count(),
        ]);
    }
}
