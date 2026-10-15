<?php

namespace App\Privacy;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Explicit current-profile allowlist shared by the isolated executor and recovery replay. */
final class PrivacyProfileAnonymizer
{
    public function apply(Customer $customer): bool
    {
        $priorEmail = (string) $customer->getRawOriginal('email');
        $user = $customer->user_id !== null ? $customer->user()->lockForUpdate()->first() : null;
        $replacementEmail = NonDeliverableIdentity::forSubject($customer->uuid);
        $needsProfileChange = $customer->email !== $replacementEmail
            || $customer->first_name !== 'Deleted'
            || $customer->last_name !== 'Account'
            || $customer->company_name !== null
            || $customer->customer_type !== null
            || $customer->country_code !== 'ZZ'
            || $customer->billing_address !== null
            || $customer->tax_id !== null
            || $customer->tax_identifier_encrypted !== null
            || (bool) $customer->active
            || ($user !== null && ($user->email !== $replacementEmail || $user->name !== 'Deleted Account' || $user->email_verified_at !== null));

        if ($needsProfileChange) {
            $customer->forceFill([
                'email' => $replacementEmail,
                'first_name' => 'Deleted',
                'last_name' => 'Account',
                'company_name' => null,
                'customer_type' => null,
                'country_code' => 'ZZ',
                'billing_address' => null,
                'tax_id' => null,
                'tax_identifier_encrypted' => null,
                'active' => false,
            ])->save();

            if ($user !== null) {
                $user->forceFill([
                    'name' => 'Deleted Account',
                    'email' => $replacementEmail,
                    'email_verified_at' => null,
                    'password' => Hash::make(Str::random(64)),
                    'remember_token' => Str::random(60),
                    'locale' => config('app.locale', 'en'),
                ])->save();
            }
        }

        $removedAuthState = false;
        if ($user !== null && Schema::hasTable('sessions')) {
            $removedAuthState = DB::table('sessions')->where('user_id', $user->id)->delete() > 0 || $removedAuthState;
        }
        if (Schema::hasTable('password_reset_tokens')) {
            $emails = array_values(array_unique([$priorEmail, $replacementEmail]));
            $removedAuthState = DB::table('password_reset_tokens')->whereIn('email', $emails)->delete() > 0 || $removedAuthState;
        }
        if ($user !== null && Schema::hasTable('personal_access_tokens')) {
            $removedAuthState = DB::table('personal_access_tokens')
                ->where('tokenable_type', $user::class)->where('tokenable_id', $user->id)->delete() > 0 || $removedAuthState;
        }

        // Revoke only WP CareGrid's own connection/access credentials. The
        // customer's WordPress site and its content are never contacted or changed.
        foreach ($customer->websites()->with('connectors')->get() as $website) {
            foreach ($website->connectors as $connector) {
                if ($connector->isActive()) {
                    $connector->revoke('privacy_erasure', 'system');
                    $removedAuthState = true;
                }
            }
            if (Schema::hasTable('connector_pairing_codes')) {
                $removedAuthState = DB::table('connector_pairing_codes')
                    ->where('website_id', $website->id)->whereNull('used_at')->whereNull('invalidated_at')
                    ->update(['invalidated_at' => now('UTC'), 'updated_at' => now('UTC')]) > 0 || $removedAuthState;
            }
        }

        return $needsProfileChange || $removedAuthState;
    }
}
