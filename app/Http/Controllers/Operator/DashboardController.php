<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Models\WebsiteEvent;
use App\Operator\Overview;
use App\Operator\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, Overview $overview): View
    {
        return view('operator.dashboard', [
            'metrics' => $overview->metrics(),
            // Websites that need someone to look at them, most urgent first.
            'attention' => Website::with(['customer', 'activeConnector'])->whereIn('health_status', ['critical', 'attention'])
                ->orderByRaw("case health_status when 'critical' then 0 else 1 end")->orderByDesc('health_checked_at')->limit(8)->get(),
            'events' => $request->user()->hasOperatorPermission(Permission::AuditView)
                ? WebsiteEvent::with(['website', 'actorUser'])->latest('id')->limit(10)->get()
                : null,
        ]);
    }
}
