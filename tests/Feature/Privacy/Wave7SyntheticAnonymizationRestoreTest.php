<?php

namespace Tests\Feature\Privacy;

use App\Models\Connector;
use App\Models\ConnectorPairingCode;
use App\Models\Customer;
use App\Models\PrivacyErasureExecutionAction;
use App\Models\PrivacyErasureExecutionPlan;
use App\Models\SyntheticSiteFixture;
use App\Models\User;
use App\Models\Website;
use App\Platform\DisasterRecovery\PlatformBackupCryptography;
use App\Privacy\ErasureExecutionPlanService;
use App\Privacy\ObjectStoragePrivacyTombstoneJournal;
use App\Privacy\PrivacyRequestService;
use App\Privacy\PrivacyRequestSource;
use App\Privacy\PrivacyRequestType;
use App\Privacy\PrivacyTombstoneJournal;
use App\Privacy\PrivacyTombstoneObjectStore;
use App\Privacy\PrivacyTombstoneReplayApplier;
use App\Privacy\SyntheticPrivacyAnonymizationService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class Wave7SyntheticAnonymizationRestoreTest extends TestCase
{
    public function test_synthetic_profile_anonymization_is_journaled_before_mutation_and_survives_real_t0_restore(): void
    {
        $root = sys_get_temp_dir().'/wpcg-wave7-'.bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        $sourcePath = $root.'/t0.sqlite';
        $restorePath = $root.'/restore.sqlite';
        $originalDefault = config('database.default');
        config([
            'database.connections.w7_t0' => ['driver' => 'sqlite', 'database' => $sourcePath, 'prefix' => '', 'foreign_key_constraints' => true],
            'database.connections.w7_restore' => ['driver' => 'sqlite', 'database' => $restorePath, 'prefix' => '', 'foreign_key_constraints' => true],
            'database.default' => 'w7_t0',
            'cache.default' => 'array',
            'platform.deployment_environment' => 'testing',
            'platform_backup.prefix' => 'platform-database',
            'platform_backup.encryption.current_key_id' => 'wave7-test-key',
            'platform_backup.encryption.keys.wave7-test-key' => base64_encode(random_bytes(32)),
            'platform.recovery_mode' => false,
            'privacy.destructive_retention_enabled' => false,
            'privacy.profile_anonymization_enabled' => false,
            'privacy.backup_deletion_enabled' => false,
            'privacy.provider_deletion_enabled' => false,
            'privacy.bulk_retention_execution_enabled' => false,
            'privacy.synthetic_profile_anonymization_enabled' => true,
        ]);
        DB::purge('w7_t0');
        Storage::fake('platform_backups');
        $journal = $this->journal();
        $this->app->instance(PrivacyTombstoneJournal::class, $journal);

        try {
            $exit = Artisan::call('migrate', ['--database' => 'w7_t0', '--force' => true]);
            $this->assertSame(0, $exit, Artisan::output());

            $owner = User::factory()->owner()->create(['password' => 'wave7-owner-password']);
            $originalEmail = 'synthetic-wave7-'.Str::lower(Str::random(12)).'@example.test';
            $customer = Customer::factory()->withUser()->create(['email' => $originalEmail]);
            $originalFirstName = $customer->first_name;
            $website = Website::factory()->for($customer)->create();
            $connector = Connector::query()->create([
                'website_id' => $website->id,
                'installation_id' => Str::random(32),
                'credential_hash' => hash('sha256', Str::random(64)),
                'credential_hint' => Str::random(8),
                'status' => Connector::ACTIVE,
            ]);
            $pairing = ConnectorPairingCode::query()->create([
                'website_id' => $website->id,
                'code_hash' => hash('sha256', Str::random(32)),
                'expires_at' => now()->addHour(),
            ]);
            SyntheticSiteFixture::query()->create([
                'purpose' => 'm12-wave7-'.Str::lower(Str::random(8)), 'environment' => 'testing',
                'customer_id' => $customer->id, 'website_id' => $website->id,
                'enabled' => true, 'backup_restore_entitled' => false, 'created_by_user_id' => $owner->id,
            ]);
            $case = app(PrivacyRequestService::class)->create($customer, PrivacyRequestType::Erasure, PrivacyRequestSource::OwnerConsole, 'wave7-erasure-'.Str::random(20), $owner);
            DB::table('billing_invoices')->insert([
                'stripe_invoice_id' => 'in_wave7_'.Str::lower(Str::random(12)),
                'customer_id' => $customer->id,
            ]);

            $plan = app(ErasureExecutionPlanService::class)->createPreview($case, $owner);
            $plan = app(ErasureExecutionPlanService::class)->approve(
                $plan, $owner, 'wave7-owner-password', 'APPROVE '.$plan->uuid.' '.$plan->preview_hash, $plan->preview_hash,
            );
            $action = $plan->actions()->where('action_code', 'ANONYMIZE_CURRENT_PROFILE')->firstOrFail();
            $invoiceBefore = $this->accountingFingerprint($customer->id);
            $t0Watermark = $journal->watermark();

            DB::connection('w7_t0')->statement('PRAGMA wal_checkpoint(FULL)');
            DB::purge('w7_t0');
            $this->assertTrue(copy($sourcePath, $restorePath), 'A byte-for-byte T0 SQLite snapshot must be created.');
            DB::reconnect('w7_t0');

            $result = app(SyntheticPrivacyAnonymizationService::class)->execute($plan, $action, $owner, $plan->preview_hash);
            $this->assertSame('complete', $result->state->value);
            $this->assertSame('partial', $plan->fresh()->state->value, 'Unresolved external actions keep the overall plan partial.');
            $this->assertSame($this->replacementFor($customer->uuid), $customer->fresh()->email);
            $this->assertSame('Deleted Account', $customer->fresh()->user->name);
            $this->assertSame(Connector::REVOKED, $connector->fresh()->status);
            $this->assertNotNull($pairing->fresh()->invalidated_at);
            $this->assertSame($invoiceBefore, $this->accountingFingerprint($customer->id));
            $this->assertNotNull($journal->watermark());
            $watermarkAfterFirstExecution = $journal->watermark();
            $sameAction = app(SyntheticPrivacyAnonymizationService::class)->execute($plan->fresh(), $action->fresh(), $owner, $plan->preview_hash);
            $this->assertSame('complete', $sameAction->state->value);
            $this->assertSame($watermarkAfterFirstExecution, $journal->watermark(), 'Repeating completed synthetic execution must not append a duplicate journal event.');

            config(['database.default' => 'w7_restore']);
            DB::purge('w7_restore');
            $this->assertTrue(DB::table('customers')->where('uuid', $customer->uuid)->exists(), 'The restored database must contain the actual T0 customer row.');
            $restored = Customer::query()->where('uuid', $customer->uuid)->firstOrFail();
            $this->assertSame($originalEmail, $restored->email, 'T0 must demonstrably lack the post-snapshot profile mutation.');
            $this->assertSame($originalFirstName, $restored->first_name);
            config(['platform.recovery_mode' => true, 'platform.recovery_id' => (string) Str::uuid()]);

            $replayed = app(PrivacyTombstoneReplayApplier::class)->replay(config('platform.recovery_id'), $t0Watermark);
            $this->assertSame('complete', $replayed->status);
            $this->assertSame(1, $replayed->events_applied);
            $this->assertSame($this->replacementFor($customer->uuid), $restored->fresh()->email);
            $this->assertSame('Deleted Account', $restored->fresh()->user->name);
            $this->assertSame(Connector::REVOKED, $connector->fresh()->status);
            $this->assertNotNull($pairing->fresh()->invalidated_at);
            $this->assertSame($invoiceBefore, $this->accountingFingerprint($customer->id));

            config(['platform.recovery_id' => (string) Str::uuid()]);
            $second = app(PrivacyTombstoneReplayApplier::class)->replay(config('platform.recovery_id'), $t0Watermark);
            $this->assertSame('complete', $second->status);
            $this->assertSame(1, $second->events_already_applied);
            $this->assertSame($this->replacementFor($customer->uuid), $restored->fresh()->email);

            $newUser = User::factory()->create(['email' => $originalEmail]);
            $newCustomer = Customer::factory()->create(['email' => $originalEmail, 'user_id' => $newUser->id]);
            $this->assertSame(0, $newCustomer->websites()->count());
            $this->assertSame(0, $newCustomer->subscriptions()->count());
            $this->assertSame(0, $newCustomer->privacyRequests()->count());
            $this->assertSame(0, DB::table('billing_invoices')->where('customer_id', $newCustomer->id)->count());
            $this->assertNotSame($newCustomer->id, $restored->id);
            $this->assertSame(false, config('privacy.destructive_retention_enabled'));
        } finally {
            config(['database.default' => $originalDefault, 'platform.recovery_mode' => false]);
            DB::purge();
            foreach ([$sourcePath, $restorePath] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            @rmdir($root);
        }
    }

    public function test_synthetic_anonymizer_refuses_non_testing_environment_even_if_test_flag_is_set(): void
    {
        config(['app.env' => 'production', 'privacy.synthetic_profile_anonymization_enabled' => true]);
        $this->expectException(\RuntimeException::class);
        app(SyntheticPrivacyAnonymizationService::class)->execute(new PrivacyErasureExecutionPlan, new PrivacyErasureExecutionAction, new User, '');
    }

    private function journal(): ObjectStoragePrivacyTombstoneJournal
    {
        $store = new class implements PrivacyTombstoneObjectStore
        {
            public function configured(): bool
            {
                return true;
            }

            public function driver(): string
            {
                return 'local';
            }

            public function disk(): Filesystem
            {
                return Storage::disk('platform_backups');
            }
        };

        return new ObjectStoragePrivacyTombstoneJournal($store, app(PlatformBackupCryptography::class));
    }

    private function replacementFor(string $uuid): string
    {
        return 'deleted+'.str_replace('-', '', strtolower($uuid)).'@privacy.invalid';
    }

    private function accountingFingerprint(int $customerId): string
    {
        return hash('sha256', json_encode(DB::table('billing_invoices')->where('customer_id', $customerId)->orderBy('id')->get()->toArray(), JSON_THROW_ON_ERROR));
    }
}
