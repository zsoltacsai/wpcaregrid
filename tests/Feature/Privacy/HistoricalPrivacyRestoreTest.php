<?php

namespace Tests\Feature\Privacy;

use App\Models\Customer;
use App\Models\PrivacyRequest;
use App\Models\PrivacyTombstoneReplaySession;
use App\Models\User;
use App\Platform\DisasterRecovery\PlatformBackupCryptography;
use App\Privacy\ObjectStoragePrivacyTombstoneJournal;
use App\Privacy\PrivacyRequestService;
use App\Privacy\PrivacyRequestSource;
use App\Privacy\PrivacyRequestStatus;
use App\Privacy\PrivacyRequestType;
use App\Privacy\PrivacyRestoreSafety;
use App\Privacy\PrivacyTombstoneObjectStore;
use App\Privacy\PrivacyTombstoneReplayApplier;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class HistoricalPrivacyRestoreTest extends TestCase
{
    public function test_real_old_sqlite_snapshot_replays_an_independent_post_snapshot_privacy_suppression(): void
    {
        $root = sys_get_temp_dir().'/wpcg-privacy-restore-'.bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        $snapshotPath = $root.'/t0.sqlite';
        $restoredPath = $root.'/restored.sqlite';
        $originalConnection = config('database.default');
        config([
            'cache.default' => 'array',
            'platform.deployment_environment' => 'testing',
            'platform_backup.prefix' => 'platform-database',
            'platform_backup.encryption.current_key_id' => 'historical-test-key',
            'platform_backup.encryption.keys.historical-test-key' => base64_encode(random_bytes(32)),
        ]);
        Storage::fake('platform_backups');
        $objectStore = new class implements PrivacyTombstoneObjectStore
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
        $journal = new ObjectStoragePrivacyTombstoneJournal($objectStore, app(PlatformBackupCryptography::class));
        $this->app->instance(PrivacyTombstoneJournal::class, $journal);

        try {
            touch($snapshotPath);
            config(['database.connections.privacy_t0' => ['driver' => 'sqlite', 'database' => $snapshotPath, 'prefix' => '', 'foreign_key_constraints' => true]]);
            DB::purge('privacy_t0');
            $this->createRecoverySchema('privacy_t0');
            config(['database.default' => 'privacy_t0']);
            DB::purge();

            $customerUuid = (string) Str::uuid();
            $caseUuid = (string) Str::uuid();
            $ownerId = DB::table('users')->insertGetId(['name' => 'Synthetic Owner', 'email' => 'owner@example.test', 'password' => 'synthetic-hash', 'role' => User::ROLE_OWNER]);
            $customerId = DB::table('customers')->insertGetId([
                'uuid' => $customerUuid, 'user_id' => null, 'email' => 'synthetic@example.test',
                'first_name' => 'Synthetic', 'last_name' => 'Subject', 'active' => true,
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            DB::table('privacy_requests')->insert([
                'uuid' => $caseUuid, 'customer_id' => $customerId, 'request_type' => PrivacyRequestType::AccountClosure->value,
                'status' => PrivacyRequestStatus::Requested->value, 'active_request_key' => 'active',
                'idempotency_hash' => hash('sha256', 'historical-restore-fixture'), 'source' => PrivacyRequestSource::Support->value,
                'requested_at' => now('UTC'), 'lock_version' => 0, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            DB::table('billing_invoices')->insert(['customer_id' => $customerId, 'invoice_number' => 'synthetic-invoice-immutable', 'snapshot' => json_encode(['seller' => 'unchanged'])]);

            $snapshotWatermark = $journal->watermark();
            DB::purge('privacy_t0');
            copy($snapshotPath, $restoredPath);

            $owner = User::query()->findOrFail($ownerId);
            $customer = Customer::query()->where('uuid', $customerUuid)->firstOrFail();
            $case = PrivacyRequest::query()->where('uuid', $caseUuid)->firstOrFail();
            app(PrivacyRequestService::class)->restrictAccountAccess($case, $owner);
            $this->assertFalse($customer->fresh()->active, 'T1 application privacy path did not create the expected synthetic suppression.');
            $this->assertNotNull($journal->watermark(), 'T1 journal event was not committed outside the DB file.');
            $journalObject = 'platform-database/privacy-tombstones/testing/events/'.hash('sha256', 'privacy:account_access_restricted:'.$caseUuid).'.json.enc';
            $this->assertTrue(Storage::disk('platform_backups')->exists($journalObject));
            $this->assertStringNotContainsString('synthetic@example.test', Storage::disk('platform_backups')->get($journalObject));

            config(['database.connections.privacy_recovery' => ['driver' => 'sqlite', 'database' => $restoredPath, 'prefix' => '', 'foreign_key_constraints' => true]]);
            DB::purge('privacy_recovery');
            $this->createRecoveryEvidenceSchema('privacy_recovery');
            $recoveryId = (string) Str::uuid();
            $sourceBackupUuid = (string) Str::uuid();
            config([
                'database.default' => 'privacy_recovery', 'platform.recovery_mode' => true,
                'platform.recovery_id' => $recoveryId, 'platform.recovery_source_backup_uuid' => $sourceBackupUuid,
                'platform.recovery_snapshot_watermark' => $snapshotWatermark,
            ]);
            DB::purge();

            $restored = Customer::query()->where('uuid', $customerUuid)->firstOrFail();
            $restoredCase = PrivacyRequest::query()->where('uuid', $caseUuid)->firstOrFail();
            $this->assertTrue($restored->active, 'The actual T0 file restore must demonstrate the post-T0 action is absent before replay.');
            $this->assertSame(PrivacyRequestStatus::Requested, $restoredCase->status);

            $first = app(PrivacyTombstoneReplayApplier::class)->replay($recoveryId, $snapshotWatermark, null, $sourceBackupUuid);
            $this->assertSame('complete', $first->status);
            $this->assertSame(1, $first->events_applied);
            $this->assertFalse($restored->fresh()->active);
            $this->assertSame(PrivacyRequestStatus::AccessRestricted, $restoredCase->fresh()->status);
            $this->assertSame('synthetic-invoice-immutable', DB::table('billing_invoices')->value('invoice_number'));
            $this->assertSame('unchanged', json_decode(DB::table('billing_invoices')->value('snapshot'), true, flags: JSON_THROW_ON_ERROR)['seller']);
            $this->assertSame('ready', app(PrivacyRestoreSafety::class)->status()['state']);

            $secondRecoveryId = (string) Str::uuid();
            config(['platform.recovery_id' => $secondRecoveryId]);
            $second = app(PrivacyTombstoneReplayApplier::class)->replay($secondRecoveryId, $snapshotWatermark, null, $sourceBackupUuid);
            $this->assertSame('complete', $second->status);
            $this->assertSame(1, $second->events_already_applied);
            $this->assertSame(2, PrivacyTombstoneReplaySession::query()->where('status', 'complete')->count());
            $this->assertFalse($restored->fresh()->active);
        } finally {
            config(['database.default' => $originalConnection]);
            DB::purge();
            foreach ([$snapshotPath, $restoredPath] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            @rmdir($root);
        }
    }

    private function createRecoverySchema(string $connection): void
    {
        Schema::connection($connection)->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->timestamps();
        });
        Schema::connection($connection)->create('customers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable();
            $table->string('email');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::connection($connection)->create('privacy_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id');
            $table->string('request_type');
            $table->string('status');
            $table->string('active_request_key')->nullable();
            $table->char('idempotency_hash', 64)->unique();
            $table->string('source');
            $table->timestamp('requested_at');
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });
        Schema::connection($connection)->create('privacy_request_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('privacy_request_id');
            $table->foreignId('actor_user_id')->nullable();
            $table->string('event_type');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
        });
        Schema::connection($connection)->create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id');
            $table->string('status');
            $table->timestamp('grace_ends_at')->nullable();
            $table->string('internal_plan')->nullable();
            $table->timestamp('internal_starts_at')->nullable();
            $table->timestamp('internal_ends_at')->nullable();
            $table->timestamps();
        });
        Schema::connection($connection)->create('billing_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id');
            $table->string('invoice_number');
            $table->json('snapshot');
        });
        Schema::connection($connection)->create('admin_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable();
            $table->string('actor_type');
            $table->string('event');
            $table->string('subject_type');
            $table->string('subject_key');
            $table->json('data')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamp('created_at');
        });
        Schema::connection($connection)->create('owner_settings', function (Blueprint $table): void {
            $table->string('id', 96)->primary();
            $table->longText('value');
            $table->timestamps();
        });
    }

    private function createRecoveryEvidenceSchema(string $connection): void
    {
        Schema::connection($connection)->create('privacy_tombstone_replay_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('recovery_id')->index();
            $table->string('source_backup_uuid', 36)->nullable();
            $table->string('snapshot_watermark', 26)->nullable();
            $table->string('ending_watermark', 26)->nullable();
            $table->string('status')->index();
            $table->string('verification_status')->default('pending');
            $table->unsignedInteger('events_discovered')->default(0);
            $table->unsignedInteger('events_applied')->default(0);
            $table->unsignedInteger('events_already_applied')->default(0);
            $table->unsignedInteger('events_blocked')->default(0);
            $table->string('failure_code')->nullable();
            $table->foreignId('actor_user_id')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::connection($connection)->create('privacy_tombstone_replay_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('replay_session_id');
            $table->char('event_id', 64);
            $table->string('sequence', 26);
            $table->string('action');
            $table->string('subject_uuid', 36);
            $table->string('outcome');
            $table->string('blocker_code')->nullable();
            $table->timestamp('processed_at');
            $table->unique(['replay_session_id', 'event_id']);
        });
    }
}
