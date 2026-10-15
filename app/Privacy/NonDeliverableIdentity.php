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

    /** Stable restore-safe alias derived only from the opaque customer UUID, never from the prior address. */
    public static function forSubject(string $customerUuid): string
    {
        if (! Str::isUuid($customerUuid)) {
            throw new \InvalidArgumentException('A valid opaque customer UUID is required.');
        }

        return 'deleted+'.str_replace('-', '', strtolower($customerUuid)).'@privacy.invalid';
    }
}
