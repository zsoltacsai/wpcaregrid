<?php

namespace App\Privacy;

use Illuminate\Support\Str;

/** Creates a non-reversible replacement address for a future approved anonymization workflow. */
final class NonDeliverableIdentity
{
    public static function email(): string
    {
        return 'deleted+'.Str::uuid().'@privacy.invalid';
    }
}
