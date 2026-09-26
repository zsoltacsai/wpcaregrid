<?php

namespace Tests\Feature\Security;

use App\Models\SecurityFinding;
use App\Operator\Permission;
use App\Security\Confidence;
use App\Security\FindingDecisions;
use App\Security\FindingStatus;
use App\Security\ScanType;
use App\Security\SecurityRefused;
use App\Security\SecurityScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsSecurityScans;
use Tests\TestCase;

/** Deduplication, resolution, reopening, operator decisions and the administrator baseline. */
class FindingLifecycleTest extends TestCase
{
    use RefreshDatabase, RunsSecurityScans;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups(['manual' => true]);
    }

    protected function tearDown(): void
    {
        $this->tearDownBackups();
        parent::tearDown();
    }

    private function scanWith(array $site, array $findings, ScanType $type = ScanType::Full, bool $complete = true, ?array $admins = null): void
    {
        $scan = $this->requestScan($site, $type);
        $this->reportScan($site, $scan, $findings, $complete, admins: $admins)->assertStatus(202);
        if (! $complete) {
            // Leave it partial: no more parts.
            app(SecurityScanService::class)->timeOut($scan->refresh());
        }
    }

    public function test_a_repeated_finding_is_the_same_finding(): void
    {
        $site = $this->securitySite();

        $this->scanWith($site, [$this->suspiciousFile()]);
        $first = SecurityFinding::sole();
        $this->travel(2)->days();
        $this->scanWith($site, [$this->suspiciousFile()]);
        $this->travel(2)->days();
        $this->scanWith($site, [$this->suspiciousFile()]);

        $finding = SecurityFinding::sole();
        $this->assertSame($first->id, $finding->id);
        $this->assertSame(3, $finding->occurrence_count);
        $this->assertTrue($finding->first_seen_at->lt($finding->last_seen_at));
        $this->assertSame($first->first_scan_id, $finding->first_scan_id);
    }

    public function test_a_file_stays_one_finding_when_its_leading_rule_changes(): void
    {
        $site = $this->securitySite();

        $this->scanWith($site, [$this->suspiciousFile(code: 'UPLOADS_EXECUTABLE_FILE')]);
        $this->scanWith($site, [$this->suspiciousFile(code: 'PHP_REQUEST_EXECUTION', severity: 'critical', confidence: 'high')]);

        $finding = SecurityFinding::sole();
        $this->assertSame('PHP_REQUEST_EXECUTION', $finding->code);
        $this->assertSame(2, $finding->occurrence_count);
    }

    public function test_a_finding_that_disappears_is_resolved_by_a_complete_scan_only(): void
    {
        $site = $this->securitySite();
        $this->scanWith($site, [$this->suspiciousFile(), $this->integrityFinding()]);

        // A partial malware scan does not conclude anything is gone.
        $this->scanWith($site, [], ScanType::Malware, complete: false);
        $this->assertSame(2, SecurityFinding::active()->count());

        // A complete integrity scan resolves integrity findings only.
        $this->scanWith($site, [], ScanType::Integrity);
        $this->assertSame(FindingStatus::Resolved, SecurityFinding::where('type', 'integrity')->sole()->status);
        $this->assertSame(FindingStatus::Open, SecurityFinding::where('type', 'suspicious_file')->sole()->status);

        $this->scanWith($site, [], ScanType::Malware);
        $file = SecurityFinding::where('type', 'suspicious_file')->sole();
        $this->assertSame(FindingStatus::Resolved, $file->status);
        $this->assertNotNull($file->resolved_at);
        $this->assertSame(2, SecurityFinding::count(), 'Resolved, not deleted.');
    }

    public function test_a_resolved_finding_that_comes_back_is_reopened_not_duplicated(): void
    {
        $site = $this->securitySite();
        $this->scanWith($site, [$this->suspiciousFile()], ScanType::Malware);
        $this->scanWith($site, [], ScanType::Malware);

        $this->scanWith($site, [$this->suspiciousFile()], ScanType::Malware);

        $finding = SecurityFinding::sole();
        $this->assertSame(FindingStatus::Open, $finding->status);
        $this->assertNull($finding->resolved_at);
        $this->assertSame(2, $finding->occurrence_count);
    }

    public function test_a_false_positive_stays_until_the_file_changes(): void
    {
        $site = $this->securitySite();
        $this->scanWith($site, [$this->suspiciousFile(sha: str_repeat('a', 64))], ScanType::Malware);
        $operator = $this->operator([Permission::SecurityManage]);
        app(FindingDecisions::class)->decide(SecurityFinding::sole(), FindingStatus::FalsePositive, $operator, 'Known cache file of the theme.');

        $this->scanWith($site, [$this->suspiciousFile(sha: str_repeat('a', 64))], ScanType::Malware);
        $this->assertSame(FindingStatus::FalsePositive, SecurityFinding::sole()->status);

        // The file changed: someone needs to look again.
        $this->scanWith($site, [$this->suspiciousFile(sha: str_repeat('b', 64))], ScanType::Malware);
        $finding = SecurityFinding::sole();
        $this->assertSame(FindingStatus::Open, $finding->status);
        $this->assertSame('Reopened: the file changed.', $finding->status_note);

        // A false positive is never resolved by a scan that does not see it.
        app(FindingDecisions::class)->decide($finding, FindingStatus::FalsePositive, $operator);
        $this->scanWith($site, [], ScanType::Malware);
        $this->assertSame(FindingStatus::FalsePositive, SecurityFinding::sole()->status);
    }

    public function test_only_operators_with_security_manage_decide_and_never_to_remediated(): void
    {
        $site = $this->securitySite();
        $this->scanWith($site, [$this->suspiciousFile()], ScanType::Malware);
        $finding = SecurityFinding::sole();
        $decisions = app(FindingDecisions::class);

        foreach ([$this->operator([Permission::SecurityView, Permission::SecurityScan]), $site['website']->customer->user] as $user) {
            try {
                $decisions->decide($finding, FindingStatus::FalsePositive, $user);
                $this->fail('Decided without security.manage');
            } catch (SecurityRefused $e) {
                $this->assertSame('NOT_AUTHORIZED', $e->errorCode);
            }
        }

        $manager = $this->operator([Permission::SecurityManage]);
        foreach ([FindingStatus::Remediated, FindingStatus::Quarantined, FindingStatus::Resolved] as $status) {
            try {
                $decisions->decide($finding, $status, $manager);
                $this->fail("Set {$status->value} by hand");
            } catch (SecurityRefused $e) {
                $this->assertSame('INVALID_TRANSITION', $e->errorCode);
            }
        }

        $decisions->decide($finding, FindingStatus::Confirmed, $manager);
        $finding->refresh();
        $this->assertSame(Confidence::Confirmed, $finding->confidence);
        $this->assertTrue($finding->isConfirmedMalware());
        $this->assertSame($manager->id, $finding->status_changed_by_user_id);

        // A scanner never downgrades an operator's confirmation.
        $this->scanWith($site, [$this->suspiciousFile(confidence: 'low')], ScanType::Malware);
        $this->assertSame(Confidence::Confirmed, $finding->refresh()->confidence);
    }

    public function test_new_administrators_are_compared_with_the_baseline(): void
    {
        $site = $this->securitySite();
        $owner = ['id' => 1, 'login' => 'owner', 'registered' => '2025-01-01T00:00:00Z', 'roles' => ['administrator'], 'managed' => false];
        $managed = ['id' => 7, 'login' => 'wp-caregrid-admin', 'registered' => null, 'roles' => ['administrator'], 'managed' => true];

        // First scan: the baseline, no finding.
        $this->scanWith($site, [], ScanType::Malware, admins: [$owner]);
        $this->assertSame(0, SecurityFinding::count());
        $this->assertSame('established', $site['website']->securityScans()->latest('id')->first()->summary['baseline']);

        $intruder = ['id' => 42, 'login' => 'support-team', 'registered' => now()->toIso8601String(), 'roles' => ['administrator'], 'managed' => false];
        $this->scanWith($site, [], ScanType::Malware, admins: [$owner, $managed, $intruder]);

        $finding = SecurityFinding::sole();
        $this->assertSame('NEW_ADMIN_ACCOUNT', $finding->code);
        $this->assertSame('user:42', $finding->component);
        $this->assertSame(Confidence::Low, $finding->confidence, 'It may be legitimate.');
        $this->assertSame('support-team', $finding->evidence['user_login']);

        // Still flagged on the next scan: being there before does not make it known.
        $this->scanWith($site, [], ScanType::Malware, admins: [$owner, $managed, $intruder]);
        $this->assertSame(FindingStatus::Open, SecurityFinding::sole()->status);
        $this->assertSame(2, SecurityFinding::sole()->occurrence_count);

        // Once an operator decides, the account is part of the baseline.
        app(FindingDecisions::class)->decide(SecurityFinding::sole(), FindingStatus::FalsePositive, $this->operator([Permission::SecurityManage]), 'Agency account.');
        $this->scanWith($site, [], ScanType::Malware, admins: [$owner, $managed, $intruder]);
        $this->assertSame(FindingStatus::FalsePositive, SecurityFinding::sole()->status);
        $this->assertSame(1, SecurityFinding::count());
    }
}
