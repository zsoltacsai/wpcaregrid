<?php

namespace App\Privacy;

enum ErasurePlanState: string
{
    case PreviewReady = 'preview_ready';
    case Blocked = 'blocked';
    case ApprovedBlocked = 'approved_blocked';
    case Invalidated = 'invalidated';
    case Cancelled = 'cancelled';
    case Partial = 'partial';
    case ManualReview = 'manual_review';
}
