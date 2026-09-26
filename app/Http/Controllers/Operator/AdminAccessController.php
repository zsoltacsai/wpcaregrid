<?php

namespace App\Http\Controllers\Operator;

use App\AdminAccess\AccessLevel;
use App\AdminAccess\ActorType;
use App\AdminAccess\AdminAccessRefused;
use App\AdminAccess\AdminAccessService;
use App\Http\Controllers\Controller;
use App\Models\AdminAccessRequest;
use App\Models\Website;
use App\Operator\Refusal;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WordPress admin access for operators. Everything goes through
 * AdminAccessService, which checks the operator's permissions again; the
 * one-time token reaches the operator's browser once, in a POST form.
 */
class AdminAccessController extends Controller
{
    public function maintenance(Request $request, Website $website, AdminAccessService $access): Response
    {
        return $this->open($request, $website, $access, AccessLevel::Maintenance, false);
    }

    public function confirmFullAdmin(Request $request, Website $website, AdminAccessService $access): View
    {
        return view('operator.websites.admin-access-confirm', [
            'website' => $website,
            'minutes' => AccessLevel::FullAdmin->minutes(),
            'refusal' => $access->refusal($website, $request->user(), ActorType::Operator, AccessLevel::FullAdmin, true),
        ]);
    }

    public function fullAdmin(Request $request, Website $website, AdminAccessService $access): Response
    {
        $request->validate(['confirm' => ['accepted']]);

        return $this->open($request, $website, $access, AccessLevel::FullAdmin, true);
    }

    public function revoke(Request $request, Website $website, AdminAccessRequest $adminAccessRequest, AdminAccessService $access): RedirectResponse
    {
        $access->revoke($adminAccessRequest, $request->user());

        return redirect()->to(route('operator.websites.show', $website).'#admin-access')->with('status', __('operator.admin_access.revoked'));
    }

    private function open(Request $request, Website $website, AdminAccessService $access, AccessLevel $level, bool $confirmed): Response
    {
        try {
            [$accessRequest, $token] = $access->request($website, $request->user(), ActorType::Operator, $level, $confirmed);
        } catch (AdminAccessRefused $e) {
            return redirect()->to(route('operator.websites.show', $website).'#admin-access')->with('operator_error', Refusal::flash($e->errorCode));
        }

        // The same hand-off page as the customer area: token in a POST body only.
        return response()->view('account.websites.admin-access-opening', [
            'website' => $website,
            'action' => $access->accessUrl($website),
            'requestUuid' => $accessRequest->uuid,
            'token' => $token,
        ])->withHeaders([
            'Cache-Control' => 'no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
        ]);
    }
}
