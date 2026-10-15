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
use Illuminate\Support\Facades\Hash;
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

    /** Create and identity-verify an access/rectification case after current-password confirmation. */
    public function createCustomerRequest(Customer $customer, PrivacyRequestType $type, string $idempotencyKey, User $actor, string $password): PrivacyRequest
    {
        if (! in_array($type, [PrivacyRequestType::Access, PrivacyRequestType::Rectification], true)
            || ! $actor->customer()->whereKey($customer->id)->exists()
            || ! Hash::check($password, (string) $actor->getAuthPassword())) {
            throw ValidationException::withMessages(['password' => __('passwords.password')]);
        }

        $case = $this->create($customer, $type, PrivacyRequestSource::CustomerPortal, $idempotencyKey, $actor);

        return DB::transaction(function () use ($case, $actor): PrivacyRequest {
            $locked = PrivacyRequest::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === PrivacyRequestStatus::Verified) {
                return $locked;
            }
            if (! in_array($locked->status, [PrivacyRequestStatus::Requested, PrivacyRequestStatus::IdentityVerificationRequired], true)) {
                throw ValidationException::withMessages(['privacy' => 'This privacy request is already under review or complete.']);
            }

            $from = $locked->status;
            $locked->forceFill([
                'status' => PrivacyRequestStatus::Verified,
                'identity_verified_at' => now('UTC'),
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            $this->recordEvent($locked, 'identity_verified', $from, PrivacyRequestStatus::Verified, $actor, []);

            return $locked->refresh();
        }, attempts: 3);
    }

    /** Complete a customer rectification case using only the canonical editable profile flow. */
    public function completeRectification(PrivacyRequest $privacyRequest, Customer $customer, User $actor, array $changedFields): PrivacyRequest
    {
        $allowedFields = ['first_name', 'last_name', 'customer_type', 'company_name', 'country_code', 'tax_id', 'billing_address', 'locale'];
        $fieldCodes = array_values(array_unique(array_filter($changedFields, fn ($field) => is_string($field) && in_array($field, $allowedFields, true))));
        if ($fieldCodes === [] || ! $actor->customer()->whereKey($customer->id)->exists()) {
            throw new AuthorizationException('A verified customer profile correction is required.');
        }

        return DB::transaction(function () use ($privacyRequest, $customer, $actor, $fieldCodes): PrivacyRequest {
            $case = PrivacyRequest::query()->whereKey($privacyRequest->getKey())->lockForUpdate()->firstOrFail();
            if ($case->customer_id !== $customer->id || $case->request_type !== PrivacyRequestType::Rectification || $case->status !== PrivacyRequestStatus::Verified) {
                throw new AuthorizationException('The rectification case is not available to this customer.');
            }

            $from = $case->status;
            $case->forceFill(['status' => PrivacyRequestStatus::Completed, 'active_request_key' => null, 'lock_version' => $case->lock_version + 1])->save();
            $this->recordEvent($case, 'rectification_completed', $from, PrivacyRequestStatus::Completed, $actor, ['field_codes' => $fieldCodes]);

            return $case->refresh();
        }, attempts: 3);
    }

    /** Append a minimized export lifecycle event after verifying case ownership or Owner authority. */
    public function recordExportEvent(PrivacyRequest $privacyRequest, string $eventType, ?User $actor = null, array $metadata = []): void
    {
        $allowedEvents = ['export_requested', 'export_queued', 'export_generating', 'export_generated', 'export_failed', 'export_cleanup_failed', 'export_downloaded', 'export_link_renewed', 'export_revoked', 'export_expired'];
        if (! in_array($eventType, $allowedEvents, true)) {
            throw new InvalidArgumentException('Unknown privacy export event.');
        }

        $safe = [];
        if (isset($metadata['artifact_reference']) && is_string($metadata['artifact_reference']) && preg_match('/^[0-9a-f-]{36}$/i', $metadata['artifact_reference'])) {
            $safe['artifact_reference'] = $metadata['artifact_reference'];
        }
        if (isset($metadata['failure_code']) && is_string($metadata['failure_code']) && preg_match('/^[a-z0-9_]{1,48}$/', $metadata['failure_code'])) {
            $safe['failure_code'] = $metadata['failure_code'];
        }
        if (isset($metadata['download_count']) && is_int($metadata['download_count']) && $metadata['download_count'] >= 0) {
            $safe['download_count'] = $metadata['download_count'];
        }
        if (isset($metadata['section_codes']) && is_array($metadata['section_codes'])) {
            $safe['section_codes'] = array_values(array_intersect($metadata['section_codes'], ['account', 'profile', 'websites', 'subscriptions', 'billing_summary', 'privacy_requests', 'backup_metadata']));
        }

        DB::transaction(function () use ($privacyRequest, $actor, $eventType, $safe): void {
            $case = PrivacyRequest::query()->whereKey($privacyRequest->getKey())->lockForUpdate()->firstOrFail();
            if ($actor !== null) {
                if ($actor->isOwner() && ! $actor->customer()->exists()) {
                    // Authorized Owner-assisted handling.
                } elseif ($actor->customer()->whereKey($case->customer_id)->exists() && ! $actor->isOperator()) {
                    // Customer activity is always confined to its own case.
                } else {
                    throw new AuthorizationException('Privacy export activity is not available to this actor.');
                }
            }

            PrivacyRequestEvent::create([
                'privacy_request_id' => $case->id,
                'actor_user_id' => $actor?->id,
                'event_type' => $eventType,
                'from_status' => $case->status->value,
                'to_status' => $case->status->value,
                'metadata' => $safe ?: null,
            ]);
        }, attempts: 3);
    }

    /** Mark an access case complete only after its complete, verified artifact is available. */
    public function completeAccessExport(PrivacyRequest $privacyRequest): PrivacyRequest
    {
        return DB::transaction(function () use ($privacyRequest): PrivacyRequest {
            $case = PrivacyRequest::query()->whereKey($privacyRequest->getKey())->lockForUpdate()->firstOrFail();
            if ($case->request_type !== PrivacyRequestType::Access || $case->status !== PrivacyRequestStatus::Verified) {
                throw new LogicException('Only a verified access case can be completed by export generation.');
            }

            $from = $case->status;
            $case->forceFill(['status' => PrivacyRequestStatus::Completed, 'active_request_key' => null, 'lock_version' => $case->lock_version + 1])->save();
            $this->recordEvent($case, 'export_generated', $from, PrivacyRequestStatus::Completed, null, []);

            return $case->refresh();
        }, attempts: 3);
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
