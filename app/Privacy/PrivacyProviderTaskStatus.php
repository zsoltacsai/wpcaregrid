<?php

namespace App\Privacy;

enum PrivacyProviderTaskStatus: string
{
    case Pending = 'pending';
    case Requested = 'requested';
    case Confirmed = 'confirmed';
    case RetainedLegal = 'retained_legal';
    case Failed = 'failed';
    case ManualReview = 'manual_review';
    case Unknown = 'unknown';

    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::Requested, self::Failed, self::RetainedLegal, self::ManualReview, self::Unknown],
            self::Requested => [self::Confirmed, self::RetainedLegal, self::Failed, self::ManualReview, self::Unknown],
            self::Failed => [self::Requested, self::ManualReview, self::Unknown],
            self::ManualReview => [self::Requested, self::RetainedLegal, self::Unknown],
            self::Unknown => [self::Requested, self::ManualReview],
            self::Confirmed, self::RetainedLegal => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->transitions(), true);
    }
}
