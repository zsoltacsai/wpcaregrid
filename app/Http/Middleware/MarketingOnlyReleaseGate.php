<?php

namespace App\Http\Middleware;

use App\Release\StagingActiveProfile;
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
    private const PROFILES = ['marketing_only', 'invitation_beta', 'staging_admin_qa', 'staging_active'];

    private const STAGING_ADMIN_QA_READ_PATHS = [
        '/owner', '/owner/customers', '/owner/operators',
        '/operator', '/operator/websites', '/operator/activity',
        '/account', '/account/websites',
    ];

    private const STAGING_ADMIN_QA_READ_PATTERNS = [
        '~^/owner/customers/[0-9]+$~D',
        '~^/owner/operators/[0-9]+$~D',
        '~^/owner/operators/[0-9]+/permissions$~D',
        '~^/operator/websites/[^/]+$~D',
        '~^/account/websites/[^/]+$~D',
    ];

    private const STAGING_ADMIN_QA_AUTH_POSTS = [
        '/login',
        '/operator/login',
        '/owner/login',
        '/logout',
        '/operator/logout',
        '/owner/logout',
    ];

    private const PUBLIC_PATHS = [
        '/',
        '/up',
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

        if (! $this->mustEnforce()) {
            return $next($request);
        }

        if ($profile === 'staging_admin_qa') {
            if (! $this->isAuthorizedStagingAdminQaRequest($request)) {
                return $this->notFound();
            }

            return $next($request);
        }

        if ($profile === 'staging_active') {
            return $this->isAuthorizedStagingActiveRequest($request)
                ? $next($request)
                : $this->notFound();
        }

        if (! $this->isAllowedPublicRequest($request)) {
            return $this->notFound();
        }

        // A bodyless liveness response preserves the approved health probe without
        // exposing framework, dependency, deployment, or operational details.
        // The request method has already been restricted to GET or HEAD by the
        // public-request allowlist.
        if ($request->getPathInfo() === '/up') {
            return response('', Response::HTTP_OK, ['Cache-Control' => 'no-store']);
        }

        // invitation_beta is not an access grant. This gate has no independent
        // invitation resolver, so it deliberately keeps backend paths closed.
        return $next($request);
    }

    /** The only exemption is the exact server-side PHPUnit test identity. */
    private function mustEnforce(): bool
    {
        $testingExemption = config('marketing_release.enforcement_mode') === 'testing'
            && config('marketing_release.test_app_environment') === 'testing'
            && config('marketing_release.test_deployment_environment') === 'testing';

        return ! $testingExemption;
    }

    /**
     * The QA profile exposes only authenticated read pages and login/logout on
     * the exact HTTPS staging-next origin. Every mutating product operation,
     * payment, Connector/API, password-reset and registration path stays shut.
     */
    private function isAuthorizedStagingAdminQaRequest(Request $request): bool
    {
        if (config('marketing_release.staging_admin_qa_enabled') !== true
            || config('app.env') !== 'staging'
            || config('app.deployment_environment') !== 'staging_next'
            || config('app.debug') !== false
            || config('app.url') !== 'https://staging-next.wpcaregrid.com'
            || ! $request->isSecure()) {
            return false;
        }

        $host = $request->headers->get('host');
        if (! is_string($host) || strtolower($host) !== 'staging-next.wpcaregrid.com'
            || $request->headers->has('x-forwarded-host')
            || $request->headers->has('forwarded')
            || ! $this->hasUnambiguousTarget($request)) {
            return false;
        }

        $method = strtoupper($request->getRealMethod());
        if ($request->method() !== $method) {
            return false;
        }
        $path = $request->getPathInfo();

        if (in_array($path, self::STAGING_ADMIN_QA_AUTH_POSTS, true)) {
            return $method === 'POST';
        }

        if (! in_array($method, ['GET', 'HEAD'], true)) {
            return false;
        }

        if (in_array($path, ['/up', '/login', '/owner/login', '/operator/login'], true)) {
            return true;
        }

        if (in_array($path, self::STAGING_ADMIN_QA_READ_PATHS, true)) {
            return true;
        }
        foreach (self::STAGING_ADMIN_QA_READ_PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return $this->isAllowedPublicRequest($request);
    }

    /**
     * staging_active lifts the marketing restriction for the application as a whole, but
     * only for the exact staging-next origin and only while the whole environment is proven:
     * configuration identity, the live database, HTTPS and the Host header. It is not an
     * authorisation: routes keep their own guards, roles and CSRF protection. Routes that
     * touch something outside this stack (Stripe, Connectors, third-party services) stay
     * closed until their isolation is proven or the Owner opens them.
     * Any doubt is an empty 404, the same answer the marketing profile gives.
     */
    private function isAuthorizedStagingActiveRequest(Request $request): bool
    {
        $staging = app(StagingActiveProfile::class);

        if ($staging->identityProblems() !== [] || ! $request->isSecure()) {
            return false;
        }

        $host = $request->headers->get('host');
        if (! is_string($host) || strtolower($host) !== StagingActiveProfile::HOST
            || $request->headers->has('x-forwarded-host')
            || $request->headers->has('forwarded')
            || ! $this->hasUnambiguousTarget($request)) {
            return false;
        }

        if ($staging->databaseProblems() !== []) {
            return false;
        }

        // The effective method (including the framework's form method spoofing) decides,
        // because that is the method the router will dispatch.
        $path = $request->getPathInfo();
        $group = $staging->groupFor($path, $request->method());

        return $group === null || $staging->groupOpen($group, $path);
    }

    private function notFound(): Response
    {
        return response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
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

        if (preg_match('/^([^:]+)(?::([0-9]{1,5}))?$/D', strtolower($rawHost), $hostParts) !== 1) {
            return false;
        }

        $host = $hostParts[1];
        $requestPort = isset($hostParts[2]) ? (int) $hostParts[2] : null;
        if (($requestPort !== null && ($requestPort < 1 || $requestPort > 65535))
            || ! $this->isDnsHostname($host)) {
            return false;
        }

        // Do not let proxy-supplied host aliases widen the application host
        // allowlist. If a proxy provides this header, it must agree exactly.
        if ($request->headers->has('x-forwarded-host')) {
            $forwardedHost = $request->headers->get('x-forwarded-host');
            if (! is_string($forwardedHost) || strtolower($forwardedHost) !== strtolower($rawHost)) {
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
        $configuredHost = strtolower($configuredHost);
        $configuredPort = parse_url($appUrl, PHP_URL_PORT);

        // The built-in CI/dev server uses localhost:PORT. Accept that loopback
        // Host only when the configured application URL itself names localhost.
        // Production FQDNs never inherit this exception.
        if ($requestPort !== null
            && $configuredPort !== $requestPort
            && ! ($host === 'localhost' && $configuredHost === 'localhost')) {
            return false;
        }

        $canonicalHost = $configuredHost;
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

        if ($host === 'localhost') {
            return true;
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
