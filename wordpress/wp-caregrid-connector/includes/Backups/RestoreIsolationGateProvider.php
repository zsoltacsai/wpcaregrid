<?php

namespace WPCareGrid\Connector\Backups;

/**
 * Host/provider contract for destructive restore isolation.
 *
 * An implementation must not report acquire() success until it has atomically
 * gated every public origin/CDN path, drained in-flight requests, and quiesced
 * all supported WordPress, cron, queue, CLI, and deployment writers. The gate
 * must survive Connector process death and must not expire automatically while
 * a restore may be incomplete. Implementations must compare the generation on
 * every assertion/release and preserve a previous gate throughout recovery. A
 * recovery handoff is two-phase: acquire() activates the new generation without
 * releasing the previous one; commit() retires the previous generation only
 * after the new lease is durably recorded by the Connector.
 */
interface RestoreIsolationGateProvider
{
    /** A stable, non-secret provider identifier; this method must have no side effects. */
    public function provider_id(): string;

    /**
     * Acquire or resume the same pending generation while retaining any
     * previous generation. Repeated calls for the same restore UUID/scope
     * return the existing pending lease, making a crash before marker publish
     * recoverable. Callers persist the returned lease before commit().
     */
    public function acquire(RestoreIsolationLease $lease, ?RestoreIsolationLease $previous): ?RestoreIsolationLease;

    /** True only while the same generation remains active and writers quiescent. */
    public function assert_active(RestoreIsolationLease $lease): bool;

    /** Retire only the previous generation after the current lease is durable; idempotent. */
    public function commit(RestoreIsolationLease $current, RestoreIsolationLease $previous): bool;

    /**
     * Release only this exact generation. Repeating a completed release must
     * succeed idempotently; an unknown or mismatched generation leaves the
     * host gate closed and returns false.
     */
    public function release(RestoreIsolationLease $lease): bool;
}
