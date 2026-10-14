<?php

namespace App\Privacy;

enum PrivacyRequestStatus: string
{
    case Requested = 'requested';
    case IdentityVerificationRequired = 'identity_verification_required';
    case Verified = 'verified';
    case ReviewPending = 'review_pending';
    case BlockedPolicy = 'blocked_policy';
    case ManualReview = 'manual_review';
    case Cancelled = 'cancelled';

    /** Wave 1 deliberately has no processing/completed state: no export or erasure executor exists. */
    public function transitions(): array
    {
        return match ($this) {
            self::Requested => [self::IdentityVerificationRequired, self::ReviewPending, self::Cancelled, self::ManualReview],
            self::IdentityVerificationRequired => [self::Verified, self::Cancelled, self::ManualReview],
            self::Verified => [self::ReviewPending, self::BlockedPolicy, self::Cancelled, self::ManualReview],
            self::ReviewPending => [self::BlockedPolicy, self::Cancelled, self::ManualReview],
            self::BlockedPolicy => [self::ReviewPending, self::Cancelled, self::ManualReview],
            self::ManualReview => [self::ReviewPending, self::BlockedPolicy, self::Cancelled],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->transitions(), true);
    }

    public function isActive(): bool
    {
        return $this !== self::Cancelled;
    }
}
