<?php

namespace WPCareGrid\Connector\Jobs\Handlers;

use WPCareGrid\Connector\AdminAccess\AccessFailure;
use WPCareGrid\Connector\AdminAccess\AdminAccess;
use WPCareGrid\Connector\Core\Settings;
use WPCareGrid\Connector\Jobs\InvalidPayload;
use WPCareGrid\Connector\Jobs\JobHandler;
use WPCareGrid\Connector\Jobs\JobResult;

/**
 * CREATE_ADMIN_SESSION: a WordPress session for one authorised request.
 * Runs only inside the browser request that brought the token (Landing):
 * received any other way, it is refused. The payload names the request,
 * the level, this website and the expiry; nothing else is accepted.
 */
final class CreateAdminSessionHandler implements JobHandler
{
    /** Set by Landing for the duration of the browser request. */
    public static bool $browser = false;

    public function capability(array $payload = []): string
    {
        return 'admin_access';
    }

    public function validate(array $payload): array
    {
        InvalidPayload::check($payload, ['request_uuid', 'access_level', 'website_uuid', 'session_expires_at'], ['request_uuid', 'access_level', 'website_uuid', 'session_expires_at']);

        if (! in_array($payload['access_level'], ['maintenance', 'full_admin'], true)) {
            throw new InvalidPayload('Invalid access level.');
        }

        $expires = strtotime((string) $payload['session_expires_at']);
        if (! $expires) {
            throw new InvalidPayload('Invalid expiry.');
        }

        return [
            'request_uuid' => InvalidPayload::uuid((string) $payload['request_uuid']),
            'access_level' => $payload['access_level'],
            'website_uuid' => InvalidPayload::uuid((string) $payload['website_uuid']),
            'session_expires_at' => $expires,
        ];
    }

    public function handle(array $payload): JobResult
    {
        if (! self::$browser) {
            return JobResult::failed('ADMIN_SESSION_NEEDS_BROWSER', 'Admin sessions are only created when the customer opens the site.');
        }

        if (! hash_equals((string) Settings::get('website_uuid'), $payload['website_uuid'])) {
            return JobResult::failed('WRONG_WEBSITE', 'This access belongs to a different website.');
        }

        try {
            AdminAccess::start($payload['request_uuid'], $payload['access_level'], $payload['session_expires_at']);
        } catch (AccessFailure $e) {
            return JobResult::failed($e->error_code, $e->getMessage());
        }

        return JobResult::success('ADMIN_SESSION_STARTED', [
            'access_level' => $payload['access_level'],
            'session_expires_at' => gmdate('c', (int) AdminAccess::state()[$payload['access_level']]['expires_at']),
        ]);
    }
}
