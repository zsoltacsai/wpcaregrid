<?php

namespace App\AiProviders;

enum ProviderHealthStatus: string
{
    case NotConfigured = 'NOT_CONFIGURED';
    case Unknown = 'UNKNOWN';
    case Healthy = 'HEALTHY';
    case AuthFailed = 'AUTH_FAILED';
    case RateLimited = 'RATE_LIMITED';
    case Unavailable = 'UNAVAILABLE';
    case Timeout = 'TIMEOUT';
    case Misconfigured = 'MISCONFIGURED';
    case Error = 'ERROR';
}
