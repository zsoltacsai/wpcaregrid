<?php

namespace App\Privacy;

use App\Models\Customer;
use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use App\Models\User;
use App\Owner\AdminAudit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/** Creates and transitions privacy cases without exporting or erasing customer data. */
final class PrivacyRequestService
{
    /** Restrict customer-area access without cancelling billing or erasing data. */
    public function restrictAccountAccess(PrivacyRequest $privacyRequest, User $actor): PrivacyRequest
    {
        $this->authorizeOwner($actor);

        return DB::transaction(function () use ($privacyRequest, $actor): PrivacyRequest {
            $case = PrivacyRequest::query()->whereKey($privacyRequest->getKey())->lockForUpdate()->firstOrFail();
            if ($case->request_type !== PrivacyRequestType::AccountClosure || ! in_array($case->status, [PrivacyRequestStatus::Requested, PrivacyRequestStatus::ManualReview], true)) {
                throw new LogicException('Only an open account-closure case can restrict access.');
            }

            $customer = Customer::query()->whereKey($case->customer_id)->lockForUpdate()->firstOrFail();
            if (! $customer->active) {
                throw ValidationException::withMessages(['closure' => 'The customer account is already inactive; an Owner must review its original suspension state before changing access.']);
            }
            if ($customer->activeSubscriptions()->exists()) {
                throw ValidationException::withMessages(['closure' => 'Cancel or end active subscriptions through the separate billing workflow before restricting account access.']);
            }

            $from = $case->status;
            $customer->forceFill(['active' => false])->save();
            $case->forceFill(['status' => PrivacyRequestStatus::AccessRestricted, 'lock_version' => $case->lock_version + 1])->save();
            $this->recordEvent($case, 'account_access_restricted', $from, PrivacyRequestStatus::AccessRestricted, $actor, ['reason_code' => 'owner_closure']);
            AdminAudit::record($actor, 'privacy.account_access.restricted', 'customer', $customer->uuid, ['privacy_request' => $case->uuid]);

            return $case->refresh();
        }, attempts: 3);
    }

    /** Reopen a reversibly closed account before any separate erasure workflow. */
    public function restoreAccountAccess(PrivacyRequest $privacyRequest, User $actor): PrivacyRequest
    {
        $this->authorizeOwner($actor);

        return DB::transaction(function () use ($privacyRequest, $actor): PrivacyRequest {
            $case = PrivacyRequest::query()->whereKey($privacyRequest->getKey())->lockForUpdate()->firstOrFail();
            if ($case->request_type !== PrivacyRequestType::AccountClosure || $case->status !== PrivacyRequestStatus::AccessRestricted) {
                throw new LogicException('Only an access-restricted account-closure case can be reopened.');
            }

            $customer = Customer::query()->whereKey($case->customer_id)->lockForUpdate()->firstOrFail();
            if ($customer->privacyRequests()->where('request_type', PrivacyRequestType::Erasure->value)->where('active_request_key', 'active')->exists()) {
                throw ValidationException::withMessages(['closure' => 'Resolve the separate active erasure request before reopening account access.']);
            }

            $customer->forceFill(['active' => true])->save();
            $case->forceFill([
                'status' => PrivacyRequestStatus::Cancelled,
                'active_request_key' => null,
                'lock_version' => $case->lock_version + 1,
            ])->save();
            $this->recordEvent($case, 'account_access_restored', PrivacyRequestStatus::AccessRestricted, PrivacyRequestStatus::Cancelled, $actor, ['reason_code' => 'owner_reopen']);
            AdminAudit::record($actor, 'privacy.account_access.restored', 'customer', $customer->uuid, ['privacy_request' => $case->uuid]);

            return $case->refresh();
        }, attempts: 3);
    }

    public function create(
        Customer $customer,
        PrivacyRequestType $type,
        PrivacyRequestSource $source,
        string $idempotencyKey,
        ?User $actor = null,
    ): PrivacyRequest {
        $this->authorizeActor($actor, $customer, allowCustomer: true);
        if ($source === PrivacyRequestSource::OwnerConsole && ($actor === null || ! $actor->isOwner() || $actor->customer()->exists())) {
            throw new AuthorizationException('Owner-console privacy cases require an authenticated Owner actor.');
        }
        $idempotencyHash = $this->hashKey($idempotencyKey);

        try {
            return DB::transaction(function () use ($customer, $type, $source, $idempotencyHash, $actor): PrivacyRequest {
                Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

                $sameKey = PrivacyRequest::query()->where('idempotency_hash', $idempotencyHash)->first();
                if ($sameKey) {
                    if ($sameKey->customer_id !== $customer->id || $sameKey->request_type !== $type) {
                        throw new InvalidArgumentException('The idempotency key is already used for a different privacy request.');
                    }

                    return $sameKey;
                }

                $active = PrivacyRequest::query()
                    ->where('customer_id', $customer->id)
                    ->where('request_type', $type->value)
                    ->where('active_request_key', 'active')
                    ->first();
                if ($active) {
                    return $active;
                }

                $request = PrivacyRequest::create([
                    'customer_id' => $customer->id,
                    'created_by_user_id' => $actor?->id,
                    'request_type' => $type,
                    'status' => PrivacyRequestStatus::Requested,
                    'active_request_key' => 'active',
                    'idempotency_hash' => $idempotencyHash,
                    'source' => $source,
                    'requested_at' => now(),
                ]);

                $this->recordEvent($request, 'created', null, PrivacyRequestStatus::Requested, $actor, ['source' => $source->value]);

                if ($actor?->isOwner()) {
                    AdminAudit::record($actor, 'privacy.request.created', 'privacy_request', $request->uuid, [
                        'request_type' => $type->value,
                        'status' => PrivacyRequestStatus::Requested->value,
                    ]);
                }

                return $request;
            }, attempts: 3);
        } catch (QueryException $exception) {
            // The database unique constraints are the final guard if another process wins the race.
            $sameKey = PrivacyRequest::query()->where('idempotency_hash', $idempotencyHash)->first();
            if ($sameKey && $sameKey->customer_id === $customer->id && $sameKey->request_type === $type) {
                return $sameKey;
            }

            $active = PrivacyRequest::query()
                ->where('customer_id', $customer->id)
                ->where('request_type', $type->value)
                ->where('active_request_key', 'active')
                ->first();
            if ($active) {
                return $active;
            }

            throw $exception;
        }
    }

