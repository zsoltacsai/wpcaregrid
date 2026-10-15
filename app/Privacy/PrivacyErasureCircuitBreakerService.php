<?php

namespace App\Privacy;

use App\Models\PrivacyErasureCircuitBreaker;
use App\Models\User;
use App\Owner\AdminAudit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Durable stop control. Resetting it never changes a destructive gate. */
final class PrivacyErasureCircuitBreakerService
{
    private const KEY = 'global';

    private const REASONS = [
        'journal_failure', 'authorization_anomaly', 'tenant_mismatch', 'accounting_mutation',
        'provider_inconsistency', 'repeated_failure', 'recovery_unavailable', 'scope_exceeded',
    ];

    /** @return array{available:bool,is_open:bool,reason_code:?string,opened_at:?string} */
    public function status(): array
    {
        if (! Schema::hasTable('privacy_erasure_circuit_breakers')) {
            return ['available' => false, 'is_open' => true, 'reason_code' => 'state_store_unavailable', 'opened_at' => null];
        }
        $row = PrivacyErasureCircuitBreaker::query()->find(self::KEY);

        return [
            'available' => true,
            'is_open' => (bool) $row?->is_open,
            'reason_code' => $row?->reason_code,
            'opened_at' => $row?->opened_at?->copy()->utc()->toIso8601String(),
        ];
    }

    public function trip(string $reasonCode, ?User $actor = null): void
    {
        if (! in_array($reasonCode, self::REASONS, true)) {
            throw new RuntimeException('Unknown privacy execution circuit-breaker reason.');
        }
        if (! Schema::hasTable('privacy_erasure_circuit_breakers')) {
            throw new RuntimeException('Privacy execution circuit-breaker state store is unavailable.');
        }

        DB::transaction(function () use ($reasonCode, $actor): void {
            $row = PrivacyErasureCircuitBreaker::query()->whereKey(self::KEY)->lockForUpdate()->first();
            if ($row?->is_open) {
                return;
            }
            $row ??= new PrivacyErasureCircuitBreaker(['breaker_key' => self::KEY]);
            $row->forceFill([
                'is_open' => true,
                'reason_code' => $reasonCode,
                'opened_at' => now('UTC'),
                'opened_by_user_id' => $actor?->id,
                'reset_at' => null,
                'reset_by_user_id' => null,
            ])->save();
            AdminAudit::record($actor, 'privacy.erasure.circuit_open', 'privacy_erasure_circuit_breaker', self::KEY, ['reason_code' => $reasonCode]);
        }, attempts: 3);
    }

    public function reset(User $actor, string $password, string $typedConfirmation): void
    {
        if (! $actor->isOwner() || $actor->customer()->exists()) {
            throw new AuthorizationException('Only an Owner can reset the privacy execution circuit breaker.');
        }
        if (! Hash::check($password, (string) $actor->getAuthPassword())) {
            throw ValidationException::withMessages(['password' => 'Owner password confirmation failed.']);
        }
        if ($typedConfirmation !== 'RESET PRIVACY EXECUTION CIRCUIT') {
            throw ValidationException::withMessages(['confirmation' => 'Type the exact circuit reset phrase.']);
        }
        if (! Schema::hasTable('privacy_erasure_circuit_breakers')) {
            throw new RuntimeException('Privacy execution circuit-breaker state store is unavailable.');
        }

        DB::transaction(function () use ($actor): void {
            $row = PrivacyErasureCircuitBreaker::query()->whereKey(self::KEY)->lockForUpdate()->first();
            if (! $row?->is_open) {
                return;
            }
            $row->forceFill(['is_open' => false, 'reset_at' => now('UTC'), 'reset_by_user_id' => $actor->id])->save();
            AdminAudit::record($actor, 'privacy.erasure.circuit_reset', 'privacy_erasure_circuit_breaker', self::KEY, ['previous_reason_code' => $row->reason_code]);
        }, attempts: 3);
    }
}
