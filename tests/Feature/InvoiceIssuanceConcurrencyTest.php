<?php

namespace Tests\Feature;

use App\Billing\SzamlazzBillingSettings;
use App\Invoicing\ApprovedTaxDecision;
use App\Invoicing\InvoiceIntentStatus;
use App\Invoicing\InvoiceIssuanceWorkflow;
use App\Invoicing\NormalizedInvoiceRequest;
use App\Invoicing\Providers\InvoiceSubmissionProvider;
use App\Invoicing\Providers\ProviderLookupResult;
use App\Invoicing\Providers\ProviderSubmissionResult;
use App\Models\Customer;
use App\Models\CustomerBillingIdentity;
use App\Models\InvoiceIntent;
use App\Models\InvoiceIssuanceAttempt;
use App\Models\User;
use App\Tax\SellerTaxProfileRegistry;
use App\Tax\SellerTaxProfileStatus;
use App\Tax\VerificationStatus;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('billing-concurrency')]
final class InvoiceIssuanceConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_independent_mysql_processes_only_submit_one_invoice_for_an_intent(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl for independent-process contention.');
        }

        config([
            'szamlazz.provider_enabled' => true, 'szamlazz.dry_run' => false, 'szamlazz.issuance_enabled' => true,
            'szamlazz.permitted_environments' => ['testing'], 'szamlazz.agent_api_key' => 'synthetic-test-key',
        ]);
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcg-issue-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        config(['szamlazz.test_provider_path' => $directory]);
        $this->app->bind(InvoiceSubmissionProvider::class, ProcessInvoiceSubmissionProvider::class);
        $intent = $this->readyIntent();
        $request = $this->request();
        $pids = [];

        try {
            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->fail('Unable to fork invoice issuance contender.');
                }
                if ($pid === 0) {
                    DB::purge(DB::getDefaultConnection());
                    DB::connection()->getPdo();
                    file_put_contents($directory.DIRECTORY_SEPARATOR.'ready-'.$index, 'ready', LOCK_EX);
                    $deadline = microtime(true) + 20;
                    while (! is_file($directory.DIRECTORY_SEPARATOR.'release')) {
                        if (microtime(true) >= $deadline) {
                            exit(2);
                        }
                        usleep(10000);
                    }
                    try {
                        $result = app(InvoiceIssuanceWorkflow::class)->submit($intent->id, $request);
                        file_put_contents($directory.DIRECTORY_SEPARATOR.'result-'.$index, is_object($result) ? (string) $result->id : $result, LOCK_EX);
                        exit(is_object($result) ? 0 : 3);
                    } catch (\Throwable $exception) {
                        file_put_contents($directory.DIRECTORY_SEPARATOR.'result-'.$index, get_class($exception), LOCK_EX);
                        exit(1);
                    }
                }
                $pids[] = $pid;
            }

            $deadline = microtime(true) + 20;
            while (! is_file($directory.DIRECTORY_SEPARATOR.'ready-0') || ! is_file($directory.DIRECTORY_SEPARATOR.'ready-1')) {
                if (microtime(true) >= $deadline) {
                    $this->fail('Invoice issuance contenders did not reach the start barrier.');
                }
                usleep(10000);
            }
            file_put_contents($directory.DIRECTORY_SEPARATOR.'release', 'go', LOCK_EX);
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            DB::purge(DB::getDefaultConnection());
            $providerCalls = glob($directory.DIRECTORY_SEPARATOR.'provider-call-*') ?: [];
            $this->assertCount(1, $providerCalls);
            $this->assertSame(1, InvoiceIssuanceAttempt::query()->where('invoice_intent_id', $intent->id)->count());
            $this->assertSame('issued', InvoiceIssuanceAttempt::query()->where('invoice_intent_id', $intent->id)->value('status'));
            $this->assertSame(InvoiceIntentStatus::Issued, $intent->fresh()->status);
            $this->assertSame(file_get_contents($directory.DIRECTORY_SEPARATOR.'result-0'), file_get_contents($directory.DIRECTORY_SEPARATOR.'result-1'));
        } finally {
            foreach ($pids as $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                    posix_kill($pid, SIGTERM);
                    pcntl_waitpid($pid, $status);
                }
            }
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($directory);
        }
    }

    private function readyIntent(): InvoiceIntent
    {
        $customer = Customer::factory()->create();
        $identity = new CustomerBillingIdentity;
        $identity->forceFill([
            'customer_id' => $customer->id, 'version' => 1, 'source' => 'profile', 'customer_type' => 'company',
            'legal_name' => 'Synthetic Buyer Ltd', 'country_code' => 'DE', 'verification_status' => VerificationStatus::Verified,
        ])->save();
        $profile = app(SellerTaxProfileRegistry::class)->publish([
            'effective_from' => now()->toDateString(), 'legal_name' => 'Synthetic Seller', 'establishment_country' => 'HU',
            'verification_status' => SellerTaxProfileStatus::Verified, 'evidence_reference' => 'synthetic-evidence-only',
        ]);
        $version = app(SzamlazzBillingSettings::class)->publish([
            'seller_profile_id' => $profile->id, 'invoice_language' => 'en', 'default_payment_method' => 'bank_transfer',
            'payment_deadline_policy' => 'same_day', 'fulfillment_date_policy' => 'payment_date', 'invoice_prefix' => 'WPCG',
            'notify_customer' => false, 'currency_policy' => 'block_foreign_without_fx', 'tax_configuration_mode' => 'manual_review',
            'default_tax_code' => null, 'effective_at' => now()->toDateString(),
        ], User::factory()->owner()->create());

        return InvoiceIntent::query()->create([
            'customer_id' => $customer->id, 'billing_identity_id' => $identity->id, 'seller_tax_profile_id' => $profile->id,
            'billing_tax_configuration_version_id' => $version->id,
            'stripe_invoice_id' => 'in_issue_race_'.Str::random(14), 'provider' => 'szamlazz_hu', 'purpose' => 'subscription_invoice',
            'status' => InvoiceIntentStatus::DryRunValidated, 'amount' => 80100, 'currency' => 'EUR',
            'idempotency_key' => 'issue_race_'.Str::random(20), 'dry_run_validated_at' => now(),
            'tax_decision_reference' => 'approved-concurrency-fixture', 'tax_decision_version' => 1,
            'commercial_snapshot' => [
                'billing_identity' => ['id' => $identity->id],
                'approved_tax_decision' => ['reference' => 'approved-concurrency-fixture', 'version' => 1, 'approval_authority' => 'accountant', 'approval_reference' => 'synthetic-approval', 'tax_code' => 'AAM'],
            ],
        ]);
    }

    private function request(): NormalizedInvoiceRequest
    {
        return new NormalizedInvoiceRequest(
            'caller-value-ignored', 'EUR', 80100, now()->toDateString(), now()->toDateString(), now()->toDateString(), now()->toDateString(), 'ignored',
            ['name' => 'Synthetic Seller', 'country' => 'HU', 'postal_code' => '1000', 'city' => 'Budapest', 'address' => 'Test street'],
            ['name' => 'Synthetic Buyer', 'country' => 'DE', 'postal_code' => '10115', 'city' => 'Berlin', 'address' => 'Test street'],
            'Synthetic subscription line',
            new ApprovedTaxDecision('approved-concurrency-fixture', 1, ['net' => 80100, 'tax' => 0, 'gross' => 80100, 'tax_code' => 'AAM'], 'en', '1.00000000', 'Synthetic FX basis'),
        );
    }
}

final class ProcessInvoiceSubmissionProvider implements InvoiceSubmissionProvider
{
    public function submit(NormalizedInvoiceRequest $request): ProviderSubmissionResult
    {
        $path = (string) config('szamlazz.test_provider_path');
        file_put_contents($path.DIRECTORY_SEPARATOR.'provider-call-'.getmypid(), 'called', LOCK_EX);
        usleep(250000);

        return new ProviderSubmissionResult('issued', 'WPCG-CONCURRENT-1', metadata: ['provider_invoice_id' => 'WPCG-CONCURRENT-1']);
    }

    public function lookupByExternalReference(string $externalReference): ProviderLookupResult
    {
        return new ProviderLookupResult('unresolved');
    }
}
