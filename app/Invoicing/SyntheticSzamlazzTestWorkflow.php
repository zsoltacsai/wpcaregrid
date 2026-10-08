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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Owner-attested synthetic provider exercise, separate from tax-approved invoicing. */
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
        if ($invoicePrefix !== SyntheticSzamlazzTestContract::INVOICE_PREFIX) {
            throw ValidationException::withMessages(['test' => __('owner.synthetic_invoice_test.prefix_required')]);
        }

        $line = SyntheticSzamlazzTestContract::line();
        $amount = SyntheticSzamlazzTestContract::grossMinorUnits();

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
            'seller' => SyntheticSzamlazzTestContract::seller(),
            'buyer' => SyntheticSzamlazzTestContract::buyer(),
            'line' => $line,
            'tax_code' => SyntheticSzamlazzTestContract::TAX_CODE,
            'currency' => SyntheticSzamlazzTestContract::CURRENCY,
            'amount' => $amount,
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
            return DB::transaction(function () use ($data, $hash, $actor, $externalReference, $amount): SzamlazzSyntheticTestRun {
                // Serialize new runs so an unresolved/ambiguous provider outcome cannot
                // race with authorization for another invoice against the same fixture.
                if (! DB::table('szamlazz_synthetic_test_controls')->where('id', 1)->lockForUpdate()->first()) {
                    throw new LogicException('Synthetic test control row is missing.');
                }
                $unresolvedRun = SzamlazzSyntheticTestRun::query()
                    ->whereIn('status', ['prepared', 'submission_started', 'reconciliation_required'])
                    ->exists();
                if ($unresolvedRun) {
                    throw ValidationException::withMessages(['test' => __('owner.synthetic_invoice_test.unresolved_run_exists')]);
                }

                $run = SzamlazzSyntheticTestRun::query()->create([
                    'execution_slot' => 'szamlazz-phase6-run-'.Str::uuid(),
                    'designation' => 'synthetic_test',
                    'status' => 'prepared',
                    'prepared_by' => $actor->id,
                    'tax_code' => SyntheticSzamlazzTestContract::TAX_CODE,
                    'currency' => SyntheticSzamlazzTestContract::CURRENCY,
                    'amount' => $amount,
                    'test_account_attested' => true,
                    'test_seller_attested' => true,
                    'credential_reference' => 'szamlazz_agent',
                    'external_reference' => $externalReference,
                    'snapshot_sha256' => $hash,
                    'commercial_snapshot' => $data,
                ]);
                AdminAudit::record($actor, 'billing_tax.synthetic_test_prepared', 'synthetic_invoice_test', (string) $run->id, [
                    'designation' => 'synthetic_test', 'currency' => SyntheticSzamlazzTestContract::CURRENCY, 'amount' => $amount,
                    'credential_reference' => 'szamlazz_agent', 'snapshot_sha256' => $hash,
                ]);

                return $run;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['test' => __('owner.synthetic_invoice_test.duplicate_run_identity')]);
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

        $preflightRun = SzamlazzSyntheticTestRun::query()->findOrFail($run->id);
        if ($preflightRun->status !== 'prepared') {
            return $preflightRun;
        }
        $preflightErrors = app(SyntheticInvoiceSemanticValidator::class)->validateRun($preflightRun);
        if ($preflightErrors !== []) {
            throw ValidationException::withMessages(['test' => __('owner.synthetic_invoice_test.invalid_snapshot')]);
        }
        $request = $this->request($preflightRun);
        if (! $this->provider->validateSyntheticTest($request)) {
            throw ValidationException::withMessages(['test' => __('owner.synthetic_invoice_test.invalid_snapshot')]);
        }

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
            if (app(SyntheticInvoiceSemanticValidator::class)->validateRun($locked) !== []) {
                throw ValidationException::withMessages(['test' => __('owner.synthetic_invoice_test.invalid_snapshot')]);
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

    public function cancel(SzamlazzSyntheticTestRun $run, User $actor, string $requestHost, array $input): SzamlazzSyntheticTestRun
    {
        abort_unless($actor->isOwner() && $this->guard->permits($requestHost), 404);
        if (array_diff(array_keys($input), ['reason_code', 'confirmation_phrase']) !== []) {
            throw ValidationException::withMessages(['test' => __('owner.synthetic_invoice_test.invalid_input')]);
        }
        Validator::make($input, [
            'reason_code' => ['required', 'in:'.SyntheticSzamlazzTestContract::CANCELLATION_REASON_INVALID_AMOUNT],
            'confirmation_phrase' => ['required', 'in:CANCEL PREPARED TEST RUN'],
        ])->validate();

        return DB::transaction(function () use ($run, $actor): SzamlazzSyntheticTestRun {
            $locked = SzamlazzSyntheticTestRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->status === 'cancelled'
                && $locked->cancellation_reason === SyntheticSzamlazzTestContract::CANCELLATION_REASON_INVALID_AMOUNT) {
                return $locked;
            }
            if ($locked->status !== 'prepared') {
                throw ValidationException::withMessages(['test' => __('owner.synthetic_invoice_test.cancel_not_allowed')]);
            }

            $errors = app(SyntheticInvoiceSemanticValidator::class)->validateRun($locked);
            if (! in_array('incorrect_amount_snapshot', $errors, true)
                || array_diff($errors, ['incorrect_amount_snapshot']) !== []) {
                throw ValidationException::withMessages(['test' => __('owner.synthetic_invoice_test.cancel_not_allowed')]);
            }

            $locked->status = 'cancelled';
            $locked->cancelled_by = $actor->id;
            $locked->cancellation_reason = SyntheticSzamlazzTestContract::CANCELLATION_REASON_INVALID_AMOUNT;
            $locked->cancelled_at = now();
            $locked->error_category = SyntheticSzamlazzTestContract::CANCELLATION_REASON_INVALID_AMOUNT;
            $locked->resolved_at = now();
            $locked->save();

            AdminAudit::record($actor, 'billing_tax.synthetic_test_cancelled', 'synthetic_invoice_test', (string) $locked->id, [
                'reason_code' => SyntheticSzamlazzTestContract::CANCELLATION_REASON_INVALID_AMOUNT,
                'snapshot_sha256' => $locked->snapshot_sha256,
            ]);

            return $locked;
        });
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
            $snapshot['currency'] ?? '',
            is_int($snapshot['amount'] ?? null) ? $snapshot['amount'] : -1,
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
            $snapshot,
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
