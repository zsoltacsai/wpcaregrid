<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a marketing-only release before the router can dispatch a route.
 *
 * The profile is release configuration only. It is never an authorization
 * decision. Until an independent invitation-eligibility gate is implemented,
 * invitation_beta remains fail-closed for backend behavior too.
 */
final class MarketingOnlyReleaseGate
{
    private const PROFILES = ['marketing_only', 'invitation_beta'];

    private const PUBLIC_PATHS = [
        '/',
        '/pricing',
        '/robots.txt',
        '/sitemap.xml',
        '/terms',
        '/privacy',
        '/cookie-policy',
        '/dpa',
        '/favicon.ico',
        '/favicon.svg',
        '/favicon-32x32.png',
        '/apple-touch-icon.png',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $profile = config('marketing_release.profile', 'marketing_only');
        if (! is_string($profile) || ! in_array($profile, self::PROFILES, true)) {
            $profile = 'marketing_only';
        }

        if (! $this->isAllowedPublicRequest($request)) {
            return response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        // invitation_beta is not an access grant. This gate has no independent
        // invitation resolver, so it deliberately keeps backend paths closed.
        return $next($request);
    }

    private function isAllowedPublicRequest(Request $request): bool
    {
        if (! $this->hasExpectedHost($request) || ! $this->hasUnambiguousTarget($request)) {
            return false;
        }

        $method = strtoupper($request->getRealMethod());
        if ($request->method() !== $method) {
            return false;
        }

        if (in_array($method, ['GET', 'HEAD'], true)) {
            return $this->isPublicGetPath($request->getPathInfo());
        }

        // These two forms only change presentation preferences. They continue
        // through the existing web/CSRF, validation, and throttling middleware.
        return $method === 'POST'
            && in_array($request->getPathInfo(), ['/locale', '/currency'], true);
    }

    private function hasExpectedHost(Request $request): bool
    {
        $rawHost = $request->headers->get('host');
        if (! is_string($rawHost) || $rawHost === '' || trim($rawHost) !== $rawHost) {
            return false;
        }

        $host = strtolower($rawHost);
        if (! $this->isDnsHostname($host)) {
            return false;
        }

        // Do not let proxy-supplied host aliases widen the application host
        // allowlist. If a proxy provides this header, it must agree exactly.
        if ($request->headers->has('x-forwarded-host')) {
            $forwardedHost = $request->headers->get('x-forwarded-host');
            if (! is_string($forwardedHost) || strtolower($forwardedHost) !== $host) {
                return false;
            }
        }

        if ($request->headers->has('forwarded')) {
            return false;
        }

        $appUrl = config('app.url');
        if (! is_string($appUrl)) {
            return false;
        }

        $configuredHost = parse_url($appUrl, PHP_URL_HOST);
        if (! is_string($configuredHost) || ! $this->isDnsHostname(strtolower($configuredHost))) {
            return false;
        }

        $canonicalHost = strtolower($configuredHost);
        if (str_starts_with($canonicalHost, 'www.')) {
            $canonicalHost = substr($canonicalHost, 4);
        }

        return $host === $canonicalHost || $host === 'www.'.$canonicalHost;
    }

    private function isDnsHostname(string $host): bool
    {
        if ($host === '' || str_ends_with($host, '.') || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        return preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',
            $host,
        ) === 1;
    }

    private function hasUnambiguousTarget(Request $request): bool
    {
        foreach ([
            'x-http-method-override',
            'x-method-override',
            'x-original-url',
            'x-rewrite-url',
            'x-forwarded-prefix',
        ] as $header) {
            if ($request->headers->has($header)) {
                return false;
            }
        }

        $target = $request->getRequestUri();
        if (! is_string($target) || ! str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return false;
        }

        $rawPath = parse_url($target, PHP_URL_PATH);
        if (! is_string($rawPath) || $rawPath !== $request->getPathInfo()) {
            return false;
        }

        if (str_contains($rawPath, '\\')
            || preg_match('/[%;\x00-\x20\x7f]/', $rawPath) === 1
            || str_contains($rawPath, '//')) {
            return false;
        }

        foreach (explode('/', $rawPath) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function isPublicGetPath(string $path): bool
    {
        if (in_array($path, self::PUBLIC_PATHS, true)) {
            return true;
        }

        if (preg_match('~^/(?:en|hu)/?$~D', $path) === 1
            || preg_match('~^/(?:en|hu)/pricing$~D', $path) === 1) {
            return true;
        }

        if (preg_match(
            '~^/legal/history/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$~D',
            $path,
        ) === 1) {
            return true;
        }

        // Vite-generated browser assets only. PHP, source maps, manifests,
        // arbitrary public files and all non-GET methods remain denied.
        return preg_match(
            '~^/build/assets/[A-Za-z0-9_-][A-Za-z0-9._-]*\.(?:css|js|woff2?|ttf|svg|png|jpe?g|webp)$~D',
            $path,
        ) === 1;
    }
}
