<?php

namespace App\Connector;

use App\Models\ConnectorPairingCode;
use App\Models\User;
use App\Models\Website;

/**
 * Issues one-time pairing codes. A code is shown to the customer once;
 * only its hash is stored. Issuing a new code invalidates older ones.
 */
final class PairingCodes
{
    /** No 0/O, 1/I/L: easy to read and type. 31^12 ≈ 2^59 combinations. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function issue(Website $website, ?User $by = null): string
    {
        $website->pairingCodes()->whereNull('used_at')->whereNull('invalidated_at')->update(['invalidated_at' => now()]);

        $raw = '';
        for ($i = 0; $i < 12; $i++) {
            $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        ConnectorPairingCode::create([
            'website_id' => $website->id,
            'code_hash' => self::hash($raw),
            'created_by' => $by?->id,
            'expires_at' => now()->addSeconds(config('connector.pairing_code_ttl')),
        ]);

        return implode('-', str_split($raw, 4));
    }

    /** Case, spaces and dashes do not matter when the code is typed in. */
    public static function hash(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)));
    }
}
