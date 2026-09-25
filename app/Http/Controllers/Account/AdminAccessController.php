<?php

namespace App\Http\Controllers\Account;

use App\AdminAccess\AccessLevel;
use App\AdminAccess\ActorType;
use App\AdminAccess\AdminAccessRefused;
use App\AdminAccess\AdminAccessService;
use App\Http\Controllers\Controller;
use App\Models\AdminAccessRequest;
use App\Models\Website;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Open WordPress Admin". The one-time token goes to the customer's browser
 * once, in an auto-submitted POST form to the site (never in a URL, never
 * stored, never shown), and the site's connector exchanges it.
 */
class AdminAccessController extends Controller
{
    public function maintenance(Request $request, Website $website, AdminAccessService $access): Response
    {
        $this->authorize('adminAccess', $website);

        return $this->open($request, $website, $access, AccessLevel::Maintenance, false);
    }

    public function confirmFullAdmin(Website $website, AdminAccessService $access): View
    {
        $this->authorize('adminAccess', $website);

        return view('account.websites.admin-access-confirm', [
            'website' => $website,
            'minutes' => AccessLevel::FullAdmin->minutes(),
            'refusal' => $access->refusal($website, request()->user(), ActorType::Customer, AccessLevel::FullAdmin, true),
        ]);
    }

    /** Full admin only with the explicit confirmation of this form. */
    public function fullAdmin(Request $request, Website $website, AdminAccessService $access): Response
    {
        $this->authorize('adminAccess', $website);
        $request->validate(['confirm' => ['accepted']]);

        return $this->open($request, $website, $access, AccessLevel::FullAdmin, true);
    }

    public function revoke(Request $request, Website $website, AdminAccessRequest $adminAccessRequest, AdminAccessService $access): RedirectResponse
    {
        $this->authorize('revokeAdminAccess', $website);

        $access->revoke($adminAccessRequest, $request->user());

        return redirect()->route('account.websites.show', $website)->with('status', __('admin_access.account.revoked'));
    }

    private function open(Request $request, Website $website, AdminAccessService $access, AccessLevel $level, bool $confirmed): Response
    {
        try {
            [$accessRequest, $token] = $access->request($website, $request->user(), ActorType::Customer, $level, $confirmed);
        } catch (AdminAccessRefused $e) {
            $key = 'admin_access.refusals.'.$e->errorCode;

            return redirect()->route('account.websites.show', $website)->with('account_error', __($key) !== $key ? __($key) : __('admin_access.refusals.default'));
        }

        return response()->view('account.websites.admin-access-opening', [
            'website' => $website,
            'action' => $access->accessUrl($website),
            'requestUuid' => $accessRequest->uuid,
            'token' => $token,
        ])->withHeaders([
            // The token must not be cached, framed or leaked through a referrer.
            'Cache-Control' => 'no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
        ]);
    }
}
