<?php

namespace App\Privacy;

enum PrivacyRequestSource: string
{
    case OwnerConsole = 'owner_console';
    case CustomerPortal = 'customer_portal';
    case Support = 'support';
    case System = 'system';
}
