<?php

namespace App\Privacy;

enum PrivacyExportStatus: string
{
    case Queued = 'queued';
    case Generating = 'generating';
    case Available = 'available';
    case Failed = 'failed';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case CleanupFailed = 'cleanup_failed';
}
