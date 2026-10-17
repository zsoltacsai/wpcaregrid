<?php

namespace Tests\Feature\Owner;

use App\Backups\BackupGfsShadowStore;
use App\Backups\BackupManifest;
use App\Backups\BackupReason;
use App\Backups\BackupType;
use App\Backups\OwnerBackupProtectionService;
use App\Backups\SubscriptionBackupEntitlementRecorder;
use App\Models\Backup;
use App\Models\BackupObject;
use App\Models\BackupOwnerPolicy;
use App\Models\BackupRetentionPolicyPreview;
use App\Models\User;
use App\Models\Website;
use App\Owner\OwnerAbility;
use App\Pricing\PlanTier;
use App\Pricing\ProductType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsOwner;
use Tests\Concerns\CreatesBackups;
use Tests\TestCase;

final class BackupRetentionPolicyTest extends TestCase
{
    use ActsAsOwner, CreatesBackups, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpBackups();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownBackups();
        parent::tearDown();
    }

    public function test_owner_can_extend_protection_above_purchased_floor_without_destructive_effects(): void
    {
        $owner = $this->asOwner();
        $site = $this->siteWithEntitlement('wordpress', 'business');
        Queue::fake();
        $input = ['daily_recovery_points' => 0, 'weekly_recovery_points' => 12, 'monthly_recovery_points' => 6, 'desired_post_service_days' => 30];

        $this->get(route('owner.backup-policies.index'))->assertOk()->assertSee('backup-entitlements-v1')->assertSee('wordpress.business');
        $this->post(route('owner.backup-policies.preview', $site['website']->subscription), $input)
            ->assertRedirect();
        $preview = BackupRetentionPolicyPreview::query()->sole();
        $this->assertSame(8, $preview->impact['base_entitlement']['weekly']);
        $this->assertSame(12, $preview->impact['proposed_effective_policy']['weekly']);
        $this->assertSame(['WAIT_LEGAL', 'WAIT_ACCOUNTING', 'WAIT_FACT'], $preview->impact['wait_states']);
        $this->post(route('owner.backup-policies.confirm', $preview), ['current_password' => 'password', 'preview_hash' => $preview->preview_hash])
            ->assertRedirect(route('owner.backup-policies.index'));

        $policy = BackupOwnerPolicy::query()->sole();
        $this->assertSame(12, $policy->weekly_recovery_points);
        $this->assertSame(6, $policy->monthly_recovery_points);
        $this->assertSame(30, $policy->desired_post_service_days);
        $this->assertSame('confirmed', $preview->fresh()->state);
        $this->assertDatabaseHas('admin_audit_events', ['event' => 'backup_policy.extension_changed', 'actor_user_id' => $owner->id]);
        $this->assertSame(0, Backup::query()->count());
        Queue::assertNothingPushed();
        $this->assertSame([], app(BackupGfsShadowStore::class)->evaluate($site['website']->refresh())->result['eligible_candidates']);
    }

    public function test_owner_cannot_preview_values_below_any_purchased_floor(): void
    {
        $this->asOwner();
        $site = $this->siteWithEntitlement('wordpress', 'business');

        $this->post(route('owner.backup-policies.preview', $site['website']->subscription), [
            'daily_recovery_points' => 0,
            'weekly_recovery_points' => 7,
            'monthly_recovery_points' => 3,
            'desired_post_service_days' => 30,
        ])->assertSessionHasErrors('weekly_recovery_points');
        $this->assertDatabaseCount('backup_retention_policy_previews', 0);
        $this->assertDatabaseCount('subscription_backup_owner_policies', 0);
    }

    #[DataProvider('belowFloorValues')]
    public function test_each_retention_dimension_is_independently_enforced_at_the_purchased_floor(string $field, int $value): void
    {
        $this->asOwner();
        $site = $this->siteWithEntitlement('wordpress', 'secure');
        $proposed = ['daily_recovery_points' => 14, 'weekly_recovery_points' => 8, 'monthly_recovery_points' => 3, 'desired_post_service_days' => 30];
        $proposed[$field] = $value;

        $this->post(route('owner.backup-policies.preview', $site['website']->subscription), $proposed)->assertSessionHasErrors($field);
        $this->assertDatabaseCount('backup_retention_policy_previews', 0);
        $this->assertDatabaseCount('subscription_backup_owner_policies', 0);
    }

    public static function belowFloorValues(): array
    {
        return [
            'daily' => ['daily_recovery_points', 13],
            'weekly' => ['weekly_recovery_points', 7],
            'monthly' => ['monthly_recovery_points', 2],
        ];
    }

    public function test_reduction_preview_identifies_owner_only_points_and_requires_password_confirmation(): void
    {
        $this->asOwner();
        Carbon::setTestNow(Carbon::parse('2026-10-17 12:00:00', 'UTC'));
        $site = $this->siteWithEntitlement('wordpress', 'business');
        $at = CarbonImmutable::parse('2026-10-17 12:00:00', 'UTC');
        $history = [];
        for ($offset = 0; $offset < 12; $offset++) {
            $history[] = $this->validPoint($site['website'], $at->startOfWeek(Carbon::MONDAY)->subWeeks($offset), BackupReason::Scheduled);
        }
        $service = app(OwnerBackupProtectionService::class);
        $addition = $service->preview($site['website']->subscription, auth('owner')->user(), [
            'daily_recovery_points' => 0,
            'weekly_recovery_points' => 12,
            'monthly_recovery_points' => 6,
            'desired_post_service_days' => 30,
        ]);
        $service->confirm($addition, auth('owner')->user(), 'password', $addition->preview_hash);
        $reduction = $service->preview($site['website']->subscription->refresh(), auth('owner')->user(), [
            'daily_recovery_points' => 0,
            'weekly_recovery_points' => 8,
            'monthly_recovery_points' => 3,
            'desired_post_service_days' => 14,
        ]);

        $this->assertNotEmpty($reduction->impact['points_losing_only_owner_extension']);
        $this->assertNotEmpty($reduction->impact['would_become_future_candidates']);
        try {
            $service->confirm($reduction, auth('owner')->user(), 'wrong-password', $reduction->preview_hash);
            $this->fail('Owner protection reduction was accepted without current password confirmation.');
        } catch (ValidationException) {
            $this->assertSame(12, BackupOwnerPolicy::query()->sole()->weekly_recovery_points);
        }
    }

    public function test_source_change_invalidates_policy_reduction_preview_without_applying_it(): void
    {
        $this->asOwner();
        $site = $this->siteWithEntitlement('wordpress', 'business');
        $service = app(OwnerBackupProtectionService::class);
        $extended = $service->preview($site['website']->subscription, auth('owner')->user(), [
            'daily_recovery_points' => 0,
            'weekly_recovery_points' => 12,
            'monthly_recovery_points' => 6,
            'desired_post_service_days' => 30,
        ]);
        $service->confirm($extended, auth('owner')->user(), 'password', $extended->preview_hash);
        $policy = BackupOwnerPolicy::query()->sole();

        $reduction = $service->preview($site['website']->subscription->refresh(), auth('owner')->user(), [
            'daily_recovery_points' => 0,
            'weekly_recovery_points' => 8,
            'monthly_recovery_points' => 3,
            'desired_post_service_days' => 14,
        ]);
        $backup = $this->verifiedBackup($site['website'], BackupType::Full, now(), BackupReason::Manual);
        $backup->forceFill(['manifest' => ['changed' => true]])->save();

        $result = $service->confirm($reduction, auth('owner')->user(), 'password', $reduction->preview_hash);

        $this->assertTrue($result['stale']);
        $this->assertFalse($result['confirmed']);
        $this->assertSame('stale', $reduction->fresh()->state);
        $this->assertSame($policy->version, $policy->fresh()->version);
        $this->assertSame(12, $policy->fresh()->weekly_recovery_points);
        $this->assertDatabaseHas('admin_audit_events', ['event' => 'backup_policy.preview_stale']);
    }

    public function test_customer_and_operator_cannot_access_owner_backup_policy_controls(): void
    {
        $site = $this->siteWithEntitlement('wordpress', 'business');
        $customerUser = $site['website']->customer->user;
        $this->actingAs($customerUser, 'owner')->get(route('owner.backup-policies.index'))->assertRedirect(route('owner.login'));
        $operator = User::factory()->operator()->create();
        $this->actingAs($operator, 'owner')->get(route('owner.backup-policies.index'))->assertRedirect(route('owner.login'));
        $this->assertFalse(OwnerAbility::BackupPoliciesManage->allows($customerUser));
    }

    private function siteWithEntitlement(string $product, string $tier): array
    {
        $site = $this->backupReadySite('https://'.str_replace('.', '-', $product.'.'.$tier).'.example');
        $site['website']->subscription->update(['product_type' => ProductType::from($product), 'plan' => PlanTier::from($tier)]);
        app(SubscriptionBackupEntitlementRecorder::class)->record(
            $site['website']->subscription->refresh(),
            'owner-policy-'.$product.'-'.$tier,
            Carbon::now('UTC')->subMinute(),
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
