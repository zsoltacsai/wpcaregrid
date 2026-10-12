<?php

namespace App\Monitoring;

use App\Connector\HealthStatus;
use App\Models\MonitoringResult;
use App\Models\Website;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Facades\Http;

final class WebsiteProbe
{
    public function __construct(
        private readonly MonitoringEngine $engine,
        private readonly PublicAddressLookup $addressLookup,
    ) {}

    public function run(Website $website): void
    {
        $started = microtime(true);
        try {
            if (! function_exists('curl_init')) {
                throw new \DomainException('monitoring.transport_unavailable');
            }

            $origin = PublicOrigin::resolve($website->url, $this->addressLookup);
            $initialHost = $origin->host;
            $tlsDue = ! MonitoringResult::query()
                ->where('check_id', 'website.tls')->where('subject_type', 'website')->where('subject_id', $website->uuid)
                ->where('fresh_until', '>', now())->exists();
            // Probe the saved website origin only. Stored paths and query strings
            // are not included in monitor traffic or evidence.
            $uri = new Uri($origin->scheme.'://'.$origin->host.'/');
            $redirects = 0;
            $certificateInfo = [];

            while (true) {
                $path = ($uri->getPath() ?: '/').($uri->getQuery() !== '' ? '?'.$uri->getQuery() : '');
                $responseStatus = null;
                $responseLocation = null;
                $responseBodyTruncated = false;
                $options = $this->pinnedRequestOptions($origin, $tlsDue) + [
                    'on_headers' => static function ($response) use (&$responseStatus, &$responseLocation): void {
                        $responseStatus = $response->getStatusCode();
                        $responseLocation = $response->getHeaderLine('Location') ?: null;
                    },
                    'progress' => $this->progressCallback($responseBodyTruncated),
                    'on_stats' => static function ($stats) use (&$certificateInfo): void {
                        $certificateInfo = $stats->getHandlerStats()['certinfo'] ?? [];
                    },
                ];
                try {
                    $response = Http::withOptions($options)
                        ->withHeaders(['Range' => 'bytes=0-0', 'User-Agent' => 'WP-CareGrid-Monitor/1.0'])
                        ->get($origin->url($path));

                    $status = $response->status();
                    $location = $response->header('Location');
                    $response->toPsrResponse()->getBody()->close();
                } catch (\Throwable $requestException) {
                    // Many healthy WordPress pages ignore Range. Once the status and
                    // headers are received, stop the body at the cap but evaluate the
                    // actual HTTP response instead of declaring the site unreachable.
                    if (! $responseBodyTruncated || $responseStatus === null) {
                        throw $requestException;
                    }

                    $status = $responseStatus;
                    $location = $responseLocation;
                }
                if ($status >= 300 && $status < 400 && $status !== 304 && (! is_string($location) || $location === '')) {
                    throw new \DomainException('monitoring.redirect_refused');
                }
                if ($status >= 300 && $status < 400 && is_string($location) && $location !== '') {
                    if ($redirects >= 3) {
                        throw new \DomainException('monitoring.redirect_limit');
                    }
                    try {
                        [$next, $nextOrigin] = SafeRedirect::resolve($uri, $location, $initialHost, $this->addressLookup);
                    } catch (\Throwable) {
                        throw new \DomainException('monitoring.redirect_refused');
                    }
                    $uri = $next;
                    $origin = $nextOrigin;
                    $redirects++;

                    continue;
                }

                $latency = (int) round((microtime(true) - $started) * 1000);
                $healthy = $status >= 200 && $status < 400;
                $state = $healthy ? HealthStatus::Healthy : ($status < 500 ? HealthStatus::Attention : HealthStatus::Critical);
                $reason = $status >= 200 && $status < 400 ? MonitoringReason::MONITORING_OK : MonitoringReason::HTTP_BAD_STATUS;
                $this->engine->record(new MonitoringObservation(
                    checkId: 'website.http',
                    scope: MonitoringScope::Website,
                    subjectType: 'website',
                    subjectId: (string) $website->uuid,
                    state: $state,
                    severity: $healthy ? null : ($state === HealthStatus::Attention ? MonitoringSeverity::Warning : MonitoringSeverity::Critical),
                    reasonCode: $reason,
                    source: 'active_probe',
                    evidence: ['http_status' => $status, 'latency_ms' => $latency, 'redirects' => $redirects, 'response_truncated' => $responseBodyTruncated],
                    freshnessSeconds: 600,
                    failureThreshold: 3,
                    recoveryThreshold: 2,
                    nextCheckSeconds: 300,
                ));
                if ($tlsDue) {
                    $this->recordCertificate($website, $origin, $certificateInfo);
                }

                return;
            }
        } catch (\Throwable $exception) {
            $handlerContext = $exception instanceof ConnectException || $exception instanceof RequestException
                ? $exception->getHandlerContext()
                : [];
            $curlError = (int) ($handlerContext['errno'] ?? 0);
            $timeoutCode = defined('CURLE_OPERATION_TIMEDOUT') ? constant('CURLE_OPERATION_TIMEDOUT') : 28;
            $callbackAbortCode = defined('CURLE_ABORTED_BY_CALLBACK') ? constant('CURLE_ABORTED_BY_CALLBACK') : 42;
            $tlsErrorCodes = array_filter([
                defined('CURLE_SSL_CONNECT_ERROR') ? constant('CURLE_SSL_CONNECT_ERROR') : 35,
                defined('CURLE_SSL_CERTPROBLEM') ? constant('CURLE_SSL_CERTPROBLEM') : 58,
                defined('CURLE_PEER_FAILED_VERIFICATION') ? constant('CURLE_PEER_FAILED_VERIFICATION') : 60,
            ]);
            $reason = match (true) {
                $exception instanceof \DomainException && $exception->getMessage() === 'monitoring.transport_unavailable' => MonitoringReason::MONITORING_SOURCE_UNVERIFIED,
                $exception instanceof \DomainException && str_contains($exception->getMessage(), 'redirect') => MonitoringReason::HTTP_REDIRECT_FAILURE,
                $exception instanceof \DomainException && str_contains($exception->getMessage(), 'response_too_large') => MonitoringReason::HTTP_RESPONSE_TOO_LARGE,
                $curlError === $callbackAbortCode => MonitoringReason::HTTP_RESPONSE_TOO_LARGE,
                $exception instanceof \DomainException && str_contains($exception->getMessage(), 'dns') => MonitoringReason::HTTP_UNREACHABLE,
                $curlError === $timeoutCode => MonitoringReason::HTTP_TIMEOUT,
                in_array($curlError, $tlsErrorCodes, true) => MonitoringReason::HTTP_TLS_FAILED,
                default => MonitoringReason::HTTP_UNREACHABLE,
            };
            $this->engine->record(new MonitoringObservation(
                checkId: 'website.http',
                scope: MonitoringScope::Website,
                subjectType: 'website',
                subjectId: (string) $website->uuid,
                state: $reason === MonitoringReason::MONITORING_SOURCE_UNVERIFIED ? HealthStatus::Unknown : ($reason === MonitoringReason::HTTP_TIMEOUT ? HealthStatus::Attention : HealthStatus::Critical),
                severity: $reason === MonitoringReason::MONITORING_SOURCE_UNVERIFIED ? null : ($reason === MonitoringReason::HTTP_TIMEOUT ? MonitoringSeverity::Warning : MonitoringSeverity::Critical),
                reasonCode: $reason,
                source: 'active_probe',
                evidence: ['error_category' => $reason, 'latency_ms' => (int) round((microtime(true) - $started) * 1000)],
                freshnessSeconds: 600,
                failureThreshold: 3,
                recoveryThreshold: 2,
                nextCheckSeconds: 300,
            ));
            if ($reason === MonitoringReason::HTTP_TLS_FAILED) {
                $message = strtolower($exception->getMessage());
                $tlsReason = str_contains($message, 'expired') ? MonitoringReason::TLS_EXPIRED
                    : (str_contains($message, 'hostname') || str_contains($message, 'subject name') ? MonitoringReason::TLS_HOSTNAME_MISMATCH : MonitoringReason::TLS_INVALID_CHAIN);
                $this->engine->record(new MonitoringObservation(
                    'website.tls', MonitoringScope::Website, 'website', (string) $website->uuid,
                    HealthStatus::Critical, MonitoringSeverity::Critical, $tlsReason,
                    'active_probe', ['error_category' => $tlsReason], now(), 86400,
                ));
            }
        }
    }

