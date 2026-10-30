<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/** Durable per-step intent and outcome; an existing row permanently prevents replay. */
class TransitionExecution extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new InvalidArgumentException('A durable transition effect record cannot be deleted.');
        });

        static::saving(function (self $execution): void {
            if (! in_array($execution->status, ['INTENT', 'SUCCEEDED', 'FAILED', 'UNKNOWN'], true)
                || ! is_int($execution->effect_step_index)
                || $execution->effect_step_index < 0
                || ! is_string($execution->step_id)
                || $execution->step_id === ''
                || ! is_string($execution->idempotency_key)
                || $execution->idempotency_key === ''
                || ! is_string($execution->payload_hash)
                || preg_match('/\A[0-9a-f]{64}\z/D', $execution->payload_hash) !== 1
                || $execution->intent_at === null
                || ($execution->status === 'INTENT' && $execution->outcome_at !== null)
                || (in_array($execution->status, ['SUCCEEDED', 'FAILED', 'UNKNOWN'], true)
                    && ($execution->outcome_at === null
                        || ! is_string($execution->outcome_digest)
                        || preg_match('/\A[0-9a-f]{64}\z/D', $execution->outcome_digest) !== 1))) {
                throw new InvalidArgumentException('Invalid transition effect intent or outcome.');
            }

            if ($execution->exists && $execution->isDirty(['transition_admission_id', 'effect_step_index', 'step_id', 'idempotency_key', 'payload_hash', 'intent_at'])) {
                throw new InvalidArgumentException('A transition effect intent is immutable.');
            }

            if ($execution->exists && in_array($execution->getOriginal('status'), ['SUCCEEDED', 'FAILED', 'UNKNOWN'], true)) {
                throw new InvalidArgumentException('A terminal transition effect outcome cannot be changed.');
            }
        });
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(TransitionAdmission::class, 'transition_admission_id');
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
