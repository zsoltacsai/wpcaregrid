<?php

namespace App\Privacy;

enum PrivacyRequestType: string
{
    case Access = 'access';
    case Rectification = 'rectification';
    case Erasure = 'erasure';
}
