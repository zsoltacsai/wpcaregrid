<?php

namespace App\Tax;

enum AamStatus: string
{
    case Unknown = 'unknown';
    case Declared = 'declared';
    case Confirmed = 'confirmed';
    case NotApplicable = 'not_applicable';
}
