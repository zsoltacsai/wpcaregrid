<?php

namespace App\Invoicing;

use App\Billing\SzamlazzBillingSettings;
use App\Invoicing\Providers\ProviderSubmissionResult;
use App\Invoicing\Providers\SyntheticInvoiceTestProvider;
use App\Models\SzamlazzSyntheticTestRun;
use App\Models\User;
use App\Owner\AdminAudit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** One-shot, Owner-attested sandbox exercise. It is separate from tax-approved invoicing. */
final class SyntheticSzamlazzTestWorkflow
{
    public function __construct(
        private readonly SyntheticSzamlazzTestGuard $guard,
        private readonly SyntheticInvoiceTestProvider $provider,
        private readonly SzamlazzBillingSettings $billingSettings,
    ) {}

    public function prepare(array $input, User $actor, string $requestHost): SzamlazzSyntheticTestRun
    {
        abort_unless($this->guard->permits($requestHost), 404);
        if (array_diff(array_keys($input), ['account_test_mode_confirmed', 'test_seller_confirmed', 'confirmation_phrase', 'tax_code']) !== []) {
            throw ValidationException::withMessages(['test' => __('owner.synthetic_invoice_test.invalid_input')]);
        }
        $validated = Validator::make($input, [
            'account_test_mode_confirmed' => ['required', 'accepted'],
            'test_seller_confirmed' => ['required', 'accepted'],
            'confirmation_phrase' => ['required', 'in:SYNTHETIC TEST ONLY'],
            'tax_code' => ['required', 'in:AAM'],
        ])->validate();
        abort_unless(SzamlazzTaxCodeRegistry::supports($validated['tax_code']), 422);
        $configurationVersion = $this->billingSettings->current();
        $configuration = $configurationVersion?->configuration ?? $this->billingSettings->defaults();
        $invoicePrefix = $configuration['invoice_prefix'] ?? null;
        if (! is_string($invoicePrefix) || preg_match('/\A[A-Z0-9]{1,5}\z/D', $invoicePrefix) !== 1) {
            throw ValidationException::withMessages(['test' => __('owner.synthetic_invoice_test.prefix_required')]);
        }

        $data = [
            'designation' => 'synthetic_test',
            'test_account' => [
                'credential_reference' => 'szamlazz_agent',
                'owner_attested_test_mode' => true,
                'owner_attested_at' => now()->toIso8601String(),
                'owner_attestor_id' => $actor->id,
                'test_seller_profile_confirmed' => true,
                'seller_profile_label' => 'Owner-confirmed synthetic seller profile in the TEST account',
            ],
            'seller' => [
                'name' => 'WP CareGrid Synthetic TEST Seller', 'country' => 'HU', 'postal_code' => '1111',
                'city' => 'Budapest', 'address' => 'Synthetic Test Street 1',
            ],
            'buyer' => [
                'name' => 'WP CareGrid Synthetic TEST Buyer', 'country' => 'HU', 'postal_code' => '1111',
                'city' => 'Budapest', 'address' => 'Synthetic Test Street 1',
            ],
            'line' => ['net' => 1000, 'tax' => 0, 'gross' => 1000, 'tax_code' => $validated['tax_code']],
            'currency' => 'HUF',
            'amount' => 1000,
            'notify_customer' => false,
            'description' => 'WP CareGrid synthetic Szamlazz integration test',
            'tax_decision_kind' => 'synthetic_provider_code_selection_not_a_tax_decision',
            'billing_tax_configuration' => [
                'version_id' => $configurationVersion?->id,
                'version' => $configurationVersion?->version,
                'invoice_prefix' => $invoicePrefix,
            ],
        ];
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $externalReference = 'wpcg-synthetic-test-'.random_int(1_000_000_000, 9_999_999_999);

        try {
            return DB::transaction(function () use ($data, $hash, $actor, $externalReference): SzamlazzSyntheticTestRun {
                $run = SzamlazzSyntheticTestRun::query()->create([
                    'execution_slot' => 'szamlazz-phase6-one-shot',
                    'designation' => 'synthetic_test',
                    'status' => 'prepared',
                    'prepared_by' => $actor->id,
                    'tax_code' => $data['line']['tax_code'],
                    'currency' => 'HUF',
                    'amount' => 1000,
                    'test_account_attested' => true,
                    'test_seller_attested' => true,
                    'credential_reference' => 'szamlazz_agent',
                    'external_reference' => $externalReference,
                    'snapshot_sha256' => $hash,
                    'commercial_snapshot' => $data,
                ]);
                AdminAudit::record($actor, 'billing_tax.synthetic_test_prepared', 'synthetic_invoice_test', (string) $run->id, [
                    'designation' => 'synthetic_test', 'currency' => 'HUF', 'amount' => 1000,
                    'credential_reference' => 'szamlazz_agent', 'snapshot_sha256' => $hash,
                ]);

                return $run;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['test' => __('owner.synthetic_invoice_test.single_execution_used')]);
        }
    }

    public function submit(SzamlazzSyntheticTestRun $run, User $actor, string $requestHost, array $input): SzamlazzSyntheticTestRun
    {
        abort_unless($this->guard->permits($requestHost), 404);
        if (array_diff(array_keys($input), ['confirm_submission', 'confirmation_phrase']) !== []) {
            throw ValidationException::withMessages(['test' => __('owner.synthetic_invoice_test.invalid_input')]);
        }
        Validator::make($input, [
            'confirm_submission' => ['required', 'accepted'],
            'confirmation_phrase' => ['required', 'in:SUBMIT ONE TEST INVOICE'],
        ])->validate();

        [$run, $startedNow] = DB::transaction(function () use ($run, $actor): array {
            $locked = SzamlazzSyntheticTestRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->status !== 'prepared') {
                return [$locked, false];
            }
            if ($locked->designation !== 'synthetic_test' || $locked->status !== 'prepared'
                || ! $locked->test_account_attested || ! $locked->test_seller_attested
                || $locked->credential_reference !== 'szamlazz_agent'
                || ! hash_equals($locked->snapshot_sha256, $this->snapshotHash($locked->commercial_snapshot ?? []))) {
                throw ValidationException::withMessages(['test' => __('owner.synthetic_invoice_test.prepared_record_invalid')]);
            }
            $locked->status = 'submission_started';
            $locked->submitted_by = $actor->id;
            $locked->submitted_at = now();
            $locked->save();
            AdminAudit::record($actor, 'billing_tax.synthetic_test_submission_started', 'synthetic_invoice_test', (string) $locked->id, [
                'designation' => 'synthetic_test', 'external_reference' => $locked->external_reference,
                'snapshot_sha256' => $locked->snapshot_sha256,
            ]);

            return [$locked, true];
        });

        if (! $startedNow) {
            return $run;
        }

        $result = $this->provider->submitSyntheticTest($this->request($run));

        return $this->recordResult($run, $actor, $result);
    }

