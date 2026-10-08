<?php

namespace App\Tax;

use App\Models\SellerTaxProfile;
use App\Models\SellerTaxRegistration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SellerTaxProfileRegistry
{
    /** @param array<string, mixed> $attributes */
    public function publish(array $attributes): SellerTaxProfile
    {
        $start = Carbon::parse($attributes['effective_from'] ?? throw ValidationException::withMessages(['effective_from' => 'An effective-from date is required.']))->startOfDay();
        $end = isset($attributes['effective_to']) && $attributes['effective_to'] !== ''
            ? Carbon::parse($attributes['effective_to'])->startOfDay()
            : null;
        if ($end && $end->lt($start)) {
            throw ValidationException::withMessages(['effective_to' => 'The end date cannot precede the start date.']);
        }
        $aamStatus = $this->enumValue($attributes['aam_status'] ?? AamStatus::Unknown, AamStatus::class, 'aam_status');
        $verificationStatus = $this->enumValue($attributes['verification_status'] ?? SellerTaxProfileStatus::Unverified, SellerTaxProfileStatus::class, 'verification_status');
        $evidenceReference = filled($attributes['evidence_reference'] ?? null) ? trim((string) $attributes['evidence_reference']) : null;
        if (($verificationStatus === SellerTaxProfileStatus::Verified->value || $aamStatus === AamStatus::Confirmed->value) && ! $evidenceReference) {
            throw ValidationException::withMessages(['evidence_reference' => 'Verified seller facts require an evidence reference.']);
        }
        $country = isset($attributes['establishment_country']) ? strtoupper(trim((string) $attributes['establishment_country'])) : null;
        if ($country !== null && ! preg_match('/^[A-Z]{2}$/', $country)) {
            throw ValidationException::withMessages(['establishment_country' => 'Use a two-letter country code.']);
        }

        return DB::transaction(function () use ($attributes, $start, $end, $aamStatus, $verificationStatus, $country, $evidenceReference) {
            $this->lockRegistry();
            $profiles = SellerTaxProfile::query()->orderBy('id')->lockForUpdate()->get();
            foreach ($profiles as $profile) {
                $existingStart = $profile->effective_from;
                $existingEnd = $profile->effective_to;
                if (! $existingStart) {
                    continue;
                }
                $overlap = ($end === null || $existingStart->lte($end))
                    && ($existingEnd === null || $existingEnd->gte($start));
                if ($overlap) {
                    throw ValidationException::withMessages(['effective_from' => 'Seller tax profile effective periods cannot overlap.']);
                }
            }

            $profile = new SellerTaxProfile;
            $profile->forceFill([
                'version' => ((int) SellerTaxProfile::query()->max('version')) + 1,
                'legal_name' => $attributes['legal_name'] ?? null,
                'establishment_country' => $country,
                'seller_address' => $attributes['seller_address'] ?? null,
                'aam_status' => $aamStatus,
                'verification_status' => $verificationStatus,
                'effective_from' => $start,
                'effective_to' => $end,
                'evidence_reference' => $evidenceReference,
                'created_by' => $attributes['created_by'] ?? null,
            ]);
            $profile->save();

            return $profile;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $attributes */
    public function addRegistration(SellerTaxProfile $profile, array $attributes): SellerTaxRegistration
    {
        return DB::transaction(function () use ($profile, $attributes) {
            $this->lockRegistry();
            SellerTaxProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $type = $this->enumValue($attributes['registration_type'] ?? SellerTaxRegistrationType::Other, SellerTaxRegistrationType::class, 'registration_type');
            $verificationStatus = $this->enumValue($attributes['verification_status'] ?? SellerTaxProfileStatus::Unverified, SellerTaxProfileStatus::class, 'verification_status');
            $evidenceReference = filled($attributes['evidence_reference'] ?? null) ? trim((string) $attributes['evidence_reference']) : null;
            if ($verificationStatus === SellerTaxProfileStatus::Verified->value && ! $evidenceReference) {
                throw ValidationException::withMessages(['evidence_reference' => 'Verified registrations require an evidence reference.']);
            }
            $jurisdiction = isset($attributes['jurisdiction']) ? strtoupper((string) $attributes['jurisdiction']) : null;
            $start = isset($attributes['effective_from']) ? Carbon::parse($attributes['effective_from'])->startOfDay() : null;
            $end = isset($attributes['effective_to']) ? Carbon::parse($attributes['effective_to'])->startOfDay() : null;
            if ($jurisdiction !== null && ! preg_match('/^[A-Z]{2}$/', $jurisdiction)) {
                throw ValidationException::withMessages(['jurisdiction' => 'Use a two-letter country code.']);
            }
            if ($start && $end && $end->lt($start)) {
                throw ValidationException::withMessages(['effective_to' => 'The end date cannot precede the start date.']);
            }

            if ($start) {
                foreach (SellerTaxRegistration::query()
                    ->where('registration_type', $type)
                    ->where('jurisdiction', $jurisdiction)
                    ->orderBy('id')->lockForUpdate()->get() as $existing) {
                    $overlap = ($end === null || ! $existing->effective_from || $existing->effective_from->lte($end))
                        && (! $existing->effective_to || $existing->effective_to->gte($start));
                    if ($overlap) {
                        throw ValidationException::withMessages(['effective_from' => 'Registration effective periods cannot overlap for this type and jurisdiction.']);
                    }
                }
            }

            $identifier = filled($attributes['tax_identifier'] ?? null) ? trim((string) $attributes['tax_identifier']) : null;
            $registration = new SellerTaxRegistration;
            $registration->forceFill([
                'seller_tax_profile_id' => $profile->id,
                'registration_type' => $type,
                'jurisdiction' => $jurisdiction,
                'tax_identifier' => $identifier,
                'tax_identifier_hash' => $identifier ? $this->fingerprint($identifier) : null,
                'verification_status' => $verificationStatus,
                'effective_from' => $start,
                'effective_to' => $end,
                'evidence_reference' => $evidenceReference,
            ]);
            $registration->save();

            return $registration;
        }, attempts: 3);
    }

    private function lockRegistry(): void
    {
        $lock = DB::table('seller_tax_registry_locks')->where('id', 1)->lockForUpdate()->first();
        if (! $lock) {
            throw new \LogicException('Seller tax registry lock row is missing.');
        }

        DB::table('seller_tax_registry_locks')->where('id', 1)->update(['revision' => DB::raw('revision + 1')]);
    }

    /** @param class-string<\BackedEnum> $enum */
    private function enumValue(mixed $value, string $enum, string $field): string
    {
        if ($value instanceof $enum) {
            return $value->value;
        }

        if (is_string($value) && $enum::tryFrom($value)) {
            return $value;
        }

        throw ValidationException::withMessages([$field => 'The selected value is not supported.']);
    }

    private function fingerprint(string $identifier): string
    {
        $key = (string) (config('tax.identifiers.fingerprint_key') ?: config('app.key'));
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true) ?: $key;
        }

        return hash_hmac('sha256', mb_strtoupper(preg_replace('/\s+/u', '', trim($identifier)) ?? trim($identifier)), $key);
    }
}
