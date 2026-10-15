<?php

namespace App\Backups\Storage;

enum ObjectPresence: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Unknown = 'unknown';
}
