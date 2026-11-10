<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Jobs\RunPlatformUpdateCheck;
use App\Models\PlatformAdvisory;
use App\Models\PlatformComponent;
use App\Models\PlatformUpdateCheck;
use App\Platform\Updates\PlatformUpdateOverview;
use App\Release\StagingActiveProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** System → Platform Updates. Shows stored results; the only action is a read-only check. */
class PlatformUpdatesController extends Controller
{
    private const KINDS = ['composer', 'npm', 'runtime', 'connector'];

    public function index(Request $request, PlatformUpdateOverview $overview): View
    {
        $kind = in_array($request->query('kind'), self::KINDS, true) ? $request->query('kind') : null;

        return view('owner.platform-updates.index', [
            'summary' => $overview->summary(),
            'kind' => $kind,
            'kinds' => self::KINDS,
            'advisories' => PlatformAdvisory::query()->with('component')->where('state', PlatformAdvisory::OPEN)->whereHas('component', fn ($q) => $q->where('development_only', false))
                ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 when 'low' then 3 else 4 end")
                ->orderByDesc('published_at')->limit(100)->get(),
            'components' => PlatformComponent::query()->withCount(['advisories as open_advisories_count' => fn ($q) => $q->where('state', PlatformAdvisory::OPEN)])
                ->where('present', true)->when($kind, fn ($q) => $q->where('kind', $kind))
                ->orderByRaw("case advisory_state when 'UNKNOWN' then 0 when 'UNVERIFIED' then 1 else 2 end")->orderBy('kind')->orderBy('name')->paginate(25)->withQueryString(),
            'history' => PlatformUpdateCheck::query()->latest('started_at')->limit(15)->get(),
        ]);
    }

    public function show(PlatformAdvisory $advisory): View
    {
        return view('owner.platform-updates.advisory', ['advisory' => $advisory->load('component')]);
    }

    public function check(): RedirectResponse
    {
        if (config('platform_updates.enabled') !== true) {
            return back()->with('owner_error', __('platform_updates.refused.disabled'));
        }
        if (StagingActiveProfile::externalContactClosed()) {
            return back()->with('owner_error', __('platform_updates.refused.staging_external_contact_closed'));
        }
        RunPlatformUpdateCheck::dispatch();

        return back()->with('status', __('platform_updates.check_requested'));
    }
}
