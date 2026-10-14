<?php

namespace App\Privacy;

enum PrivacyRequestStatus: string
{
    case Requested = 'requested';
    case IdentityVerificationRequired = 'identity_verification_required';
    case Verified = 'verified';
    case CoolingOff = 'cooling_off';
    case RetentionAnalysis = 'retention_analysis';
    case ReviewPending = 'review_pending';
    case BlockedPolicy = 'blocked_policy';
    case BlockedLegal = 'blocked_legal';
    case BlockedAccounting = 'blocked_accounting';
    case AccessRestricted = 'access_restricted';
    case ManualReview = 'manual_review';
    case Failed = 'failed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** Wave 1 deliberately has no processing/completed state: no export or erasure executor exists. */
    public function transitions(): array
    {
        return match ($this) {
            self::Requested => [self::IdentityVerificationRequired, self::ReviewPending, self::Cancelled, self::ManualReview],
            self::IdentityVerificationRequired => [self::Verified, self::Cancelled, self::ManualReview],
            self::Verified => [self::CoolingOff, self::ReviewPending, self::BlockedPolicy, self::BlockedLegal, self::BlockedAccounting, self::Cancelled, self::ManualReview],
            self::CoolingOff => [self::RetentionAnalysis, self::ReviewPending, self::BlockedPolicy, self::BlockedLegal, self::BlockedAccounting, self::Cancelled, self::ManualReview],
            self::RetentionAnalysis => [self::ReviewPending, self::BlockedPolicy, self::BlockedLegal, self::BlockedAccounting, self::Cancelled, self::ManualReview],
            self::ReviewPending => [self::BlockedPolicy, self::BlockedLegal, self::BlockedAccounting, self::Cancelled, self::ManualReview],
            self::BlockedPolicy, self::BlockedLegal, self::BlockedAccounting => [self::ReviewPending, self::Cancelled, self::ManualReview],
            self::ManualReview => [self::ReviewPending, self::BlockedPolicy, self::BlockedLegal, self::BlockedAccounting, self::Cancelled],
            // Access restriction is reversible through the dedicated Owner workflow, not a generic status transition.
            self::AccessRestricted => [],
            // Terminal labels are reserved for later waves; Wave 5 exposes no transition into them.
            self::Cancelled, self::Failed, self::Completed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->transitions(), true);
    }

    public function isActive(): bool
    {
        return ! in_array($this, [self::Cancelled, self::Failed, self::Completed], true);
    }
}
