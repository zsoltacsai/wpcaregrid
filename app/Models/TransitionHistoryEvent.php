<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

/** Append-only, digest-only record of a HOLD-preserving Phase 2B tuple commit. */
class TransitionHistoryEvent extends Model
{
    public const HOLD_TUPLE_COMMITTED = 'HOLD_TUPLE_COMMITTED';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (self $event): void {
            if ($event->exists) {
                throw new LogicException('A Phase 2B transition history event is immutable.');
            }

            if (! is_int($event->event_sequence)
                || $event->event_sequence < 1
                || ! self::isUuidV7($event->operation_id)
                || ! is_int($event->generation)
                || $event->generation < 0
                || ! self::isUuidV7($event->request_id)
                || ! self::isSha256($event->request_digest)
                || $event->event_type !== self::HOLD_TUPLE_COMMITTED
                || ($event->source_tuple_digest !== null && ! self::isSha256($event->source_tuple_digest))
                || ! self::isSha256($event->target_tuple_digest)
                || $event->occurred_at === null) {
                throw new InvalidArgumentException('Invalid Phase 2B transition history event.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('A Phase 2B transition history event cannot be deleted.');
        });
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'event_sequence' => 'integer',
            'generation' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    private static function isUuidV7(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $value) === 1;
    }

    private static function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
    }
}
