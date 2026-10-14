<?php

namespace App\Privacy;

enum AnonymizationAction: string
{
    case ReplaceWithNonDeliverable = 'replace_with_non_deliverable';
    case ClearIfApproved = 'clear_if_approved';
    case RetainRelationalReference = 'retain_relational_reference';
    case RetainImmutableEvidence = 'retain_immutable_evidence';
    case SeparateRetentionReview = 'separate_retention_review';
    case NoSiteMutation = 'no_site_mutation';
}
