<?php

namespace Tests\Feature;

use App\Invoicing\NormalizedInvoiceRequest;
use App\Invoicing\Providers\ProviderSubmissionResult;
use App\Invoicing\Providers\SyntheticInvoiceTestProvider;
use App\Invoicing\SyntheticTestTaxSelection;
use App\Invoicing\SyntheticSzamlazzTestGuard;
use App\Invoicing\SyntheticSzamlazzTestWorkflow;
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
        $provider = $this->mock(SyntheticInvoiceTestProvider::class);
        $provider->shouldReceive('submitSyntheticTest')->once()->withArgs(fn ($request) => $request instanceof NormalizedInvoiceRequest
            && $request->taxDecision instanceof SyntheticTestTaxSelection
            && $request->currency === 'HUF'
            && $request->amountMinor === 1000
            && $request->billingSettings['notify_customer'] === false
        )->andReturn(new ProviderSubmissionResult('issued', 'TEST-2026-1', metadata: ['provider_invoice_id' => 'TEST-2026-1'], pdfContent: "%PDF-1.4\nsynthetic"));
        $provider->shouldNotReceive('lookupSyntheticTestByExternalReference');

        $workflow = app(SyntheticSzamlazzTestWorkflow::class);
        $run = $workflow->prepare($this->prepareInput(), $owner, 'staging.wpcaregrid.com');
        $this->assertSame('synthetic_test', $run->designation);
        $this->assertSame('prepared', $run->status);
        $this->assertNull($run->commercial_snapshot['buyer']['email'] ?? null);
        $this->assertSame('synthetic_provider_code_selection_not_a_tax_decision', $run->commercial_snapshot['tax_decision_kind']);
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

    public function test_execution_slot_is_permanently_single_use(): void
    {
        $this->enableStagingTestMode();
        $owner = User::factory()->owner()->create();
        $workflow = app(SyntheticSzamlazzTestWorkflow::class);
        $workflow->prepare($this->prepareInput(), $owner, 'staging.wpcaregrid.com');
        try {
            $workflow->prepare($this->prepareInput(), $owner, 'staging.wpcaregrid.com');
            $this->fail('The one-shot unique slot should reject a second run.');
        } catch (ValidationException) {
            $this->assertSame(1, SzamlazzSyntheticTestRun::query()->count());
        }

        $this->assertSame(0, InvoiceIntent::query()->count());
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
            ->assertSee('A separate, owner-attested provider test', false);
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
}
