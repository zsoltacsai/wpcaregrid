<?php

namespace App\Billing;

use App\Invoicing\SzamlazzTaxCodeRegistry;
use App\Models\BillingTaxConfigurationVersion;
use App\Models\SellerTaxProfile;
use App\Models\User;
use App\Owner\AdminAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Typed, effective-dated non-secret settings for Számlázz.hu billing. */
final class SzamlazzBillingSettings
{
    public const LANGUAGES = ['hu', 'en', 'de', 'it', 'ro', 'sk', 'hr', 'fr', 'es', 'cz', 'pl', 'bg', 'nl', 'ru', 'si'];

    public const PAYMENT_METHODS = ['bank_transfer', 'bank_card', 'cash', 'other'];

    public const PAYMENT_DEADLINES = ['same_day', 'days_8', 'days_15', 'days_30'];

    public const FULFILLMENT_POLICIES = ['payment_date', 'invoice_date'];

    public const CURRENCY_POLICIES = ['preserve_source', 'block_foreign_without_fx'];

    public const TAX_MODES = ['manual_review', 'approved_rules'];

    public function defaults(): array
    {
        return [
            'seller_profile_id' => null,
            'invoice_language' => 'hu',
            'default_payment_method' => 'bank_transfer',
            'payment_deadline_policy' => 'same_day',
            'fulfillment_date_policy' => 'payment_date',
            'invoice_prefix' => null,
            'notify_customer' => false,
            'currency_policy' => 'block_foreign_without_fx',
            'tax_configuration_mode' => 'manual_review',
            'default_tax_code' => null,
        ];
    }

    public function current(?\DateTimeInterface $at = null): ?BillingTaxConfigurationVersion
    {
        $at ??= now();

        return BillingTaxConfigurationVersion::query()
            ->where('effective_at', '<=', $at)
            ->orderByDesc('effective_at')->orderByDesc('version')->first();
    }

    public function values(?\DateTimeInterface $at = null): array
    {
        return $this->current($at)?->configuration ?? $this->defaults();
    }

    public function history(): Collection
    {
        return BillingTaxConfigurationVersion::query()->with('creator')->orderByDesc('version')->limit(50)->get();
    }

    public function validate(array $input): array
    {
        $known = [...array_keys($this->defaults()), 'effective_at'];
        if (array_diff(array_keys($input), $known) !== []) {
            throw ValidationException::withMessages(['settings' => __('owner.billing_tax.validation.unknown_field')]);
        }
        $validated = Validator::make($input, [
            'seller_profile_id' => ['nullable', 'integer', Rule::exists('seller_tax_profiles', 'id')],
            'invoice_language' => ['required', Rule::in(self::LANGUAGES)],
            'default_payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'payment_deadline_policy' => ['required', Rule::in(self::PAYMENT_DEADLINES)],
            'fulfillment_date_policy' => ['required', Rule::in(self::FULFILLMENT_POLICIES)],
            'invoice_prefix' => ['nullable', 'string', 'regex:/\A[A-Z0-9]{1,5}\z/D'],
            'notify_customer' => ['required', 'boolean'],
            'currency_policy' => ['required', Rule::in(self::CURRENCY_POLICIES)],
            'tax_configuration_mode' => ['required', Rule::in(self::TAX_MODES)],
            'default_tax_code' => ['nullable', 'string', Rule::in(array_keys(SzamlazzTaxCodeRegistry::all()))],
            'effective_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
        ])->validate();

        if ($validated['seller_profile_id'] !== null) {
            $profile = SellerTaxProfile::query()->find($validated['seller_profile_id']);
            $effective = Carbon::parse($validated['effective_at'])->startOfDay();
            if (! $profile || $profile->effective_from?->startOfDay()->gt($effective)
                || ($profile->effective_to && $profile->effective_to->endOfDay()->lt($effective))) {
                throw ValidationException::withMessages(['seller_profile_id' => __('owner.settings.billing_tax.validation.seller_profile_effective')]);
            }
        }

        return [
            'configuration' => [
                'seller_profile_id' => $validated['seller_profile_id'] === null ? null : (int) $validated['seller_profile_id'],
                'invoice_language' => $validated['invoice_language'],
                'default_payment_method' => $validated['default_payment_method'],
                'payment_deadline_policy' => $validated['payment_deadline_policy'],
                'fulfillment_date_policy' => $validated['fulfillment_date_policy'],
                'invoice_prefix' => $validated['invoice_prefix'] ?: null,
                'notify_customer' => (bool) $validated['notify_customer'],
                'currency_policy' => $validated['currency_policy'],
                'tax_configuration_mode' => $validated['tax_configuration_mode'],
                'default_tax_code' => $validated['default_tax_code'] ?: null,
            ],
            'effective_at' => $validated['effective_at'],
        ];
    }

    public function publish(array $input, User $actor): ?BillingTaxConfigurationVersion
    {
        $input['notify_customer'] = array_key_exists('notify_customer', $input) && filter_var($input['notify_customer'], FILTER_VALIDATE_BOOLEAN);
        foreach (['seller_profile_id', 'invoice_prefix', 'default_tax_code'] as $field) {
            if (($input[$field] ?? null) === '') {
                $input[$field] = null;
            }
        }
        $validated = $this->validate($input);
        if ($validated['configuration'] === $this->values() && $this->current()?->effective_at?->toDateString() === $validated['effective_at']) {
            return $this->current();
        }

        return DB::transaction(function () use ($validated, $actor): BillingTaxConfigurationVersion {
            $counter = DB::table('billing_tax_configuration_counters')->where('id', 1)->lockForUpdate()->first();
            if (! $counter) {
                throw new \RuntimeException('Billing tax settings version counter is missing.');
            }
            $version = (int) $counter->version + 1;
            DB::table('billing_tax_configuration_counters')->where('id', 1)->update(['version' => $version]);
            $row = BillingTaxConfigurationVersion::query()->create([
                'version' => $version,
                'effective_at' => $validated['effective_at'].' 00:00:00',
                'created_by' => $actor->id,
                'configuration' => $validated['configuration'],
                'created_at' => now(),
            ]);
            AdminAudit::record($actor, 'billing_tax.configuration_published', 'billing_tax_configuration', (string) $version, [
                'version' => $version,
                'effective_at' => $validated['effective_at'],
                'tax_mode' => $validated['configuration']['tax_configuration_mode'],
                'default_tax_code' => $validated['configuration']['default_tax_code'],
                'issuance_enabled' => false,
            ]);

            return $row;
        });
    }

    public function sellerProfiles(): \Illuminate\Database\Eloquent\Collection
    {
        return SellerTaxProfile::query()->orderByDesc('version')->limit(50)->get();
    }
}
