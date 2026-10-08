<?php

namespace App\Tax;

use App\Models\CustomerBillingIdentity;
use App\Models\TaxVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaxVerificationWorkflow
{
    public function open(CustomerBillingIdentity $identity, VerificationStatus $status = VerificationStatus::PendingVerification, ?string $reasonCode = null): TaxVerification
    {
        if (! in_array($status, [VerificationStatus::PendingVerification, VerificationStatus::ManualReview], true)) {
            throw ValidationException::withMessages(['status' => 'A new review must start pending or in manual review.']);
        }

        return DB::transaction(function () use ($identity, $status, $reasonCode) {
            CustomerBillingIdentity::query()->whereKey($identity->id)->lockForUpdate()->firstOrFail();
            $open = TaxVerification::query()
                ->where('customer_billing_identity_id', $identity->id)
                ->whereNull('resolved_at')
                ->whereIn('status', [VerificationStatus::PendingVerification->value, VerificationStatus::ManualReview->value])
                ->latest('id')
                ->first();
            if ($open) {
                return $open;
            }

            $verification = new TaxVerification;
            $verification->forceFill([
                'customer_billing_identity_id' => $identity->id,
                'status' => $status,
                'reason_code' => $reasonCode,
            ])->save();

            return $verification;
        }, attempts: 3);
    }

    /** Append the current review result; no tax decision is implied. */
    public function resolve(TaxVerification $verification, VerificationStatus $status, ?int $actorId, ?string $reasonCode): TaxVerification
    {
        if (! in_array($status, [VerificationStatus::Verified, VerificationStatus::Rejected, VerificationStatus::NotRequired], true)) {
            throw ValidationException::withMessages(['status' => 'This status cannot resolve a verification case.']);
        }

        return DB::transaction(function () use ($verification, $status, $actorId, $reasonCode) {
            $locked = TaxVerification::query()->whereKey($verification->id)->lockForUpdate()->firstOrFail();
            if ($locked->resolved_at) {
                throw ValidationException::withMessages(['status' => 'A resolved verification record is immutable; open a new review record.']);
            }
            $locked->forceFill([
                'status' => $status,
                'reason_code' => $reasonCode,
                'resolved_by' => $actorId,
                'resolved_at' => now(),
            ])->save();

            return $locked;
        }, attempts: 3);
    }
}
