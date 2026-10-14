<?php

namespace App\Privacy;

enum RetentionApprovalStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Superseded = 'superseded';
}
