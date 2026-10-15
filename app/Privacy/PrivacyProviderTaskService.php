<?php

namespace App\Privacy;

use App\Models\PrivacyProviderTask;
use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use App\Models\User;
use App\Owner\AdminAudit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Records future reconciliation work only. No provider client is injected or called. */
final class PrivacyProviderTaskService
{
    public function create(
        PrivacyRequest $request,
        PrivacyProvider $provider,
        string $scopeCode,
        ?string $scopeReference,
        string $idempotencyKey,
        ?User $actor = null,
    ): PrivacyProviderTask {
        if ($actor !== null && (! $actor->isOwner() || $actor->customer()->exists())) {
            throw new AuthorizationException('Provider reconciliation tasks are restricted to Owner/system workflows.');
        }
        if (! PrivacyDataCategoryRegistry::has($scopeCode)) {
            throw new InvalidArgumentException('Unknown privacy data category.');
        }
        if ($scopeReference !== null && ! preg_match('/^(?:[0-9]{1,20}|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})$/', $scopeReference)) {
            throw new InvalidArgumentException('Scope reference must be an internal numeric or UUID identifier.');
        }
        if (! preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $idempotencyKey)) {
            throw new InvalidArgumentException('A non-secret idempotency key between 16 and 128 safe characters is required.');
        }

        $idempotencyHash = hash('sha256', $idempotencyKey);

        try {
            return DB::transaction(function () use ($request, $provider, $scopeCode, $scopeReference, $idempotencyHash, $actor): PrivacyProviderTask {
                $case = PrivacyRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
                $existing = PrivacyProviderTask::query()->where('idempotency_hash', $idempotencyHash)->first();
                if ($existing) {
                    if ($existing->privacy_request_id !== $case->id || $existing->provider !== $provider || $existing->scope_code !== $scopeCode || $existing->scope_reference !== $scopeReference) {
                        throw new InvalidArgumentException('The provider-task idempotency key is already used for another scope.');
                    }

                    return $existing;
                }

                $task = PrivacyProviderTask::create([
                    'privacy_request_id' => $case->id,
                    'provider' => $provider,
                    'scope_code' => $scopeCode,
                    'scope_reference' => $scopeReference,
                    'status' => PrivacyProviderTaskStatus::Pending,
                    'idempotency_hash' => $idempotencyHash,
                ]);

                PrivacyRequestEvent::create([
                    'privacy_request_id' => $case->id,
                    'actor_user_id' => $actor?->id,
                    'event_type' => 'provider_task_created',
                    'from_status' => null,
                    'to_status' => $case->status->value,
                    'metadata' => ['provider' => $provider->value, 'scope_code' => $scopeCode],
                ]);

                if ($actor?->isOwner()) {
                    AdminAudit::record($actor, 'privacy.provider_task.created', 'privacy_request', $case->uuid, [
                        'provider' => $provider->value,
                        'scope_code' => $scopeCode,
                    ]);
                }

                return $task;
            }, attempts: 3);
        } catch (QueryException $exception) {
            $existing = PrivacyProviderTask::query()->where('idempotency_hash', $idempotencyHash)->first();
            if ($existing && $existing->privacy_request_id === $request->id && $existing->provider === $provider && $existing->scope_code === $scopeCode && $existing->scope_reference === $scopeReference) {
                return $existing;
            }

            throw $exception;
        }
    }

    /** Records a provider reconciliation state without making any provider call. */
    public function transition(PrivacyProviderTask $task, PrivacyProviderTaskStatus $next, ?User $actor = null, ?string $errorCategory = null): PrivacyProviderTask
    {
        if ($actor !== null && (! $actor->isOwner() || $actor->customer()->exists())) {
            throw new AuthorizationException('Provider reconciliation tasks are restricted to Owner/system workflows.');
        }
        if ($errorCategory !== null && ! preg_match('/^[a-z0-9_]{1,40}$/', $errorCategory)) {
            throw new InvalidArgumentException('Error category must be a safe code.');
        }

        return DB::transaction(function () use ($task, $next, $actor, $errorCategory): PrivacyProviderTask {
            // Creation locks request first then task. Keep the same order here to avoid deadlocks.
            $requestId = PrivacyProviderTask::query()->whereKey($task->getKey())->value('privacy_request_id');
            $request = PrivacyRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();
            $locked = PrivacyProviderTask::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();
            $current = $locked->status;
            if (! $current->canTransitionTo($next)) {
                throw new InvalidArgumentException("Provider task {$locked->uuid} cannot go from {$current->value} to {$next->value}.");
            }

            $attributes = ['status' => $next];
            if (in_array($next, [PrivacyProviderTaskStatus::Failed, PrivacyProviderTaskStatus::Unknown], true)) {
                $attributes['error_category'] = $errorCategory ?? ($next === PrivacyProviderTaskStatus::Unknown ? 'provider_state_unknown' : 'unspecified');
                $attributes['attempts'] = $locked->attempts + 1;
                $attributes['last_attempted_at'] = now();
            }
            if (in_array($next, [PrivacyProviderTaskStatus::Confirmed, PrivacyProviderTaskStatus::RetainedLegal], true)) {
                $attributes['reconciled_at'] = now();
            }

            $locked->forceFill($attributes)->save();
            PrivacyRequestEvent::create([
                'privacy_request_id' => $request->id,
                'actor_user_id' => $actor?->id,
                'event_type' => 'provider_task_status_changed',
                'from_status' => $current->value,
                'to_status' => $next->value,
                'metadata' => ['provider' => $locked->provider->value, 'scope_code' => $locked->scope_code] + ($errorCategory ? ['error_category' => $errorCategory] : []),
            ]);

            if ($actor?->isOwner()) {
                AdminAudit::record($actor, 'privacy.provider_task.status_changed', 'privacy_request', $request->uuid, [
                    'provider' => $locked->provider->value,
                    'from_status' => $current->value,
                    'to_status' => $next->value,
                    'error_category' => $errorCategory,
                ]);
            }

            return $locked->refresh();
        }, attempts: 3);
    }
}