    public function reconcile(SzamlazzSyntheticTestRun $run, User $actor, string $requestHost): SzamlazzSyntheticTestRun
    {
        abort_unless($this->guard->permits($requestHost), 404);
        if (! in_array($run->status, ['submission_started', 'reconciliation_required'], true)) {
            return $run;
        }
        $result = $this->provider->lookupSyntheticTestByExternalReference($run->external_reference);
        if ($result->outcome !== 'found' || ! $result->invoiceNumber) {
            AdminAudit::record($actor, 'billing_tax.synthetic_test_reconciled', 'synthetic_invoice_test', (string) $run->id, ['result' => 'unresolved']);

            return $run;
        }

        return DB::transaction(function () use ($run, $actor, $result): SzamlazzSyntheticTestRun {
            $locked = SzamlazzSyntheticTestRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->status === 'issued') {
                return $locked;
            }
            $locked->status = 'issued';
            $locked->provider_invoice_number = $result->invoiceNumber;
            $locked->provider_metadata = $result->metadata;
            $locked->error_category = null;
            $locked->resolved_at = now();
            $locked->save();
            AdminAudit::record($actor, 'billing_tax.synthetic_test_reconciled', 'synthetic_invoice_test', (string) $locked->id, [
                'result' => 'found', 'provider_invoice_number' => $result->invoiceNumber,
            ]);

            return $locked;
        });
    }

    public function request(SzamlazzSyntheticTestRun $run): NormalizedInvoiceRequest
    {
        $snapshot = $run->commercial_snapshot ?? [];
        $line = $snapshot['line'] ?? [];

        return new NormalizedInvoiceRequest(
            $run->external_reference,
            'HUF',
            1000,
            now()->toDateString(),
            now()->toDateString(),
            now()->toDateString(),
            null,
            'bankkártya',
            $snapshot['seller'],
            $snapshot['buyer'],
            'WP CareGrid synthetic Szamlazz integration test',
            new SyntheticTestTaxSelection('synthetic-provider-code-selection', 1, $line, 'hu'),
            ['invoice_language' => 'hu', 'notify_customer' => false, 'fulfillment_date_policy' => 'invoice_date',
                'invoice_prefix' => $snapshot['billing_tax_configuration']['invoice_prefix'] ?? null],
        );
    }

    private function recordResult(SzamlazzSyntheticTestRun $run, User $actor, ProviderSubmissionResult $result): SzamlazzSyntheticTestRun
    {
        return DB::transaction(function () use ($run, $actor, $result): SzamlazzSyntheticTestRun {
            $locked = SzamlazzSyntheticTestRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->status !== 'submission_started') {
                return $locked;
            }
            $locked->status = match ($result->outcome) {
                'issued' => 'issued', 'rejected' => 'rejected', default => 'reconciliation_required',
            };
            $locked->error_category = $result->outcome === 'issued' ? null : ($result->errorCategory ?? 'provider_response_ambiguous');
            $locked->provider_metadata = $result->metadata;
            $locked->provider_invoice_number = $result->outcome === 'issued' ? $result->invoiceNumber : null;
            $locked->resolved_at = $result->outcome === 'issued' || $result->outcome === 'rejected' ? now() : null;
            if ($result->outcome === 'issued' && $result->pdfContent !== null) {
                $this->storePdf($locked, $result->pdfContent);
            }
            $locked->save();
            $event = match ($result->outcome) {
                'issued' => 'billing_tax.synthetic_test_issued',
                'rejected' => 'billing_tax.synthetic_test_rejected',
                default => 'billing_tax.synthetic_test_ambiguous',
            };
            AdminAudit::record($actor, $event, 'synthetic_invoice_test', (string) $locked->id, [
                'status' => $locked->status,
                'provider_invoice_number' => $locked->provider_invoice_number,
                'error_category' => $locked->error_category,
                'pdf_sha256' => $locked->pdf_sha256,
                'pdf_bytes' => $locked->pdf_bytes,
            ]);

            return $locked;
        });
    }

    private function storePdf(SzamlazzSyntheticTestRun $run, string $content): void
    {
        if (! str_starts_with($content, '%PDF-') || strlen($content) > 10_485_760) {
            return;
        }
        $disk = (string) config('szamlazz.synthetic_test.private_disk', 'local');
        $path = trim((string) config('szamlazz.synthetic_test.private_directory', 'private/szamlazz-synthetic-tests'), '/').'/'.$run->id.'.pdf';
        $root = realpath((string) config("filesystems.disks.{$disk}.root"));
        $public = realpath(public_path());
        $publicPrefix = $public ? rtrim($public, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR : null;
        if (! $root || ! $public || $root === $public || str_starts_with($root, (string) $publicPrefix)) {
            return;
        }
        try {
            if (Storage::disk($disk)->put($path, $content)) {
                $run->pdf_disk = $disk;
                $run->pdf_path = $path;
                $run->pdf_sha256 = hash('sha256', $content);
                $run->pdf_bytes = strlen($content);
            }
        } catch (\Throwable) {
            // The provider invoice remains issued; PDF storage failure is reported as absent evidence.
        }
    }

    private function snapshotHash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
