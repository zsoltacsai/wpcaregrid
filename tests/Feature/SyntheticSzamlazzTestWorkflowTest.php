<?php

namespace Tests\Feature;

use App\Billing\SzamlazzBillingSettings;
use App\Invoicing\NormalizedInvoiceRequest;
use App\Invoicing\Providers\ProviderSubmissionResult;
use App\Invoicing\Providers\SyntheticInvoiceTestProvider;
use App\Invoicing\SyntheticSzamlazzTestGuard;
use App\Invoicing\SyntheticSzamlazzTestContract;
use App\Invoicing\SyntheticSzamlazzTestWorkflow;
use App\Invoicing\SyntheticTestTaxSelection;
use App\Models\BillingTaxConfigurationVersion;
use App\Models\InvoiceIntent;
use App\Models\SzamlazzSyntheticTestRun;
use App\Models\User;
use App\Owner\IntegrationCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

final class SyntheticSzamlazzTestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->app->detectEnvironment(fn () => 'testing');
        parent::tearDown();
    }

    public function test_one_synthetic_run_is_attested_immutable_and_separate_from_normal_tax_approval(): void
    {
        $this->enableStagingTestMode();
        Storage::fake('local');
        $owner = User::factory()->owner()->create();
        $this->publishPrefix($owner, 'A');
        $provider = $this->mock(SyntheticInvoiceTestProvider::class);
        $provider->shouldReceive('validateSyntheticTest')->once()->andReturnTrue();
        $provider->shouldReceive('submitSyntheticTest')->once()->withArgs(fn ($request) => $request instanceof NormalizedInvoiceRequest
            && $request->taxDecision instanceof SyntheticTestTaxSelection
            && $request->currency === 'HUF'
            && $request->amountMinor === 100000
            && $request->billingSettings['invoice_prefix'] === 'A'
            && $request->billingSettings['notify_customer'] === false
        )->andReturn(new ProviderSubmissionResult('issued', 'TEST-2026-1', metadata: ['provider_invoice_id' => 'TEST-2026-1'], pdfContent: "%PDF-1.4\nsynthetic"));
        $provider->shouldNotReceive('lookupSyntheticTestByExternalReference');

        $workflow = app(SyntheticSzamlazzTestWorkflow::class);
        $run = $workflow->prepare($this->prepareInput(), $owner, 'staging.wpcaregrid.com');
        $this->assertSame('synthetic_test', $run->designation);
        $this->assertSame('prepared', $run->status);
        $this->assertSame(100000, $run->amount);
        $this->assertSame("1,000\u{00A0}Ft", $run->formattedAmount());
        $this->assertNull($run->commercial_snapshot['buyer']['email'] ?? null);
        $this->assertSame('synthetic_provider_code_selection_not_a_tax_decision', $run->commercial_snapshot['tax_decision_kind']);
        $this->assertSame('A', $run->commercial_snapshot['billing_tax_configuration']['invoice_prefix']);
        $this->assertSame(0, InvoiceIntent::query()->count());

        $issued = $workflow->submit($run, $owner, 'staging.wpcaregrid.com', [
            'confirm_submission' => '1', 'confirmation_phrase' => 'SUBMIT ONE TEST INVOICE',
        ]);
        $this->assertSame('issued', $issued->status);
        $this->assertSame('TEST-2026-1', $issued->provider_invoice_number);
        $this->assertSame(hash('sha256', "%PDF-1.4\nsynthetic"), $issued->pdf_sha256);
        Storage::disk('local')->assertExists($issued->pdf_path);
        $before = $issued->commercial_snapshot;
        $again = $workflow->submit($issued, $owner, 'staging.wpcaregrid.com', [
            'confirm_submission' => '1', 'confirmation_phrase' => 'SUBMIT ONE TEST INVOICE',
        ]);
        $this->assertSame($issued->id, $again->id);
        $this->assertSame($before, $again->commercial_snapshot);
        $this->assertSame(1, SzamlazzSyntheticTestRun::query()->count());
        $this->assertSame(1, DB::table('admin_audit_events')->where('event', 'billing_tax.synthetic_test_issued')->count());
        $this->assertSame(0, DB::table('invoice_intents')->count());
    }

    public function test_synthetic_test_selection_cannot_be_promoted_by_normal_billing_settings(): void
    {
        $request = new NormalizedInvoiceRequest(
            'wpcg-synthetic-test-1234567890', 'HUF', 1000, '2026-10-08', '2026-10-08', '2026-10-08',
            null, 'bankkártya', ['name' => 'Synthetic seller'], ['name' => 'Synthetic buyer'],
            'Synthetic test', new SyntheticTestTaxSelection('synthetic-only', 1,
                ['net' => 1000, 'tax' => 0, 'gross' => 1000, 'tax_code' => 'AAM']),
        );

        $this->expectException(LogicException::class);
        $request->withBillingSettings(['invoice_language' => 'hu']);
    }

    public function test_test_path_fails_closed_for_live_host_missing_flag_missing_owner_key_or_missing_attestation(): void
    {
        $owner = User::factory()->owner()->create();
        $this->enableStagingTestMode();
        $guard = app(SyntheticSzamlazzTestGuard::class);
        $this->assertTrue($guard->permits('staging.wpcaregrid.com'));
        $this->assertFalse($guard->permits('wpcaregrid.com'));

        config(['szamlazz.issuance_enabled' => true]);
        $this->assertFalse($guard->permits('staging.wpcaregrid.com'));
        config(['szamlazz.issuance_enabled' => false]);

        config(['szamlazz.synthetic_test.enabled' => false]);
        $this->assertFalse($guard->permits('staging.wpcaregrid.com'));
        config(['szamlazz.synthetic_test.enabled' => true]);
        DB::table('integration_credentials')->where('id', 'szamlazz_agent')->delete();
        $this->assertFalse($guard->permits('staging.wpcaregrid.com'));

        $this->enableStagingTestMode();
        $this->expectException(ValidationException::class);
        app(SyntheticSzamlazzTestWorkflow::class)->prepare([
            'account_test_mode_confirmed' => '1', 'test_seller_confirmed' => '1', 'tax_code' => 'AAM',
            'confirmation_phrase' => 'WRONG',
        ], $owner, 'staging.wpcaregrid.com');
    }

    public function test_rejected_run_is_preserved_and_a_fresh_owner_can_prepare_a_new_unique_run(): void
    {
        $this->enableStagingTestMode();
        $owner = User::factory()->owner()->create();
        $nextOwner = User::factory()->owner()->create();
        $this->publishPrefix($owner, 'A');
        $provider = $this->mock(SyntheticInvoiceTestProvider::class);
        $provider->shouldReceive('validateSyntheticTest')->once()->andReturnTrue();
        $provider->shouldReceive('submitSyntheticTest')->once()->andReturn(new ProviderSubmissionResult('rejected', errorCategory: 'provider_rejected'));
        $provider->shouldNotReceive('lookupSyntheticTestByExternalReference');
        $workflow = app(SyntheticSzamlazzTestWorkflow::class);

        $firstRun = $workflow->prepare($this->prepareInput(), $owner, 'staging.wpcaregrid.com');
        $legacySlot = 'szamlazz-phase6-one-shot';
        DB::table('szamlazz_synthetic_test_runs')->where('id', $firstRun->id)->update(['execution_slot' => $legacySlot]);
        $firstRun->refresh();
        $firstReference = $firstRun->external_reference;
        $firstHash = $firstRun->snapshot_sha256;

        try {
            $workflow->prepare($this->prepareInput(), $owner, 'staging.wpcaregrid.com');
            $this->fail('An unresolved prepared run must block another run.');
        } catch (ValidationException) {
            $this->assertSame(1, SzamlazzSyntheticTestRun::query()->count());
        }

        $rejected = $workflow->submit($firstRun, $owner, 'staging.wpcaregrid.com', [
            'confirm_submission' => '1', 'confirmation_phrase' => 'SUBMIT ONE TEST INVOICE',
        ]);
        $this->assertSame('rejected', $rejected->status);

        $secondRun = $workflow->prepare($this->prepareInput(), $nextOwner, 'staging.wpcaregrid.com');
        $this->assertSame(2, SzamlazzSyntheticTestRun::query()->count());
        $this->assertSame($legacySlot, DB::table('szamlazz_synthetic_test_runs')->where('id', $firstRun->id)->value('execution_slot'));
        $this->assertSame($firstReference, $firstRun->fresh()->external_reference);
        $this->assertSame($firstHash, $firstRun->fresh()->snapshot_sha256);
        $this->assertNotSame($firstRun->execution_slot, $secondRun->execution_slot);
        $this->assertNotSame($firstRun->external_reference, $secondRun->external_reference);
        $this->assertSame($nextOwner->id, $secondRun->prepared_by);
        $this->assertSame(2, DB::table('admin_audit_events')->where('event', 'billing_tax.synthetic_test_prepared')->count());
        $this->assertSame(0, InvoiceIntent::query()->count());
    }

    public function test_rejected_and_ambiguous_runs_are_never_retried(): void
    {
        $this->enableStagingTestMode();
        $owner = User::factory()->owner()->create();
        $this->publishPrefix($owner, 'A');
        $provider = $this->mock(SyntheticInvoiceTestProvider::class);
        $provider->shouldReceive('validateSyntheticTest')->twice()->andReturnTrue();
        $provider->shouldReceive('submitSyntheticTest')->twice()->andReturn(
            new ProviderSubmissionResult('rejected', errorCategory: 'provider_rejected'),
            new ProviderSubmissionResult('ambiguous', errorCategory: 'provider_response_ambiguous'),
        );
        $provider->shouldNotReceive('lookupSyntheticTestByExternalReference');
        $workflow = app(SyntheticSzamlazzTestWorkflow::class);
        $submission = ['confirm_submission' => '1', 'confirmation_phrase' => 'SUBMIT ONE TEST INVOICE'];

        $rejected = $workflow->submit($workflow->prepare($this->prepareInput(), $owner, 'staging.wpcaregrid.com'), $owner, 'staging.wpcaregrid.com', $submission);
        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('rejected', $workflow->submit($rejected, $owner, 'staging.wpcaregrid.com', $submission)->status);

        $ambiguous = $workflow->submit($workflow->prepare($this->prepareInput(), $owner, 'staging.wpcaregrid.com'), $owner, 'staging.wpcaregrid.com', $submission);
        $this->assertSame('reconciliation_required', $ambiguous->status);
        $this->assertSame('reconciliation_required', $workflow->submit($ambiguous, $owner, 'staging.wpcaregrid.com', $submission)->status);
        $this->assertSame(2, SzamlazzSyntheticTestRun::query()->count());

        try {
            $workflow->prepare($this->prepareInput(), $owner, 'staging.wpcaregrid.com');
            $this->fail('A run with ambiguous provider outcome must block a new authorization.');
        } catch (ValidationException) {
            $this->assertSame(2, SzamlazzSyntheticTestRun::query()->count());
        }
    }

    public function test_synthetic_request_freezes_account_prefix_and_requires_one_before_preparation(): void
    {
        $this->enableStagingTestMode();
        $owner = User::factory()->owner()->create();
        $workflow = app(SyntheticSzamlazzTestWorkflow::class);

        try {
            $workflow->prepare($this->prepareInput(), $owner, 'staging.wpcaregrid.com');
            $this->fail('Synthetic preparation must require an explicit account prefix.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('test', $exception->errors());
        }

        $this->publishPrefix($owner, 'A');
        $run = $workflow->prepare($this->prepareInput(), $owner, 'staging.wpcaregrid.com');
        $this->publishPrefix($owner, 'B');

        $this->assertSame('A', $workflow->request($run)->billingSettings['invoice_prefix']);
        $this->assertSame('A', $run->commercial_snapshot['billing_tax_configuration']['invoice_prefix']);
        $this->assertSame('A', BillingTaxConfigurationVersion::query()->where('version', 1)->firstOrFail()->configuration['invoice_prefix']);
    }

    public function test_owner_route_requires_owner_ability_and_renders_the_confirmation_form(): void
    {
        $this->enableStagingTestMode();
        $owner = User::factory()->owner()->create();
        $this->actingAs(User::factory()->create(), 'owner')
            ->get(route('owner.billing.synthetic-szamlazz-test.index'))
            ->assertRedirect(route('owner.login'))
            ->assertSessionHasErrors('email');

        $this->actingAs($owner, 'owner')
            ->get(route('owner.billing.synthetic-szamlazz-test.index'))
            ->assertOk()
            ->assertSee('A separate, owner-attested provider test', false)
            ->assertSee(__('owner.synthetic_invoice_test.history_title'));

        $this->publishPrefix($owner, 'A');
        $run = app(SyntheticSzamlazzTestWorkflow::class)->prepare($this->prepareInput(), $owner, 'staging.wpcaregrid.com');
        $this->actingAs($owner, 'owner')
            ->get(route('owner.billing.synthetic-szamlazz-test.index'))
            ->assertOk()
            ->assertSee($run->external_reference)
            ->assertSee(__('owner.synthetic_invoice_test.execution_slot_consumed'));
    }

    public function test_legacy_incorrect_amount_is_blocked_before_submission_slot_and_can_be_cancelled_without_mutating_snapshot(): void
    {
        $this->enableStagingTestMode();
        $owner = User::factory()->owner()->create();
        $this->publishPrefix($owner, 'A');
        $provider = $this->mock(SyntheticInvoiceTestProvider::class);
        $provider->shouldNotReceive('validateSyntheticTest');
        $provider->shouldNotReceive('submitSyntheticTest');
        $version = BillingTaxConfigurationVersion::query()->latest('version')->firstOrFail();
        $snapshot = [
            'designation' => 'synthetic_test',
            'test_account' => [
                'credential_reference' => 'szamlazz_agent', 'owner_attested_test_mode' => true,
                'owner_attested_at' => now()->toIso8601String(), 'owner_attestor_id' => $owner->id,
                'test_seller_profile_confirmed' => true,
                'seller_profile_label' => 'Owner-confirmed synthetic seller profile in the TEST account',
            ],
            'seller' => SyntheticSzamlazzTestContract::seller(),
            'buyer' => SyntheticSzamlazzTestContract::buyer(),
            'line' => ['net' => 1000, 'tax' => 0, 'gross' => 1000, 'tax_code' => 'AAM'],
            'tax_code' => 'AAM', 'currency' => 'HUF', 'amount' => 1000, 'notify_customer' => false,
            'description' => 'WP CareGrid synthetic Szamlazz integration test',
            'tax_decision_kind' => 'synthetic_provider_code_selection_not_a_tax_decision',
            'billing_tax_configuration' => ['version_id' => $version->id, 'version' => $version->version, 'invoice_prefix' => 'A'],
        ];
        $run = SzamlazzSyntheticTestRun::query()->create([
            'execution_slot' => 'legacy-invalid-amount-slot', 'designation' => 'synthetic_test', 'status' => 'prepared',
            'prepared_by' => $owner->id, 'tax_code' => 'AAM', 'currency' => 'HUF', 'amount' => 1000,
            'test_account_attested' => true, 'test_seller_attested' => true, 'credential_reference' => 'szamlazz_agent',
            'external_reference' => 'wpcg-synthetic-test-1234567890',
            'snapshot_sha256' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'commercial_snapshot' => $snapshot,
        ]);
        $originalHash = $run->snapshot_sha256;
        $originalSnapshot = $run->commercial_snapshot;
        $workflow = app(SyntheticSzamlazzTestWorkflow::class);

        try {
            $workflow->submit($run, $owner, 'staging.wpcaregrid.com', [
                'confirm_submission' => '1', 'confirmation_phrase' => 'SUBMIT ONE TEST INVOICE',
            ]);
            $this->fail('Legacy incorrect amount must be blocked before submission.');
        } catch (ValidationException) {
            $this->assertSame('prepared', $run->fresh()->status);
            $this->assertNull($run->fresh()->submitted_at);
        }

        $cancelled = $workflow->cancel($run, $owner, 'staging.wpcaregrid.com', [
            'reason_code' => 'invalid_amount_snapshot', 'confirmation_phrase' => 'CANCEL PREPARED TEST RUN',
        ]);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame($originalHash, $cancelled->snapshot_sha256);
        $this->assertSame($originalSnapshot, $cancelled->commercial_snapshot);
        $this->assertSame(1, DB::table('admin_audit_events')->where('event', 'billing_tax.synthetic_test_cancelled')->count());
        $this->assertSame('cancelled', $workflow->cancel($cancelled, $owner, 'staging.wpcaregrid.com', [
            'reason_code' => 'invalid_amount_snapshot', 'confirmation_phrase' => 'CANCEL PREPARED TEST RUN',
        ])->status);
        $this->assertSame(1, DB::table('admin_audit_events')->where('event', 'billing_tax.synthetic_test_cancelled')->count());
        $this->assertSame('cancelled', $workflow->submit($cancelled, $owner, 'staging.wpcaregrid.com', [
            'confirm_submission' => '1', 'confirmation_phrase' => 'SUBMIT ONE TEST INVOICE',
        ])->status);
    }

    public function test_valid_amount_run_cannot_be_cancelled_as_invalid(): void
    {
        $this->enableStagingTestMode();
        $owner = User::factory()->owner()->create();
        $this->publishPrefix($owner, 'A');
        $run = app(SyntheticSzamlazzTestWorkflow::class)->prepare($this->prepareInput(), $owner, 'staging.wpcaregrid.com');

        $this->expectException(ValidationException::class);
        app(SyntheticSzamlazzTestWorkflow::class)->cancel($run, $owner, 'staging.wpcaregrid.com', [
            'reason_code' => 'invalid_amount_snapshot', 'confirmation_phrase' => 'CANCEL PREPARED TEST RUN',
        ]);
    }

    public function test_contract_uses_currency_minor_units_and_exact_integer_arithmetic(): void
    {
        $this->assertSame(100000, SyntheticSzamlazzTestContract::grossMinorUnits());
        $this->assertSame(['net' => 100000, 'tax' => 0, 'gross' => 100000, 'tax_code' => 'AAM'], SyntheticSzamlazzTestContract::line());
        $this->assertSame('1,000.00', SyntheticSzamlazzTestContract::formatMinorUnits(100000));
    }

    private function enableStagingTestMode(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config([
            'app.url' => 'https://staging.wpcaregrid.com',
            'szamlazz.synthetic_test.enabled' => true,
            'szamlazz.agent_api_key' => 'non-production-fixture-key',
        ]);
        app(IntegrationCredentials::class)->store('szamlazz_agent', 'non-production-fixture-key');
    }

    private function prepareInput(): array
    {
        return [
            'account_test_mode_confirmed' => '1',
            'test_seller_confirmed' => '1',
            'tax_code' => 'AAM',
            'confirmation_phrase' => 'SYNTHETIC TEST ONLY',
        ];
    }

    private function publishPrefix(User $owner, string $prefix): void
    {
        app(SzamlazzBillingSettings::class)->publish([
            'seller_profile_id' => null,
            'invoice_language' => 'hu',
            'default_payment_method' => 'bank_transfer',
            'payment_deadline_policy' => 'same_day',
            'fulfillment_date_policy' => 'invoice_date',
            'invoice_prefix' => $prefix,
            'notify_customer' => '0',
            'currency_policy' => 'block_foreign_without_fx',
            'tax_configuration_mode' => 'manual_review',
            'default_tax_code' => null,
            'effective_at' => now()->toDateString(),
        ], $owner);
    }
}
