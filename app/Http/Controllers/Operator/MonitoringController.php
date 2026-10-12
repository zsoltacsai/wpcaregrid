<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\MonitoringIncident;
use App\Models\MonitoringResult;
use App\Models\Website;
use Illuminate\Contracts\View\View;

final class MonitoringController extends Controller
{
    public function website(Website $website): View
    {
        return view('operator.monitoring.website', [
            'website' => $website,
            'incidents' => MonitoringIncident::query()
                ->where('scope', 'website')->where('subject_type', 'website')->where('subject_id', $website->uuid)
                ->latest('opened_at')->paginate(25),
            'results' => MonitoringResult::query()
                ->where('scope', 'website')->where('subject_type', 'website')->where('subject_id', $website->uuid)
                ->orderBy('check_id')->get(),
        ]);
    }
}
