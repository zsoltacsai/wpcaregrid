<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Owner\AdminAudit;
use App\Tax\AamStatus;
use App\Tax\SellerTaxProfileRegistry;
use App\Tax\SellerTaxProfileStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SellerTaxProfileController extends Controller
{
    public function store(Request $request, SellerTaxProfileRegistry $registry): RedirectResponse
    {
        $input = $request->only('legal_name', 'establishment_country', 'seller_address', 'aam_status', 'verification_status', 'effective_from', 'effective_to', 'evidence_reference', 'aam_evidence_reference', 'supersedes_profile_id');

        try {
            if (array_diff(array_keys($request->except('_token')), array_keys($input)) !== []) {
                throw ValidationException::withMessages(['seller_profile' => __('owner.settings.seller_profile.invalid_input')]);
            }

            $isCorrection = ! empty($input['supersedes_profile_id']);
            $rules = [
                'aam_status' => ['required', Rule::enum(AamStatus::class)],
                'effective_from' => ['required', 'date_format:Y-m-d'],
                'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
                'evidence_reference' => ['nullable', 'string', 'max:200'],
                'aam_evidence_reference' => ['nullable', 'string', 'max:200'],
                'supersedes_profile_id' => ['nullable', 'integer', Rule::exists('seller_tax_profiles', 'id')],
            ];
            if (! $isCorrection) {
                $rules += [
                    'legal_name' => ['required', 'string', 'max:200'],
                    'establishment_country' => ['required', 'string', 'regex:/\A[A-Za-z]{2}\z/D'],
                    'verification_status' => ['required', Rule::in([
                        SellerTaxProfileStatus::Unverified->value,
                        SellerTaxProfileStatus::PendingVerification->value,
                        SellerTaxProfileStatus::Verified->value,
                        SellerTaxProfileStatus::ManualReview->value,
                    ])],
                    'seller_address' => ['required', 'array:address_line1,address_line2,postal_code,city,region,country_code'],
                    'seller_address.address_line1' => ['required', 'string', 'max:150'],
                    'seller_address.address_line2' => ['nullable', 'string', 'max:150'],
                    'seller_address.postal_code' => ['required', 'string', 'max:30'],
                    'seller_address.city' => ['required', 'string', 'max:120'],
                    'seller_address.region' => ['nullable', 'string', 'max:120'],
                    'seller_address.country_code' => ['required', 'string', 'regex:/\A[A-Za-z]{2}\z/D'],
                ];
            }
            $validated = Validator::make($input, $rules)->validate();
            if (! $isCorrection) {
                $validated['legal_name'] = trim($validated['legal_name']);
                $validated['establishment_country'] = strtoupper($validated['establishment_country']);
                foreach (['address_line1', 'postal_code', 'city', 'country_code'] as $field) {
                    $validated['seller_address'][$field] = trim($validated['seller_address'][$field]);
                }
                foreach (['address_line2', 'region'] as $field) {
                    $value = trim((string) ($validated['seller_address'][$field] ?? ''));
                    $validated['seller_address'][$field] = $value === '' ? null : $value;
                }
                $validated['seller_address']['country_code'] = strtoupper($validated['seller_address']['country_code']);
            }
        } catch (ValidationException $exception) {
            // Seller identity and evidence references must not be flashed back into session storage.
            return back()->withErrors($exception->errors())->withInput([]);
        }

        $actor = $request->user('owner') ?? abort(403);
        try {
            $profile = DB::transaction(function () use ($registry, $validated, $actor) {
                $profile = $registry->publish([
                    ...$validated,
                    'created_by' => $actor->id,
                ]);

                AdminAudit::record($actor, 'billing_tax.seller_profile_published', 'seller_tax_profile', (string) $profile->id, [
                    'version' => $profile->version,
                    'verification_status' => $profile->verification_status->value,
                    'effective_from' => $profile->effective_from->toDateString(),
                    'supersedes_profile_id' => $profile->supersedes_profile_id,
                    'field_names' => ['legal_name', 'establishment_country', 'seller_address', 'aam_status', 'verification_status', 'effective_from', 'effective_to', 'evidence_reference', 'aam_evidence_reference'],
                ]);

                return $profile;
            });
        } catch (ValidationException $exception) {
            // Registry-level overlap and evidence rules are validation errors too.
            return back()->withErrors($exception->errors())->withInput([]);
        }

        return to_route('owner.settings.index', ['section' => 'billing_tax'])
            ->with('status', __('owner.settings.billing_tax.seller_profile_management.published', ['version' => $profile->version]));
    }
}
