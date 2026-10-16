<?php

namespace App\AdminAccess;

use App\Compatibility\CapabilityReadiness;
use App\Connector\Capability;
use App\Connector\ConnectorError;
use App\Connector\JobStatus;
use App\Connector\JobType;
use App\Connector\PairConnector;
use App\Connector\Presence;
use App\Models\AdminAccessRequest;
use App\Models\Connector;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteJob;
use App\Operator\Permission;
use App\Websites\Actor;
use App\Websites\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * WordPress admin access without WordPress passwords.
 *
 *   customer asks → request + one-time token (only its hash is stored)
 *   → the customer's browser posts the token to the site's wp-login.php
 *   → the site's connector claims it here, authenticated as the connector
 *   → CREATE_ADMIN_SESSION is handed to that connector → WordPress session
 *
 * The token is useless without the site's connector, and the connector
 * credential is useless without the token. Expiry, revocation and
 * disconnection all end access; every step is audited.
 */
class AdminAccessService
{
    /**
     * @return array{0: AdminAccessRequest, 1: string} The request and its one-time token (shown to nobody, sent to the browser once).
     *
     * @throws AdminAccessRefused
     */
    public function request(Website $website, ?User $user, ActorType $actor, AccessLevel $level, bool $confirmed = false): array
    {
        if ($refusal = $this->refusal($website, $user, $actor, $level, $confirmed)) {
            AuditLog::record($website, 'admin_access.failed', actor: $this->actorLabel($actor, $user), data: ['level' => $level->value, 'code' => $refusal, 'stage' => 'request']);

            throw new AdminAccessRefused($refusal);
        }

        return DB::transaction(function () use ($website, $user, $actor, $level) {
            // One open request per website and level: a new one replaces the old.
            $website->adminAccessRequests()->where('access_level', $level)->whereIn('status', ['pending', 'authorized', 'active'])
                ->get()->each(fn (AdminAccessRequest $old) => $this->revoke($old, $user, 'superseded'));

            $token = bin2hex(random_bytes(32));
            $request = $website->adminAccessRequests()->create([
                'requested_by_user_id' => $user?->id,
                'actor_type' => $actor,
                'access_level' => $level,
                'status' => AdminAccessStatus::Pending,
                'token_hash' => hash('sha256', $token),
                'token_expires_at' => now()->addSeconds((int) config('admin_access.token_ttl')),
                'confirmed_at' => $level === AccessLevel::FullAdmin ? now() : null,
            ]);

            $job = $website->jobs()->create([
                'type' => JobType::CreateAdminSession,
                'payload' => ['request_uuid' => $request->uuid, 'access_level' => $level->value],
                'status' => JobStatus::Queued,
                'requested_by' => substr($this->actorLabel($actor, $user), 0, 32),
            ]);
            $request->forceFill(['job_id' => $job->id])->save();

            AuditLog::record($website, 'admin_access.requested', actor: $this->actorLabel($actor, $user), access: $request, data: ['level' => $level->value]);

            return [$request, $token];
        });
    }

    /** Why this request may not be made, or null. */
    public function refusal(Website $website, ?User $user, ActorType $actor, AccessLevel $level, bool $confirmed): ?string
    {
        $connector = $website->activeConnector;

        $refusal = match (true) {
            // Customers only for their own websites (the policy checks this too).
            $actor === ActorType::Customer && ($user?->customer === null || $user->customer->id !== $website->customer_id || $user->isOperator()) => 'NOT_AUTHORIZED',
            // Operators: an operator login with the permission for this level, checked
            // here as well as on the route, so no caller can skip it.
            $actor === ActorType::Operator && ! $user?->hasOperatorPermission(Permission::AdminAccessRequest) => 'NOT_AUTHORIZED',
            $actor === ActorType::Operator && $level === AccessLevel::FullAdmin && ! config('admin_access.operator_full_admin') => 'FULL_ADMIN_REQUIRES_CUSTOMER',
            $actor === ActorType::Operator && $level === AccessLevel::FullAdmin && ! $user->hasOperatorPermission(Permission::AdminAccessFull) => 'NOT_AUTHORIZED',
            ! $website->allowsServiceOperations() => 'SUBSCRIPTION_INACTIVE',
            $connector === null => 'NOT_CONNECTED',
            $connector->presence() !== Presence::Connected => 'CONNECTOR_OFFLINE',
            ! $connector->can(Capability::AdminAccess) || ! $this->accessUrl($website) => 'CAPABILITY_MISSING',
            ! $this->levelAllowed($website, $level) => 'LEVEL_NOT_ALLOWED',
            $level === AccessLevel::FullAdmin && ! $confirmed => 'CONFIRMATION_REQUIRED',
            $website->adminAccessRequests()->where('created_at', '>', now()->subHour())->count() >= (int) config('admin_access.max_requests_per_hour') => 'TOO_MANY_REQUESTS',
            default => null,
        };

        return $refusal ?? app(CapabilityReadiness::class)->refusal($website, JobType::CreateAdminSession);
    }