    /** @return array<string, mixed> */
    private function pinnedRequestOptions(PublicOrigin $origin, bool $tlsDue): array
    {
        // Guzzle's streaming option selects StreamHandler, which cannot apply CURLOPT_RESOLVE.
        // Omit it so Guzzle uses cURL and keeps every request pinned to the validated address.
        return [
            'allow_redirects' => false,
            'connect_timeout' => 3,
            'timeout' => 8,
            'verify' => true,
            'proxy' => '',
            'curl' => $origin->curlResolve() + ($tlsDue ? [CURLOPT_CERTINFO => true] : []),
        ];
    }

    private function progressCallback(bool &$responseBodyTruncated): \Closure
    {
        return static function (int $downloadTotal, int $downloaded) use (&$responseBodyTruncated): void {
            if ($downloaded > 65536) {
                $responseBodyTruncated = true;
                throw new \DomainException('monitoring.response_too_large');
            }
        };
    }

    /** @param list<array<string, string>> $certificateInfo */
    private function recordCertificate(Website $website, PublicOrigin $origin, array $certificateInfo): void
    {
        $expires = null;
        foreach ($certificateInfo as $certificate) {
            if (isset($certificate['Expire date'])) {
                $time = strtotime($certificate['Expire date']);
                if ($time && ($expires === null || $time < $expires)) {
                    $expires = $time;
                }
            }
        }

        $days = $expires === null ? null : (int) floor(($expires - time()) / 86400);
        $state = match (true) {
            $days !== null && $days < 0 => HealthStatus::Critical,
            $days !== null && $days <= 7 => HealthStatus::Critical,
            $days !== null && $days <= 14 => HealthStatus::Attention,
            default => HealthStatus::Healthy,
        };
        $reason = $days !== null && $days < 0
            ? MonitoringReason::TLS_EXPIRED
            : ($days !== null && $days <= 30 ? MonitoringReason::TLS_EXPIRING : MonitoringReason::MONITORING_OK);

        // A validated TLS connection is itself evidence of a valid chain. If the
        // handler does not expose certinfo, record that limited capability as
        // unknown rather than claiming a certificate lifetime we did not inspect.
        if ($expires === null && $origin->scheme !== 'https') {
            return;
        }
        if ($expires === null) {
            $state = HealthStatus::Unknown;
            $reason = MonitoringReason::TLS_METADATA_UNAVAILABLE;
        }

        $this->engine->record(new MonitoringObservation(
            checkId: 'website.tls',
            scope: MonitoringScope::Website,
            subjectType: 'website',
            subjectId: (string) $website->uuid,
            state: $state,
            severity: $state === HealthStatus::Critical ? MonitoringSeverity::Critical : ($state === HealthStatus::Attention ? MonitoringSeverity::Warning : ($days !== null && $days <= 30 ? MonitoringSeverity::Info : null)),
            reasonCode: $reason,
            source: 'active_probe',
            evidence: ['days_remaining' => $days],
            freshnessSeconds: 86400,
            failureThreshold: 1,
            recoveryThreshold: 1,
        ));
    }
}
