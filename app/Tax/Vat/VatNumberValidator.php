<?php

namespace App\Tax\Vat;

use App\Models\CustomerBillingIdentity;
use App\Models\VatValidation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

final class VatNumberValidator
{
    public function __construct(
        private readonly VatNumberNormalization $normalization,
        private readonly VatNumberProvider $provider,
    ) {}

    public function validate(string $countryCode, string $input, ?CustomerBillingIdentity $identity = null, bool $forceRefresh = false): VatValidationResult
    {
        try {
            $vat = $this->normalization->normalize($countryCode, $input);
        } catch (\InvalidArgumentException) {
            return $this->record(
                VatValidationStatus::Malformed,
                null,
                $identity,
                null,
                null,
                'malformed',
                strtoupper(substr(trim($countryCode), 0, 2)),
                $input,
            );
        }

        $ip = app()->runningInConsole() ? 'console' : (string) request()->ip();
        $limitKey = 'vies:'.hash('sha256', $vat->fingerprint.'|'.$ip);
        $perMinute = max(1, (int) config('tax.vies.rate_limit_per_minute', 5));
        if (RateLimiter::tooManyAttempts($limitKey, $perMinute)) {
            return $this->record(VatValidationStatus::PendingVerification, $vat, $identity, null, null, 'rate_limited', null, $input);
        }
        RateLimiter::hit($limitKey, 60);

        $cacheKey = 'vies:result:'.$vat->fingerprint;
        $cached = $forceRefresh ? null : Cache::get($cacheKey);
        $fromCache = is_array($cached);
        $evidenceData = $cached;

        if (! $fromCache) {
            try {
                $providerResult = $this->provider->validate($vat->countryCode, $vat->number);
            } catch (Throwable) {
                // Provider failures are always availability states, never invalid VAT IDs.
                return $this->record(VatValidationStatus::Unavailable, $vat, $identity, null, null, 'provider_unavailable', null, $input);
            }

            $evidenceData = [
                'status' => $providerResult->valid ? VatValidationStatus::Valid->value : VatValidationStatus::Invalid->value,
                'request_date' => $providerResult->requestDate,
                'reference_id' => $providerResult->referenceId,
                'name' => $providerResult->name,
                'address' => $providerResult->address,
            ];
            $seconds = max(0, (int) config('tax.vies.cache_seconds', 900));
            if ($seconds > 0) {
                // Keep cache retention to decision metadata only; names and
                // addresses remain only in encrypted evidence storage.
                Cache::put($cacheKey, array_intersect_key($evidenceData, array_flip(['status', 'request_date', 'reference_id'])), $seconds);
            }
        }

        $status = VatValidationStatus::from($evidenceData['status']);

        return $this->record(
            $status,
            $vat,
            $identity,
            $evidenceData['reference_id'] ?? null,
            $evidenceData,
            $fromCache ? 'cached_result' : 'provider_result',
            null,
            $input,
        );
    }

    private function record(
        VatValidationStatus $status,
        ?NormalizedVatNumber $vat,
        ?CustomerBillingIdentity $identity,
        ?string $providerReference,
        ?array $providerData,
        string $messageCode,
        ?string $fallbackCountryCode = null,
        ?string $originalIdentifier = null,
    ): VatValidationResult {
        $now = now();
        $evidence = new VatValidation;
        $evidence->forceFill([
            'customer_billing_identity_id' => $identity?->id,
            'provider' => in_array($messageCode, ['provider_result', 'cached_result', 'provider_unavailable'], true)
                ? 'eu_vies'
                : 'local_validation',
            'status' => $status,
            'country_code' => $vat?->countryCode ?? $fallbackCountryCode,
            'original_vat_identifier' => $originalIdentifier,
            'vat_identifier' => $vat?->number ?? null,
            'vat_identifier_hash' => $vat?->fingerprint,
            'provider_reference' => $providerReference,
            'provider_request_date' => $providerData['request_date'] ?? null,
            'provider_name' => $providerData['name'] ?? null,
            'provider_address' => $providerData['address'] ?? null,
            'provider_name_match' => $this->match($providerData['name'] ?? null, $identity?->legal_name),
            'provider_address_match' => $this->match($providerData['address'] ?? null, $identity ? $this->addressFor($identity) : null),
            'checked_at' => $now,
            'expires_at' => in_array($status, [VatValidationStatus::Valid, VatValidationStatus::Invalid], true)
                ? $now->copy()->addSeconds(max(60, (int) config('tax.vies.cache_seconds', 900)))
                : null,
            'message_code' => $messageCode,
        ]);
        $evidence->save();

        return new VatValidationResult($status, $evidence->id, Carbon::parse($evidence->checked_at), $providerReference, $messageCode);
    }

    private function match(?string $provider, ?string $customer): string
    {
        if (! $provider || ! $customer) {
            return 'not_returned';
        }

        $normalize = static fn (string $value): string => mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value));

        return hash_equals($normalize($provider), $normalize($customer)) ? 'exact_match' : 'mismatch';
    }

    private function addressFor(CustomerBillingIdentity $identity): ?string
    {
        $parts = array_filter([$identity->address_line1, $identity->address_line2, $identity->postal_code, $identity->city, $identity->region]);

        return $parts === [] ? null : implode(', ', $parts);
    }
}