    /**
     * The site presents the browser's token. Consumed exactly once, only by
     * the connector of the request's own website.
     *
     * @return array<string, mixed> The CREATE_ADMIN_SESSION job for that connector.
     */
    public function claim(Connector $connector, string $uuid, string $token): array
    {
        // Refusals are returned, not thrown, inside the transaction: failed
        // attempts and audit records must be committed, not rolled back.
        $outcome = DB::transaction(function () use ($connector, $uuid, $token) {
            $request = preg_match('/^[0-9a-f-]{36}$/', $uuid)
                ? AdminAccessRequest::where('uuid', $uuid)->where('website_id', $connector->website_id)->lockForUpdate()->first()
                : null;

            // Unknown, foreign and wrong-token requests look the same from outside.
            if (! $request) {
                return ['ACCESS_INVALID', 'This access link is not valid.'];
            }

            if (! preg_match('/^[a-f0-9]{64}$/', $token) || ! hash_equals($request->token_hash, hash('sha256', $token))) {
                $request->increment('failed_attempts');
                if ($request->failed_attempts >= (int) config('admin_access.max_failed_attempts') && $request->status->isOpen() && $request->status !== AdminAccessStatus::Active) {
                    $this->fail($request, 'TOO_MANY_ATTEMPTS');
                }

                return ['ACCESS_INVALID', 'This access link is not valid.'];
            }

            $refusal = match (true) {
                in_array($request->status, [AdminAccessStatus::Authorized, AdminAccessStatus::Active], true) => ['ACCESS_ALREADY_USED', 'This access link has already been used.'],
                $request->status === AdminAccessStatus::Revoked => ['ACCESS_REVOKED', 'This access was revoked.'],
                $request->status === AdminAccessStatus::Expired || $request->token_expires_at->isPast() => ['ACCESS_EXPIRED', 'This access link has expired.'],
                $request->status !== AdminAccessStatus::Pending => ['ACCESS_INVALID', 'This access link is not valid.'],
                ! $request->website->allowsServiceOperations() => ['SUBSCRIPTION_INACTIVE', 'The subscription for this website is not active.'],
                ! $connector->can(Capability::AdminAccess) || ! $this->levelAllowed($request->website, $request->access_level) => ['ACCESS_NOT_ALLOWED', 'This access is not allowed.'],
                default => null,
            };

            if ($refusal) {
                AuditLog::record($request->website, 'admin_access.failed', access: $request, data: ['level' => $request->access_level->value, 'code' => $refusal[0], 'stage' => 'claim']);
                if ($refusal[0] === 'ACCESS_EXPIRED' && $request->status === AdminAccessStatus::Pending) {
                    $this->finish($request, AdminAccessStatus::Expired, 'token_unused');
                }

                return $refusal;
            }

            $expires = now()->addMinutes($request->access_level->minutes());
            $request->transitionTo(AdminAccessStatus::Authorized, ['connector_id' => $connector->id, 'authorized_at' => now(), 'used_at' => now(), 'session_expires_at' => $expires]);

            $job = $request->job;
            $payload = JobType::CreateAdminSession->validatePayload([
                'request_uuid' => $request->uuid,
                'access_level' => $request->access_level->value,
                'website_uuid' => $request->website->uuid,
                'session_expires_at' => $expires->toIso8601String(),
            ]);
            $job->forceFill(['payload' => $payload, 'status' => JobStatus::Delivered, 'connector_id' => $connector->id, 'delivered_at' => now(), 'attempts' => 1])->save();

            AuditLog::record($request->website, 'admin_access.authorized', access: $request, data: ['level' => $request->access_level->value, 'expires_at' => $expires->toIso8601String()]);

            return ['job' => $job->toConnectorArray()];
        });

        if (! isset($outcome['job'])) {
            throw new ConnectorError($outcome[0], $outcome[1], 403);
        }

        return $outcome['job'];
    }

    /** The connector reported the CREATE_ADMIN_SESSION result. */
    public function jobFinished(AdminAccessRequest $request, WebsiteJob $job): void
    {
        $request->refresh();
        if ($request->status !== AdminAccessStatus::Authorized) {
            return;
        }

        if ($job->status === JobStatus::Success) {
            $request->transitionTo(AdminAccessStatus::Active, ['started_at' => now()]);
            AuditLog::record($request->website, 'admin_access.started', access: $request, data: ['level' => $request->access_level->value]);

            return;
        }

        $this->fail($request, (string) ($job->error['code'] ?? $job->result_code ?? 'SESSION_FAILED'));
    }

