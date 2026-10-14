<?php

namespace App\Privacy;

enum PrivacyRequestType: string
{
    case Access = 'access';
    case Rectification = 'rectification';
    case AccountClosure = 'account_closure';
    case Erasure = 'erasure';
}
