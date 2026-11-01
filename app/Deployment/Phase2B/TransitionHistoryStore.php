<?php

namespace App\Deployment\Phase2B;

use App\Models\TransitionHistoryEvent;
use App\Models\TransitionV2Request;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/** Append-only history persistence; callers must share the fence/CAS transaction. */
final class TransitionHistoryStore
{
    /** @return Collection<int, TransitionHistoryEvent> */
    public function orderedForScope(string $operationId, int $generation): Collection
    {
        $events = TransitionHistoryEvent::query()
            ->where('operation_id', $operationId)
            ->where('generation', $generation)
            ->orderBy('event_sequence')
            ->get();

        $previousTargetDigest = null;
        foreach ($events as $index => $event) {
            if ((int) $event->event_sequence !== $index + 1
                || $event->source_tuple_digest !== $previousTargetDigest) {
                throw new RuntimeException('Phase 2B history readback has a disconnected sequence or source digest.');
            }

            $previousTargetDigest = $event->target_tuple_digest;
        }

        foreach ($events as $event) {
            $request = TransitionV2Request::query()
                ->where('request_id', $event->request_id)
                ->where('history_sequence', $event->event_sequence)
                ->first();
            $receipt = $request?->verifiedReceipt((string) $event->request_digest);
            if (! $request
                || ! $receipt
                || $event->request_digest !== $request->request_digest
                || $event->event_type !== TransitionHistoryEvent::HOLD_TUPLE_COMMITTED
                || $event->operation_id !== $request->operation_id
                || (int) $event->generation !== (int) $request->generation
                || $event->target_tuple_digest !== $request->target_tuple_digest
                || $event->source_tuple_digest !== $request->source_tuple_digest
                || $event->operation_id !== $receipt['target']['operation_id']
                || (int) $event->generation !== $receipt['target']['generation']) {
                throw new RuntimeException('Phase 2B history readback has an absent or mismatched request proof.');
            }
        }

        return $events;
    }
}
