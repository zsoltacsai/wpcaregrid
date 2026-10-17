<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupGfsShadowStore;
use App\Backups\BackupManifest;
use App\Backups\BackupPolicy;
use App\Backups\BackupReason;
use App\Backups\BackupStatus;
use App\Backups\BackupType;
use App\Backups\GfsRetentionEvaluator;
use App\Backups\SubscriptionBackupEntitlementRecorder;
use App\Connector\JobStatus;
use App\Connector\JobType;
use App\Models\Backup;
use App\Models\BackupObject;
use App\Models\BackupRestore;
use App\Models\Website;
use App\Models\WebsiteEvent;
use App\Models\WebsiteJob;
use App\Pricing\PlanTier;
use App\Pricing\ProductType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesBackups;
use Tests\TestCase;

class GfsRetentionTest extends TestCase
{
    use CreatesBackups, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups();
    }

    protected function tearDown(): void
    {
        $this->tearDownBackups();
        parent::tearDown();
    }

    #[DataProvider('planPolicies')]
    public function test_all_six_plans_generate_the_exact_configured_utc_slot_counts(string $product, string $tier, int $daily, int $weekly, int $monthly, string $cadence): void
    {
        $site = $this->siteWithEntitlement($product, $tier);
        $at = CarbonImmutable::parse('2026-10-17 12:00:00', 'UTC');

        $result = app(GfsRetentionEvaluator::class)->evaluate($site['website'], $at);

        $this->assertSame('RECOVERY_GAP', $result['status']);
        $this->assertSame(['daily' => $daily, 'weekly' => $weekly, 'monthly' => $monthly], $result['policy']);
        $this->assertCount($daily, $result['required_slots']['DAILY']);
        $this->assertCount($weekly, $result['required_slots']['WEEKLY']);
        $this->assertCount($monthly, $result['required_slots']['MONTHLY']);
        $this->assertSame($cadence, $site['website']->subscription->backupEntitlements()->sole()->cadence);
        $this->assertFalse($result['provider_deletion_authorized']);
        $this->assertSame([], $result['eligible_candidates']);
    }

    public static function planPolicies(): array
    {
        return [
            'wordpress basic' => ['wordpress', 'basic', 0, 0, 3, 'monthly'],
            'wordpress business' => ['wordpress', 'business', 0, 8, 3, 'weekly'],
            'wordpress secure' => ['wordpress', 'secure', 14, 8, 3, 'daily'],
            'woocommerce basic' => ['woocommerce', 'basic', 0, 8, 3, 'weekly'],
            'woocommerce business' => ['woocommerce', 'business', 14, 8, 3, 'daily'],
            'woocommerce secure' => ['woocommerce', 'secure', 30, 12, 6, 'daily'],
        ];
    }

    public function test_verified_manual_point_can_satisfy_all_natural_gfs_classes_without_suppressing_schedule(): void
    {
        $site = $this->siteWithEntitlement('wordpress', 'secure');
        $at = CarbonImmutable::parse('2026-10-17 12:00:00', 'UTC');
        $manual = $this->validPoint($site['website'], $at, BackupReason::Manual);

        $result = app(GfsRetentionEvaluator::class)->evaluate($site['website'], $at);
        $reasons = collect($result['protected_backups'])->firstWhere('backup_uuid', $manual->uuid)['reasons'];

        $this->assertContains('GFS_DAILY', $reasons);
        $this->assertContains('GFS_WEEKLY', $reasons);
        $this->assertContains('GFS_MONTHLY', $reasons);
        $this->assertContains('LAST_KNOWN_GOOD', $reasons);
        $this->assertSame('daily:2026-10-17', BackupPolicy::window('daily', $at));

        Queue::fake();
        $this->artisan('backup:schedule')->assertSuccessful();
        $this->assertDatabaseHas('backups', ['website_id' => $site['website']->id, 'reason' => BackupReason::Scheduled->value]);
        $this->assertSame(2, $site['website']->backups()->count(), 'A manual recovery point must not suppress scheduled cadence.');
    }

    public function test_failed_unverified_or_incomplete_points_do_not_satisfy_required_slots(): void
    {
        $site = $this->siteWithEntitlement('wordpress', 'basic');
        $at = CarbonImmutable::parse('2026-10-17 12:00:00', 'UTC');
        $failed = $this->validPoint($site['website'], $at->startOfMonth(), BackupReason::Scheduled);
        $failed->forceFill(['status' => BackupStatus::Failed])->save();
        $unverified = $this->validPoint($site['website'], $at->startOfMonth()->addDay(), BackupReason::Scheduled);
        $unverified->forceFill(['status' => BackupStatus::Uploaded])->save();
        $incomplete = $this->validPoint($site['website'], $at->startOfMonth()->addDays(2), BackupReason::Scheduled);
        $incomplete->objects()->where('name', 'files')->update(['status' => BackupObject::FAILED]);

        $result = app(GfsRetentionEvaluator::class)->evaluate($site['website'], $at);

        $this->assertSame(3, count($result['missing_slots']));
        $this->assertSame([], $result['satisfied_slots']['MONTHLY']);
        $this->assertSame([], $result['protected_backups']);
    }

    public function test_latest_usable_point_safety_backup_and_active_restore_sources_have_additive_reasons(): void
    {
        $site = $this->siteWithEntitlement('wordpress', 'business');
        $at = CarbonImmutable::parse('2026-10-17 12:00:00', 'UTC');
        $old = $this->validPoint($site['website'], $at->subMonths(5), BackupReason::PreUpdate);
        $current = $this->validPoint($site['website'], $at, BackupReason::Scheduled);
        $safety = $this->validPoint($site['website'], $at->subMonths(4), BackupReason::PreRestore);
        BackupRestore::query()->create([
            'website_id' => $site['website']->id,
            'backup_id' => $old->id,
            'safety_backup_id' => $safety->id,
            'scope' => 'full',
            'status' => 'restoring',
            'requested_by' => 'test',
            'confirmed_at' => now(),
        ]);
        WebsiteJob::query()->create([
            'website_id' => $site['website']->id,
            'connector_id' => $site['website']->activeConnector->id,
            'backup_id' => $old->id,
            'type' => JobType::UpdatePlugin->value,
            'payload' => ['plugin' => 'sample/sample.php'],
            'status' => JobStatus::Delivered->value,
            'requested_by' => 'test',
        ]);

        $result = app(GfsRetentionEvaluator::class)->evaluate($site['website'], $at);
        $byUuid = collect($result['protected_backups'])->keyBy('backup_uuid');

        $this->assertContains('LAST_KNOWN_GOOD', $byUuid[$current->uuid]['reasons']);
        $this->assertContains('ACTIVE_RESTORE', $byUuid[$old->uuid]['reasons']);
        $this->assertContains('UPDATE_DEPENDENCY', $byUuid[$old->uuid]['reasons']);
        $this->assertContains('SAFETY_BACKUP', $byUuid[$safety->uuid]['reasons']);
    }

    public function test_out_of_window_points_are_only_blocked_shadow_candidates_and_never_eligible(): void
    {
        Queue::fake();
        $site = $this->siteWithEntitlement('wordpress', 'basic');
        $at = CarbonImmutable::parse('2026-10-17 12:00:00', 'UTC');
        $old = $this->validPoint($site['website'], $at->subMonths(6), BackupReason::Manual);
        $latest = $this->validPoint($site['website'], $at, BackupReason::Scheduled);

        $result = app(GfsRetentionEvaluator::class)->evaluate($site['website'], $at);

        $this->assertContains($old->uuid, $result['potential_candidates']);
        $this->assertContains('WAIT_LEGAL', collect($result['blocked_candidates'])->firstWhere('backup_uuid', $old->uuid)['reasons']);
        $this->assertContains($latest->uuid, array_column($result['protected_backups'], 'backup_uuid'));
        $this->assertSame([], $result['eligible_candidates']);
        $this->assertFalse($result['provider_deletion_authorized']);
        $this->assertSame(2, Backup::count());
        $this->assertFileExists($this->storageRoot.'/'.$old->storage_path.'files.tar.gz');
        Queue::assertNothingPushed();
    }

    public function test_shadow_result_is_persisted_idempotently_and_recovery_gap_event_is_not_spammed(): void
    {
        $site = $this->siteWithEntitlement('wordpress', 'basic');
        $at = CarbonImmutable::parse('2026-10-17 12:00:00', 'UTC');

        $first = app(BackupGfsShadowStore::class)->evaluate($site['website'], $at);
        $second = app(BackupGfsShadowStore::class)->evaluate($site['website'], $at);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('backup_gfs_evaluations', 1);
        $this->assertSame(1, WebsiteEvent::query()->where('website_id', $site['website']->id)->where('event', 'backup.recovery_gap')->count());
        $this->assertSame(3, $second->missing_slot_count);
        $this->assertSame([], Backup::query()->whereIn('status', [BackupStatus::Expired, BackupStatus::Deleted])->pluck('uuid')->all());
    }

    public function test_evaluation_fingerprint_tracks_backup_object_verification_changes(): void
    {
        $site = $this->siteWithEntitlement('wordpress', 'basic');
        $at = CarbonImmutable::parse('2026-10-17 12:00:00', 'UTC');
        $backup = $this->validPoint($site['website'], $at->startOfMonth(), BackupReason::Scheduled);
        $evaluator = app(GfsRetentionEvaluator::class);

        $before = $evaluator->evaluate($site['website'], $at);
        $backup->objects()->where('name', 'files')->update(['status' => BackupObject::FAILED]);
        $after = $evaluator->evaluate($site['website']->refresh(), $at);

        $this->assertNotSame($before['evaluation_fingerprint'], $after['evaluation_fingerprint']);
        $this->assertSame(3, count($after['missing_slots']));
        $this->assertSame([], $after['eligible_candidates']);
    }

    public function test_utc_slot_boundaries_are_calendar_based_and_dst_independent(): void
    {
        $at = CarbonImmutable::parse('2021-01-01 00:15:00', 'UTC');
        $site = $this->siteWithEntitlement('wordpress', 'business', $at);

        $result = app(GfsRetentionEvaluator::class)->evaluate($site['website'], $at);

        $this->assertSame(['2020-W53', '2020-W52', '2020-W51', '2020-W50', '2020-W49', '2020-W48', '2020-W47', '2020-W46'], $result['required_slots']['WEEKLY']);
        $this->assertSame(['2021-01', '2020-12', '2020-11'], $result['required_slots']['MONTHLY']);
        $dst = CarbonImmutable::parse('2024-03-31 03:30:00', 'Europe/Budapest');
        $this->assertSame('2024-03-31', CarbonImmutable::instance($dst)->utc()->format('Y-m-d'));
    }

    private function siteWithEntitlement(string $product, string $tier, ?CarbonImmutable $effectiveAt = null): array
    {
        $site = $this->backupReadySite('https://'.str_replace('.', '-', $product.'.'.$tier).'.example');
        $site['website']->subscription->update([
            'product_type' => ProductType::from($product),
            'plan' => PlanTier::from($tier),
        ]);
        app(SubscriptionBackupEntitlementRecorder::class)->record(
            $site['website']->subscription->refresh(),
            'gfs-fixture-'.$product.'-'.$tier,
            $effectiveAt ? Carbon::instance($effectiveAt) : now(),
        );
        $site['website']->refresh();

        return $site;
    }

    private function validPoint(Website $website, CarbonImmutable $at, BackupReason $reason): Backup
    {
        $backup = $this->verifiedBackup($website, BackupType::Full, $at, $reason);
        $checksums = $backup->objects()->whereIn('name', $backup->type->objects())->get()
            ->mapWithKeys(fn (BackupObject $object) => [$object->filename => $object->sha256])
            ->all();
        $backup->forceFill(['manifest' => [
            'format' => BackupManifest::FORMAT,
            'backup_uuid' => $backup->uuid,
            'website_uuid' => $website->uuid,
            'type' => $backup->type->value,
            'checksums' => ['algorithm' => 'sha256'] + $checksums,
        ]])->save();

        return $backup->refresh();
    }
}