    /** @param array<string, scalar|null> $metadata */
    public function transition(PrivacyRequest $privacyRequest, PrivacyRequestStatus $next, ?User $actor = null, array $metadata = []): PrivacyRequest
    {
        if ($actor !== null) {
            $caseCustomer = $privacyRequest->customer()->firstOrFail();
            $this->authorizeActor($actor, $caseCustomer, allowCustomer: false);
        }
        $safeMetadata = $this->safeMetadata($metadata);

        return DB::transaction(function () use ($privacyRequest, $next, $actor, $safeMetadata): PrivacyRequest {
            $case = PrivacyRequest::query()->whereKey($privacyRequest->getKey())->lockForUpdate()->firstOrFail();
            $current = $case->status;

            if ($case->request_type === PrivacyRequestType::AccountClosure && in_array($next, [PrivacyRequestStatus::CoolingOff, PrivacyRequestStatus::RetentionAnalysis], true)) {
                throw new LogicException('Account closure does not start the separate erasure cooling-off or retention-analysis workflow.');
            }

            if (! $current->canTransitionTo($next)) {
                throw new LogicException("Privacy request {$case->uuid} cannot go from {$current->value} to {$next->value}.");
            }

            $attributes = [
                'status' => $next,
                'lock_version' => $case->lock_version + 1,
            ];
            if ($next === PrivacyRequestStatus::Verified) {
                $attributes['identity_verified_at'] = now();
            }
            if (in_array($next, [PrivacyRequestStatus::ReviewPending, PrivacyRequestStatus::BlockedPolicy, PrivacyRequestStatus::BlockedLegal, PrivacyRequestStatus::BlockedAccounting, PrivacyRequestStatus::ManualReview], true)) {
                $attributes['reviewed_at'] = now();
                $attributes['reviewed_by_user_id'] = $actor?->id;
            }
            if (! $next->isActive()) {
                $attributes['active_request_key'] = null;
            }

            $case->forceFill($attributes)->save();
            $event = $next === PrivacyRequestStatus::Verified ? 'identity_verified' : 'status_changed';
            $this->recordEvent($case, $event, $current, $next, $actor, $safeMetadata);

            if ($actor?->isOwner()) {
                AdminAudit::record($actor, 'privacy.request.status_changed', 'privacy_request', $case->uuid, [
                    'from_status' => $current->value,
                    'to_status' => $next->value,
                ]);
            }

            return $case->refresh();
        }, attempts: 3);
    }

    private function recordEvent(PrivacyRequest $request, string $eventType, ?PrivacyRequestStatus $from, PrivacyRequestStatus $to, ?User $actor, array $metadata): void
    {
        PrivacyRequestEvent::create([
            'privacy_request_id' => $request->id,
            'actor_user_id' => $actor?->id,
            'event_type' => $eventType,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'metadata' => $metadata ?: null,
        ]);
    }

    private function safeMetadata(array $metadata): array
    {
        $allowed = [];
        if (isset($metadata['reason_code']) && is_string($metadata['reason_code']) && preg_match('/^[a-z0-9_]{1,40}$/', $metadata['reason_code'])) {
            $allowed['reason_code'] = $metadata['reason_code'];
        }

        return $allowed;
    }

    private function hashKey(string $key): string
    {
        if (! preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $key)) {
            throw new InvalidArgumentException('A non-secret idempotency key between 16 and 128 safe characters is required.');
        }

        return hash('sha256', $key);
    }

    private function authorizeActor(?User $actor, Customer $customer, bool $allowCustomer): void
    {
        if ($actor === null) {
            return;
        }

        if ($actor->isOwner()) {
            if (! $actor->customer()->exists()) {
                return;
            }

            throw new AuthorizationException('Owner accounts cannot also act through a customer identity.');
        }

        if (! $allowCustomer || $actor->isOperator() || ! $actor->customer()->whereKey($customer->id)->exists()) {
            throw new AuthorizationException('A privacy request actor may access only their own customer case.');
        }
    }

    private function authorizeOwner(User $actor): void
    {
        if (! $actor->isOwner() || $actor->customer()->exists()) {
            throw new AuthorizationException('Account access closure requires an authenticated Owner.');
        }
    }
}
