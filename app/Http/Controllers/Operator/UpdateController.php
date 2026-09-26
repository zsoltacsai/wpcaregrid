<?php

namespace App\Http\Controllers\Operator;

use App\Connector\RequestUpdate;
use App\Connector\UpdateRefused;
use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Operator\Refusal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * One update for one explicitly chosen website. Queued as a connector job
 * (never run from here); the site installs it at its next poll.
 */
class UpdateController extends Controller
{
    public function store(Request $request, Website $website, RequestUpdate $updates): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(RequestUpdate::KINDS)],
            'target' => ['required_unless:kind,core', 'nullable', 'string', 'max:191'],
        ]);

        try {
            $job = $updates($website->load(['activeConnector', 'inventory', 'subscription', 'customer']), $data['kind'], $data['target'] ?? null, 'operator:'.$request->user()->id);
        } catch (UpdateRefused $e) {
            return back()->with('operator_error', Refusal::flash($e->errorCode));
        }

        return back()->with('status', $job->status->value === 'waiting' ? __('operator.updates.queued_after_backup') : __('operator.updates.queued'));
    }
}
