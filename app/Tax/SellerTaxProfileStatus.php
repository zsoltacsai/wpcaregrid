<?php

namespace App\Tax;

enum SellerTaxProfileStatus: string
{
    case Unverified = 'unverified';
    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case ManualReview = 'manual_review';
    case Retired = 'retired';
}
