<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditEvent;
use App\Owner\BusinessMetrics;
use Illuminate\Contracts\View\View;

/** Business overview: real figures only (App\Owner\BusinessMetrics). */
class DashboardController extends Controller
{
    public function __invoke(BusinessMetrics $metrics): View
    {
        return view('owner.dashboard', [
            'metrics' => $metrics->all(),
            'audit' => AdminAuditEvent::with('actor')->latest('id')->limit(10)->get(),
        ]);
    }
}
