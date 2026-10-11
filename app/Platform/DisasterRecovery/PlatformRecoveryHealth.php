<?php

namespace App\Platform\DisasterRecovery;

enum PlatformRecoveryHealth: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unavailable = 'unavailable';
    case VerificationFailed = 'verification_failed';
    case ReconciliationRequired = 'reconciliation_required';
    case Stale = 'stale';
    case NotConfigured = 'not_configured';
}