    /** The site ended the session itself (expired locally, revoked on the site, disconnected). */
    public function endedBySite(Connector $connector, string $uuid, string $reason): void
    {
        $request = AdminAccessRequest::where('uuid', $uuid)->where('website_id', $connector->website_id)->first()
            ?? throw new ConnectorError('ACCESS_INVALID', 'Unknown access request.', 404);

        if ($request->status->isOpen()) {
            $this->finish($request, $reason === 'expired' ? AdminAccessStatus::Expired : AdminAccessStatus::Revoked, substr($reason, 0, 32));
        }
    }

    public function revoke(AdminAccessRequest $request, ?User $by, ?string $reason = null): void
    {
        if (! $request->status->isOpen()) {
            return;
        }

        $reason ??= $by?->isOperator() ? 'revoked_by_operator' : 'revoked_by_customer';

        $request->job()->whereIn('status', [JobStatus::Queued->value, JobStatus::Delivered->value])->update(['status' => JobStatus::Cancelled->value]);
        $this->finish($request, AdminAccessStatus::Revoked, $reason, $by);
    }

    /** Every open request of a website ends, e.g. when its connector is revoked. */
    public function revokeAll(Website $website, string $reason): void
    {
        $website->adminAccessRequests()->whereIn('status', ['pending', 'authorized', 'active'])->get()
            ->each(fn (AdminAccessRequest $request) => $this->revoke($request, null, $reason));
    }

    /** Server-side expiry, independent of anyone visiting the site. */
    public function expireDue(): int
    {
        $count = 0;
        AdminAccessRequest::where('status', 'pending')->where('token_expires_at', '<', now())->get()
            ->each(function (AdminAccessRequest $request) use (&$count) {
                $request->job()->where('status', JobStatus::Queued->value)->update(['status' => JobStatus::Expired->value]);
                $this->finish($request, AdminAccessStatus::Expired, 'token_unused');
                $count++;
            });
        AdminAccessRequest::whereIn('status', ['authorized', 'active'])->where('session_expires_at', '<', now())->get()
            ->each(function (AdminAccessRequest $request) use (&$count) {
                $this->finish($request, AdminAccessStatus::Expired, 'session_ended');
                $count++;
            });

        return $count;
    }

    /**
     * What the site may keep: sent with every poll and heartbeat. The
     * connector ends any CareGrid session not in this list.
     *
     * @return list<array{request_uuid: string, access_level: string, session_expires_at: string}>
     */
    public function liveFor(Website $website): array
    {
        return $website->adminAccessRequests()->whereIn('status', ['authorized', 'active'])->where('session_expires_at', '>', now())->get()
            ->map(fn (AdminAccessRequest $request) => [
                'request_uuid' => $request->uuid,
                'access_level' => $request->access_level->value,
                'session_expires_at' => $request->session_expires_at->toIso8601String(),
            ])->values()->all();
    }

    /** The site's wp-login.php access URL, only when it belongs to the registered website. */
    public function accessUrl(Website $website): ?string
    {
        $url = $website->activeConnector?->admin_access_url;
        $scheme = parse_url((string) $url, PHP_URL_SCHEME);

        if (! $url || ! PairConnector::sameSite($website->url, $url) || ! in_array($scheme, app()->isProduction() ? ['https'] : ['https', 'http'], true)) {
            return null;
        }

        return $url;
    }

    public function levelAllowed(Website $website, AccessLevel $level): bool
    {
        $subscription = $website->subscription;

        // An internal plan says for itself whether it includes admin access (PROBONO: no).
        if ($subscription?->internal_plan !== null) {
            return $subscription->internal_plan->includesAdminAccess();
        }

        return $subscription?->product_type && $subscription->plan
            && (bool) config("admin_access.plans.{$subscription->product_type->value}.{$subscription->plan->value}.{$level->value}", false);
    }

    private function fail(AdminAccessRequest $request, string $code): void
    {
        $request->job()->whereIn('status', [JobStatus::Queued->value, JobStatus::Delivered->value])->update(['status' => JobStatus::Cancelled->value]);
        $request->transitionTo(AdminAccessStatus::Failed, ['failure_code' => substr($code, 0, 64), 'ended_at' => now()]);
        AuditLog::record($request->website, 'admin_access.failed', access: $request, data: ['level' => $request->access_level->value, 'code' => $code, 'stage' => 'session']);
    }

    private function finish(AdminAccessRequest $request, AdminAccessStatus $status, string $reason, ?User $by = null): void
    {
        $request->transitionTo($status, ['ended_at' => now(), 'end_reason' => $reason, 'revoked_by_user_id' => $by?->id]);
        AuditLog::record($request->website, $status === AdminAccessStatus::Expired ? 'admin_access.expired' : 'admin_access.revoked', actor: $by ? Actor::user($by)->label() : 'system', access: $request, data: ['level' => $request->access_level->value, 'reason' => $reason]);
    }

    private function actorLabel(ActorType $actor, ?User $user): string
    {
        return $actor->value.($user ? ':'.$user->id : '');
    }
}
