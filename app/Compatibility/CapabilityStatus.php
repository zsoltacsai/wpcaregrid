<?php

namespace App\Compatibility;

enum CapabilityStatus: string
{
    case Pass = 'PASS';
    case Warn = 'WARN';
    case Block = 'BLOCK';
    case Unknown = 'UNKNOWN';
}
