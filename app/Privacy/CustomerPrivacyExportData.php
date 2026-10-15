<?php

namespace App\Privacy;

use App\Models\Customer;
use App\Models\PrivacyRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Explicit allowlist for the customer-facing privacy export. Never serialize Eloquent models. */
final class CustomerPrivacyExportData
{
    public const SCHEMA = 'privacy-export/v1';

    public const INCLUDED = ['account', 'profile', 'websites', 'subscriptions', 'billing_summary', 'privacy_requests'];

    public const EXCLUDED = [
        'tax_identifiers' => 'WAIT_ACCOUNTING',
        'immutable_invoice_and_tax_snapshots' => 'WAIT_ACCOUNTING',
        'raw_webhook_payloads' => 'EXCLUDED_SECURITY',
        'backup_archives' => 'NOT_INCLUDED_BY_DEFAULT',
        'backup_metadata' => 'WAIT_POLICY',
        'internal_security_and_operator_notes' => 'WAIT_LEGAL',
        'audit_and_internal_operations' => 'WAIT_LEGAL',
        'credentials_tokens_and_secrets' => 'EXCLUDED_SECURITY',
        'other_tenants' => 'EXCLUDED_TENANT_BOUNDARY',
    ];

    /** @return array{sections:array<string, mixed>,manifest:array<string,mixed>,coverage:string} */
    public function build(Customer $customer, PrivacyRequest $privacyRequest, Carbon $snapshotAt): array
    {
        abort_unless($privacyRequest->customer_id === $customer->id, 404);
        $categories = PrivacyDataCategoryRegistry::all();
        foreach (['ACCOUNT', 'CUSTOMER_PROFILE', 'WEBSITE', 'BILLING'] as $category) {
            if (! ($categories[$category]['export_candidate'] ?? false)) {
                throw new \RuntimeException('An allowed export category is disabled by the data-classification registry.');
            }
        }

        return DB::transaction(function () use ($customer, $privacyRequest, $snapshotAt): array {
            $lockedCustomer = Customer::query()->whereKey($customer->id)->firstOrFail();
            $user = $lockedCustomer->user()->first();
            $address = is_array($lockedCustomer->billing_address) ? $lockedCustomer->billing_address : [];

            $websites = $lockedCustomer->websites()->orderBy('id')->limit(5001)->get([
                'uuid', 'url', 'type', 'status', 'connector_status', 'wordpress_version', 'php_version', 'last_seen_at', 'created_at',
            ]);
            $subscriptions = $lockedCustomer->subscriptions()->orderBy('created_at')->limit(5001)->get([
                'uuid', 'product_type', 'plan', 'interval', 'currency', 'amount', 'status', 'current_period_start', 'current_period_end',
                'cancel_at_period_end', 'canceled_at', 'ended_at', 'created_at',
            ]);
            $invoices = $lockedCustomer->billingInvoices()->orderByDesc('stripe_created_at')->limit(5001)->get([
                'stripe_created_at', 'invoice_status', 'amount_paid', 'currency',
            ]);
            $privacyRequests = $lockedCustomer->privacyRequests()->orderBy('requested_at')->limit(5001)->get(['request_type', 'status', 'requested_at']);

            foreach ([$websites, $subscriptions, $invoices, $privacyRequests] as $records) {
                if ($records->count() > 5000) {
                    throw new \RuntimeException('Export exceeds the safe bounded section size.');
                }
            }

            $sections = [
                'account' => [
                    'login_email' => $user?->email,
                    'display_name' => $user?->name,
                    'email_verified_at' => $user?->email_verified_at?->utc()->toIso8601String(),
                    'locale' => $user?->locale,
                    'account_created_at' => $lockedCustomer->created_at?->utc()->toIso8601String(),
                    'account_active' => (bool) $lockedCustomer->active,
                ],
                'profile' => [
                    'first_name' => $lockedCustomer->first_name,
                    'last_name' => $lockedCustomer->last_name,
                    'contact_email' => $lockedCustomer->email,
                    'customer_type' => $lockedCustomer->customer_type,
                    'company_name' => $lockedCustomer->company_name,
                    'country_code' => $lockedCustomer->country_code,
                    'billing_address' => array_intersect_key($address, array_flip(['line1', 'line2', 'postal_code', 'city', 'region'])),
                ],
                'websites' => $websites->map(fn ($website) => [
                    'reference' => $website->uuid,
                    'url' => $website->url,
                    'type' => $website->type?->value ?? $website->type,
                    'status' => $website->status?->value ?? $website->status,
                    'connector_status' => $website->connector_status?->value ?? $website->connector_status,
                    'wordpress_version' => $website->wordpress_version,
                    'php_version' => $website->php_version,
                    'last_seen_at' => $website->last_seen_at?->utc()->toIso8601String(),
                    'created_at' => $website->created_at?->utc()->toIso8601String(),
                ])->values()->all(),
                'subscriptions' => $subscriptions->map(fn ($subscription) => [
                    'reference' => $subscription->uuid,
                    'product' => $subscription->product_type?->value,
                    'plan' => $subscription->plan?->value,
                    'billing_interval' => $subscription->interval?->value,
                    'currency' => $subscription->currency,
                    'amount_minor_units' => $subscription->amount,
                    'status' => $subscription->status?->value,
                    'current_period_start' => $subscription->current_period_start?->utc()->toIso8601String(),
                    'current_period_end' => $subscription->current_period_end?->utc()->toIso8601String(),
                    'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
                    'canceled_at' => $subscription->canceled_at?->utc()->toIso8601String(),
                    'ended_at' => $subscription->ended_at?->utc()->toIso8601String(),
                    'created_at' => $subscription->created_at?->utc()->toIso8601String(),
                ])->values()->all(),
                'billing_summary' => $invoices->map(fn ($invoice) => [
                    'issued_at' => $invoice->stripe_created_at?->utc()->toIso8601String(),
                    'status' => $invoice->invoice_status,
                    'amount_paid_minor_units' => $invoice->amount_paid,
                    'currency' => $invoice->currency,
                ])->values()->all(),
                'privacy_requests' => $privacyRequests->map(fn ($request) => [
                    'type' => $request->request_type->value,
                    'status' => $request->status->value,
                    'requested_at' => $request->requested_at?->utc()->toIso8601String(),
                ])->values()->all(),
            ];

            $sectionHashes = [];
            foreach ($sections as $name => $contents) {
                $sectionHashes[$name.'.json'] = hash('sha256', json_encode($contents, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            return [
                'sections' => $sections,
                'coverage' => 'partial',
                'manifest' => [
                    'schema' => self::SCHEMA,
                    'generated_at' => $snapshotAt->copy()->utc()->toIso8601String(),
                    'source_snapshot_at' => $snapshotAt->copy()->utc()->toIso8601String(),
                    'privacy_request_reference' => $privacyRequest->uuid,
                    'customer_reference' => $lockedCustomer->uuid,
                    'included_sections' => array_keys($sections),
                    'excluded_sections' => self::EXCLUDED,
                    'coverage' => 'partial',
                    'section_sha256' => $sectionHashes,
                ],
            ];
        }, attempts: 3);
    }
}
