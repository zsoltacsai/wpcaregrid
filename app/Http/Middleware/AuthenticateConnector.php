<?php

namespace App\Http\Middleware;

use App\Models\Connector;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a connector by its bearer credential and scopes the request
 * to exactly one website: the X-CareGrid-Website header must name the
 * website the credential belongs to. The authenticated connector is
 * available as the `connector` request attribute.
 */
class AuthenticateConnector
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('connector.require_https') && ! $request->isSecure()) {
            return self::error('HTTPS_REQUIRED', 'Connector requests must use HTTPS.', 403);
        }

        $credential = (string) $request->bearerToken();

        if (! preg_match('/^cgc_[A-Za-z0-9]{48}$/', $credential)) {
            return self::error('UNAUTHENTICATED', 'Missing or malformed connector credential.', 401);
        }

        $connector = Connector::findByCredential($credential);

        if (! $connector) {
            return self::error('UNAUTHENTICATED', 'Unknown connector credential.', 401);
        }

        if (! $connector->isActive()) {
            // The plugin clears its credential on this code and requires a new pairing.
            return self::error('CONNECTOR_REVOKED', 'This connector has been disconnected. Pair the website again.', 401);
        }

        $website = $connector->website;

        if (! $website || ! hash_equals($website->uuid, (string) $request->header('X-CareGrid-Website'))) {
            return self::error('WEBSITE_MISMATCH', 'The credential does not belong to this website.', 403);
        }

        // Record contact at most once a minute to keep writes low.
        if (! $connector->last_seen_at || $connector->last_seen_at->lt(now()->subMinute())) {
            $connector->forceFill(['last_seen_at' => now()])->saveQuietly();
            $website->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        $request->attributes->set('connector', $connector);

        return $next($request);
    }

    public static function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
