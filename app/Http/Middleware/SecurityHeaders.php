<?php

namespace App\Http\Middleware;

use App\Marketing\Analytics\MarketingAnalyticsPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response (config/http.php): no MIME sniffing,
 * no framing by other sites, a conservative referrer policy, no powerful
 * browser features, HSTS over HTTPS, an optional Content-Security-Policy,
 * and a redirect to HTTPS when the application runs on HTTPS. Headers a
 * controller set itself (e.g. Referrer-Policy: no-referrer for admin
 * access) are kept.
 */
class SecurityHeaders
{
    public function __construct(private readonly MarketingAnalyticsPolicy $analyticsPolicy) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('http.force_https') && ! $request->isSecure() && ! $request->is('up')) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        $csp = in_array(config('http.csp.mode'), ['report-only', 'enforce'], true) ? config('http.csp.mode') : null;
        $nonce = $csp ? Vite::useCspNonce() : null;

        $response = $next($request);
        $headers = $response->headers;

        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), usb=(), interest-cohort=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];
        foreach ($defaults as $name => $value) {
            if (! $headers->has($name)) {
                $headers->set($name, $value);
            }
        }

        if (config('http.hsts.enabled') && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age='.(int) config('http.hsts.max_age').(config('http.hsts.include_subdomains') ? '; includeSubDomains' : ''));
        }

        if ($csp && ! $headers->has('Content-Security-Policy')) {
            $headers->set($csp === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only', self::policy(
                $nonce,
                $this->analyticsPolicy->mayAddGoogleToContentSecurityPolicy($request),
            ));
        }

        return $response;
    }

    public static function policy(?string $nonce, bool $allowGoogleAnalytics = false): string
    {
        $googleScript = $allowGoogleAnalytics ? ' https://www.googletagmanager.com' : '';
        $googleConnect = $allowGoogleAnalytics ? ' https://www.google-analytics.com https://region1.google-analytics.com https://analytics.google.com' : '';
        $directives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-eval'".($nonce ? " 'nonce-{$nonce}'" : '').$googleScript,
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "connect-src 'self'".$googleConnect,
            "form-action 'self' https:",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ];

        if ($uri = config('http.csp.report_uri')) {
            $directives[] = 'report-uri '.$uri;
        }

        return implode('; ', $directives);
    }
}
