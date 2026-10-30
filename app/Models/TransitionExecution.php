<?php

namespace App\Models;

use App\Deployment\TransitionAdmission as TransitionAdmissionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/** Durable per-step intent and outcome; an existing row permanently prevents replay. */
class TransitionExecution extends Model
{
    public const OUTCOME_ALREADY_RECORDED = 'A transition effect outcome can only be recorded once from the current INTENT row.';

    protected $guarded = ['id'];

    private static bool $claimInProgress = false;

    /**
     * Lock and revalidate the current fence and permit, then persist one unique INTENT atomically.
     * The policy validates that the caller already holds the stable root lock in SHARED mode.
     *
     * @param  array{execution_id:string,work_id:string,payload_hash:string,tenant_id:string,site_id:string,worker_identity:string,step_index:int,step_id:string,idempotency_key:string}  $binding
     */
    public static function claimFromDrain(
        TransitionAdmissionPolicy $policy,
        DeploymentTransitionFence $fence,
        TransitionAdmission $permit,
        array $binding,
    ): self {
        $policy->assertDrainPermit($fence, $permit, $binding);

        try {
            return DB::transaction(function () use ($policy, $fence, $permit, $binding): self {
                $lockedFence = DeploymentTransitionFence::query()
                    ->whereKey($fence->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $lockedFence
                    || ! $fence->hasValidTuple()
                    || ! $lockedFence->hasValidTuple()
                    || $lockedFence->tuple() !== $fence->tuple()
                    || $lockedFence->permit_manifest_hash !== $fence->permit_manifest_hash) {
                    throw new RuntimeException('The locked transition fence does not match the validated fence tuple.');
                }

                $lockedPermit = TransitionAdmission::query()
                    ->whereKey($permit->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $lockedPermit) {
                    throw new RuntimeException('The drain permit no longer exists.');
                }

                $policy->assertDrainPermit($lockedFence, $lockedPermit, $binding);

                self::$claimInProgress = true;
                try {
                    return self::create([
                        'transition_admission_id' => $lockedPermit->getKey(),
                        'effect_step_index' => $binding['step_index'],
                        'step_id' => $binding['step_id'],
                        'idempotency_key' => $binding['idempotency_key'],
                        'payload_hash' => $binding['payload_hash'],
                        'status' => 'INTENT',
                        'intent_at' => now(),
                    ]);
                } finally {
                    self::$claimInProgress = false;
                }
            });
        } catch (QueryException $exception) {
            if (self::query()
                ->where('transition_admission_id', $permit->getKey())
                ->where('effect_step_index', $binding['step_index'])
                ->exists()) {
                throw new RuntimeException('The drain effect step has already been claimed and cannot be replayed.', 0, $exception);
            }

            throw $exception;
        }
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new InvalidArgumentException('A durable transition effect record cannot be deleted.');
        });

        static::saving(function (self $execution): void {
            $terminalStatuses = ['SUCCEEDED', 'FAILED', 'UNKNOWN'];
            if (! in_array($execution->status, ['INTENT', ...$terminalStatuses], true)
                || ! is_int($execution->effect_step_index)
                || $execution->effect_step_index < 0
                || ! is_string($execution->step_id)
                || $execution->step_id === ''
                || ! is_string($execution->idempotency_key)
                || $execution->idempotency_key === ''
                || ! is_string($execution->payload_hash)
                || preg_match('/\A[0-9a-f]{64}\z/D', $execution->payload_hash) !== 1
                || $execution->intent_at === null
                || ($execution->status === 'INTENT' && ($execution->outcome_at !== null || $execution->outcome_digest !== null))
                || (in_array($execution->status, $terminalStatuses, true)
                    && ($execution->outcome_at === null
                        || ! is_string($execution->outcome_digest)
                        || preg_match('/\A[0-9a-f]{64}\z/D', $execution->outcome_digest) !== 1))) {
                throw new InvalidArgumentException('Invalid transition effect intent or outcome.');
            }

            if (! $execution->exists && $execution->status !== 'INTENT') {
                throw new InvalidArgumentException('A transition effect must be durably claimed as INTENT before an outcome.');
            }
            if (! $execution->exists && ! self::$claimInProgress) {
                throw new InvalidArgumentException('A transition effect INTENT must use the atomic permit claim operation.');
            }

            if ($execution->exists && $execution->isDirty(['transition_admission_id', 'effect_step_index', 'step_id', 'idempotency_key', 'payload_hash', 'intent_at'])) {
                throw new InvalidArgumentException('A transition effect intent is immutable.');
            }

            if ($execution->exists) {
                $originalStatus = $execution->getOriginal('status');
                if (in_array($originalStatus, $terminalStatuses, true)) {
                    throw new InvalidArgumentException('A terminal transition effect outcome cannot be changed.');
                }
                if ($originalStatus !== 'INTENT'
                    || ! in_array($execution->status, ['INTENT', ...$terminalStatuses], true)
                    || ($execution->status === 'INTENT' && $execution->isDirty(['outcome_at', 'outcome_digest']))) {
                    throw new InvalidArgumentException('A transition effect can only move once from INTENT to a terminal outcome.');
                }
            }

            $admission = TransitionAdmission::query()->find($execution->transition_admission_id);
            if (! $admission || ! $admission->matchesExecutionBinding($execution)) {
                throw new InvalidArgumentException('A transition effect must exactly match its immutable permit step binding.');
            }

            if (! $execution->exists && ! $admission->isUsableFor([
                'state' => 'DRAINING',
                'operation_id' => $admission->operation_id,
                'generation' => (int) $admission->generation,
            ], [
                'execution_id' => $admission->execution_id,
                'work_id' => $admission->work_id,
                'payload_hash' => $execution->payload_hash,
                'tenant_id' => $admission->tenant_id,
                'site_id' => $admission->site_id,
                'worker_identity' => $admission->worker_identity,
                'step_index' => $execution->effect_step_index,
                'step_id' => $execution->step_id,
                'idempotency_key' => $execution->idempotency_key,
            ])) {
                throw new InvalidArgumentException('A new transition effect INTENT requires an exact, live ISSUED permit.');
            }
        });
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(TransitionAdmission::class, 'transition_admission_id');
    }

    /**
     * Compare and set terminal outcomes while holding the execution row lock.
     * A stale INTENT instance must not overwrite an outcome saved by another process.
     */
    protected function performUpdate(Builder $query): bool
    {
        if ($this->getOriginal('status') !== 'INTENT'
            || ! in_array($this->status, ['SUCCEEDED', 'FAILED', 'UNKNOWN'], true)) {
            return parent::performUpdate($query);
        }

        return DB::transaction(function () use ($query): bool {
            $locked = static::query()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->status !== 'INTENT') {
                throw new RuntimeException(self::OUTCOME_ALREADY_RECORDED);
            }

            foreach (['transition_admission_id', 'effect_step_index', 'step_id', 'idempotency_key', 'payload_hash'] as $attribute) {
                if ($locked->getAttribute($attribute) !== $this->getAttribute($attribute)) {
                    throw new RuntimeException('The locked transition effect binding does not match the pending outcome.');
                }
            }

            if (! parent::performUpdate($query)) {
                throw new RuntimeException('The transition effect outcome compare-and-set did not update the INTENT row.');
            }

            return true;
        });
    }

    protected function casts(): array
    {
        return [
            'effect_step_index' => 'integer',
            'intent_at' => 'datetime',
            'outcome_at' => 'datetime',
        ];
    }
}
