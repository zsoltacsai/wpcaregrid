<?php

namespace App\Tax;

enum VerificationStatus: string
{
    case NotRequired = 'not_required';
    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case ManualReview = 'manual_review';
    case Rejected = 'rejected';
}
