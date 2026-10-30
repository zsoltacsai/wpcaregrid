<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use LogicException;

/** A durable, finite DRAINING permit bound to one execution, work item and tenant/site. */
class TransitionAdmission extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('A durable transition permit cannot be deleted.');
        });

        static::saving(function (self $permit): void {
            if (! self::validPermit($permit)) {
                throw new InvalidArgumentException('Invalid or non-finite transition drain permit.');
            }

            if ($permit->exists) {
                foreach ([
                    'operation_id', 'generation', 'execution_id', 'work_id', 'payload_hash',
                    'tenant_id', 'site_id', 'worker_identity', 'effect_steps', 'effect_step_plan_hash',
                ] as $immutable) {
                    if ($permit->isDirty($immutable)) {
                        throw new InvalidArgumentException('A transition drain permit binding is immutable.');
                    }
                }

                $originalStatus = $permit->getOriginal('status');
                if ($originalStatus === 'CLOSED' && $permit->status !== 'CLOSED') {
                    throw new InvalidArgumentException('A closed transition drain permit cannot be reopened.');
                }
            }
        });
    }

    /** @param array<string, mixed> $tuple @param array<string, mixed> $binding */
    public function isUsableFor(array $tuple, array $binding): bool
    {
        if (! $this->exists
            || $tuple['state'] !== 'DRAINING'
            || $this->status !== 'ISSUED'
            || ! $this->issued_at
            || ! $this->expires_at
            || $this->issued_at->greaterThan(now())
            || $this->expires_at->lessThanOrEqualTo(now())
            || $this->operation_id !== $tuple['operation_id']
            || (int) $this->generation !== $tuple['generation']
            || $this->execution_id !== ($binding['execution_id'] ?? null)
            || $this->work_id !== ($binding['work_id'] ?? null)
            || $this->payload_hash !== ($binding['payload_hash'] ?? null)
            || $this->tenant_id !== ($binding['tenant_id'] ?? null)
            || $this->site_id !== ($binding['site_id'] ?? null)
            || $this->worker_identity !== ($binding['worker_identity'] ?? null)) {
            return false;
        }

        $stepIndex = $binding['step_index'] ?? null;
        if (! is_int($stepIndex) || $stepIndex < 0 || ! is_array($this->effect_steps)) {
            return false;
        }

        $step = $this->effect_steps[$stepIndex] ?? null;

        return is_array($step)
            && ($step['step_index'] ?? null) === $stepIndex
            && is_string($step['step_id'] ?? null)
            && ($step['idempotency_key'] ?? null) === ($binding['idempotency_key'] ?? null);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(TransitionExecution::class);
    }

    /**
     * Hash format for the app-side DRAINING manifest: immutable permit bindings, sorted by
     * execution/work ID and JSON encoded with the same canonical field order as manifestEntry.
     */
    public static function manifestHashForScope(string $operationId, int $generation): ?string
    {
        $entries = self::query()
            ->where('operation_id', $operationId)
            ->where('generation', $generation)
            ->get()
            ->map(fn (self $permit): array => $permit->manifestEntry())
            ->all();

        if ($entries === []) {
            return null;
        }

        usort($entries, static fn (array $left, array $right): int => [$left['execution_id'], $left['work_id']] <=> [$right['execution_id'], $right['work_id']]
        );

        return hash('sha256', json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function manifestEntry(): array
    {
        $steps = [];
        foreach ($this->effect_steps as $step) {
            $steps[] = [
                'step_index' => $step['step_index'],
                'step_id' => $step['step_id'],
                'idempotency_key' => $step['idempotency_key'],
            ];
        }

        return [
            'operation_id' => $this->operation_id,
            'generation' => (int) $this->generation,
            'execution_id' => $this->execution_id,
            'work_id' => $this->work_id,
            'payload_hash' => $this->payload_hash,
            'tenant_id' => $this->tenant_id,
            'site_id' => $this->site_id,
            'worker_identity' => $this->worker_identity,
            'effect_steps' => $steps,
            'effect_step_plan_hash' => $this->effect_step_plan_hash,
            'issued_at' => $this->issued_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $this->expires_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'effect_steps' => 'array',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    private static function validPermit(self $permit): bool
    {
        $steps = $permit->effect_steps;
        if (! is_array($steps) || $steps === [] || ! array_is_list($steps)) {
            return false;
        }

        $normalized = [];
        $keys = [];
        foreach ($steps as $index => $step) {
            if (! is_array($step)
                || array_keys($step) !== ['step_index', 'step_id', 'idempotency_key']
                || ($step['step_index'] ?? null) !== $index
                || ! self::isOpaqueIdentifier($step['step_id'] ?? null, 128)
                || ! self::isOpaqueIdentifier($step['idempotency_key'] ?? null, 191)
                || isset($keys[$step['idempotency_key']])) {
                return false;
            }
            $keys[$step['idempotency_key']] = true;
            $normalized[] = [
                'step_index' => $index,
                'step_id' => $step['step_id'],
                'idempotency_key' => $step['idempotency_key'],
            ];
        }

        $canonical = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return self::isUuidV7((string) $permit->operation_id)
            && (int) $permit->generation >= 0
            && self::isOpaqueIdentifier($permit->execution_id, 191)
            && self::isOpaqueIdentifier($permit->work_id, 191)
            && self::isSha256($permit->payload_hash)
            && self::isOpaqueIdentifier($permit->tenant_id, 191)
            && self::isOpaqueIdentifier($permit->site_id, 191)
            && self::isOpaqueIdentifier($permit->worker_identity, 191)
            && self::isSha256($permit->effect_step_plan_hash)
            && hash_equals(hash('sha256', $canonical), (string) $permit->effect_step_plan_hash)
            && in_array($permit->status, ['ISSUED', 'CLOSED'], true)
            && $permit->issued_at !== null
            && $permit->expires_at !== null
            && $permit->expires_at->greaterThan($permit->issued_at)
            && ($permit->status !== 'ISSUED' || $permit->closed_at === null)
            && ($permit->status !== 'CLOSED' || $permit->closed_at !== null);
    }

    private static function isOpaqueIdentifier(mixed $value, int $maxLength): bool
    {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= $maxLength
            && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
    }

    private static function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
    }

    private static function isUuidV7(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $value) === 1;
    }
}
