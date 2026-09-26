<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\WebsiteEvent;
use App\Websites\Actor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Everything that happened across websites, from the audit log (website_events). */
class ActivityController extends Controller
{
    public const GROUPS = ['backup', 'restore', 'update', 'admin_access', 'connector', 'license'];

    public const ACTORS = [Actor::CUSTOMER, Actor::OPERATOR, Actor::SYSTEM];

    public function __invoke(Request $request): View
    {
        $group = in_array($request->query('group'), self::GROUPS, true) ? $request->query('group') : null;
        $actor = in_array($request->query('actor'), self::ACTORS, true) ? $request->query('actor') : null;
        $q = mb_substr(trim((string) (is_string($request->query('q')) ? $request->query('q') : '')), 0, 100);

        $events = WebsiteEvent::with(['website', 'actorUser'])
            ->when($group, fn ($query) => $query->where('event', 'like', $group.'.%'))
            ->when($actor, fn ($query) => $query->where('actor_type', $actor))
            ->when($q !== '', fn ($query) => $query->whereHas('website', fn ($w) => $w->whereRaw("lower(url) like ? escape '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)).'%'])))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('operator.activity.index', [
            'events' => $events,
            'group' => $group,
            'actor' => $actor,
            'q' => $q,
        ]);
    }
}
