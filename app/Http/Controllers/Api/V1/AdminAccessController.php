<?php

namespace App\Http\Controllers\Api\V1;

use App\AdminAccess\AdminAccessService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Called by the connector while the customer's browser waits on the site's
 * wp-login.php: exchange the one-time token for the CREATE_ADMIN_SESSION
 * job; and report sessions the site ended itself.
 */
class AdminAccessController extends Controller
{
    public function claim(Request $request, AdminAccessService $access): JsonResponse
    {
        $data = $request->validate([
            'request_uuid' => ['required', 'string', 'max:36'],
            'token' => ['required', 'string', 'max:128'],
        ]);

        return response()->json(['job' => $access->claim($request->attributes->get('connector'), $data['request_uuid'], $data['token'])]);
    }

    public function ended(Request $request, string $uuid, AdminAccessService $access): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', Rule::in(['expired', 'revoked_on_site', 'disconnected', 'deactivated'])]]);

        $access->endedBySite($request->attributes->get('connector'), $uuid, $data['reason']);

        return response()->json(['ok' => true]);
    }
}
