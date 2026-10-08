<?php

namespace App\Tax\Vat;

enum VatValidationStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Malformed = 'malformed';
    case Unavailable = 'unavailable';
    case PendingVerification = 'pending_verification';
    case ManualReview = 'manual_review';
}
