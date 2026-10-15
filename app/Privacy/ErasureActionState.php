<?php

namespace App\Privacy;

enum ErasureActionState: string
{
    case Blocked = 'blocked';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case ProviderPending = 'provider_pending';
    case Retained = 'retained';
    case ManualReview = 'manual_review';
    case Complete = 'complete';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
