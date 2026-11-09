<?php

namespace App\Pricing\StripeCatalogue;

/** Which Stripe environment a key (and so a catalogue) belongs to. Never guessed from anything but the key prefix. */
enum StripeMode: string
{
    case Test = 'test';
    case Live = 'live';

    /** The mode a secret or restricted key declares, or null when it declares none we know. */
    public static function fromKey(string $key): ?self
    {
        return match (true) {
            str_starts_with($key, 'sk_test_'), str_starts_with($key, 'rk_test_') => self::Test,
            str_starts_with($key, 'sk_live_'), str_starts_with($key, 'rk_live_') => self::Live,
            default => null,
        };
    }
}
