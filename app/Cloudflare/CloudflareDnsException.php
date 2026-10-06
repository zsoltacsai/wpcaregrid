<?php

namespace App\Cloudflare;

use RuntimeException;

/** Safe, token-free Cloudflare failure categories for UI, audits and health checks. */
final class CloudflareDnsException extends RuntimeException
{
    public function __construct(public readonly string $category)
    {
        parent::__construct(match ($category) {
            'not_configured' => 'Cloudflare API token is not configured.',
            'authentication' => 'Cloudflare rejected the API token.',
            'permission' => 'The Cloudflare token does not have the required permission.',
            'rate_limited' => 'Cloudflare rate-limited the request.',
            'unavailable' => 'Cloudflare could not be reached.',
            'invalid_response' => 'Cloudflare returned an invalid response.',
            'zone_not_allowed' => 'This DNS zone is not approved for WP CareGrid.',
            'zone_not_found' => 'The approved DNS zone is not visible to this token.',
            'zone_ambiguous' => 'Cloudflare returned more than one exact match for the DNS zone.',
            'record_not_found' => 'The DNS record no longer exists in the selected zone.',
            'record_protected' => 'This DNS record name is protected from changes.',
            'record_conflict' => 'Multiple matching DNS records exist; resolve the conflict before changing them.',
            'invalid_record' => 'The DNS record is invalid or outside the approved zone.',
            default => 'The Cloudflare DNS operation failed.',
        });
    }
}
