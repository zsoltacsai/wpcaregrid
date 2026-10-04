<?php

namespace Tests\Feature\Security;

use App\Connector\HealthStatus;
use App\Connector\JobStatus;
use App\Models\SecurityFinding;
use App\Models\SecurityScan;
use App\Models\WebsiteEvent;
use App\Models\WebsiteJob;
use App\Security\Confidence;
use App\Security\FindingStatus;
use App\Security\FindingType;
use App\Security\ScanStatus;
use App\Security\ScanType;
use App\Security\SecurityRefused;
use App\Security\SecurityScanService;
use App\Security\Severity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RunsSecurityScans;
use Tests\TestCase;

/** Scan lifecycle, parts, versions, failure, timeout, and what is stored. */
class SecurityScanTest extends TestCase
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

    public function test_a_full_scan_runs_the_available_scanners_and_says_which_are_not(): void
    {
        $site = $this->securitySite();

        $scan = $this->requestScan($site);

        $this->assertSame(ScanStatus::Queued, $scan->status);
        $this->assertSame(config('security.scanner_version'), $scan->scanner_version);
        $this->assertEquals(['status' => 'unavailable', 'reason' => 'VULNERABILITY_SOURCE_NOT_CONFIGURED', 'source' => 'none'], $scan->coverage['vulnerability']);
        $job = WebsiteJob::where('type', 'SECURITY_SCAN')->sole();
        $this->assertEquals(['scan_uuid' => $scan->uuid, 'scan_type' => 'full', 'part' => 1, 'time_budget' => 60], $job->payload);
        $this->assertSame('scan_requested', explode('.', WebsiteEvent::where('event', 'like', 'security.%')->sole()->event)[1]);

        $this->pollJobs($site);
        $this->assertSame(ScanStatus::Running, $scan->refresh()->status, 'Delivered to the site: running.');
        $this->assertSame('system', WebsiteEvent::where('event', 'security.scan_started')->sole()->actor_type);
        $this->assertNotNull($scan->started_at);

        $this->reportScanDelivered($site, $scan, [$this->suspiciousFile(), $this->integrityFinding()]);

        $scan->refresh();
        $this->assertSame(ScanStatus::Completed, $scan->status);
        $this->assertSame('2026.10.1', $scan->rules_version);
        $this->assertSame(config('connector.latest_version'), $scan->connector_version);
        $this->assertSame(['vulnerability'], $scan->summary['unavailable']);
        $this->assertSame(2, $scan->summary['findings']);
        $this->assertSame(2, $scan->summary['by_severity']['high']);
        $this->assertNotNull($scan->completed_at);
        $this->assertNotNull($scan->duration_ms);
        $this->assertSame(2, SecurityFinding::count());
        $this->assertTrue(WebsiteEvent::where('event', 'security.scan_completed')->exists());
        // The job keeps counts only; findings live in security_findings.
        $this->assertEquals(['security' => ['scan_uuid' => $scan->uuid, 'part' => 1, 'complete' => true, 'findings' => 2]], WebsiteJob::where('type', 'SECURITY_SCAN')->sole()->result);
    }

    public function test_a_scan_continues_in_parts_until_the_site_reports_it_complete(): void
    {
        $site = $this->securitySite();
        $scan = $this->requestScan($site, ScanType::Malware);

        $this->reportScan($site, $scan, [$this->suspiciousFile('wp-content/uploads/a.php')], complete: false)->assertStatus(202);
        $this->assertSame(ScanStatus::Running, $scan->refresh()->status);
        $this->assertSame(2, $scan->parts);
        $this->assertSame(2, WebsiteJob::where('type', 'SECURITY_SCAN')->latest('id')->first()->payload['part']);

        $this->reportScan($site, $scan, [$this->suspiciousFile('wp-content/uploads/b.php')])->assertStatus(202);

        $this->assertSame(ScanStatus::Completed, $scan->refresh()->status);
        $this->assertSame(2, SecurityFinding::where('last_scan_id', $scan->id)->count());
    }

    public function test_a_scan_that_hits_the_part_limit_is_partial(): void
    {
        config(['security.scan.max_parts' => 2]);
        $site = $this->securitySite();
        $scan = $this->requestScan($site, ScanType::Malware);

        $this->reportScan($site, $scan, complete: false);
        $this->reportScan($site, $scan, [$this->suspiciousFile()], complete: false);

        $scan->refresh();
        $this->assertSame(ScanStatus::Partial, $scan->status);
        $this->assertSame('SCAN_PART_LIMIT', $scan->error_code);
        $this->assertSame(1, SecurityFinding::count(), 'What the parts found is kept.');
    }

    public function test_a_failing_part_fails_the_scan(): void
    {
        $site = $this->securitySite();
        $scan = $this->requestScan($site, ScanType::Integrity);
        $job = WebsiteJob::where('uuid', collect($this->pollJobs($site))->firstWhere('type', 'SECURITY_SCAN')['uuid'])->sole();

        $this->reportJob($site, $job, 'failed', 'SCAN_STATE_LOST', [], ['code' => 'SCAN_STATE_LOST', 'message' => 'Lost.'])->assertStatus(202);

        $scan->refresh();
        $this->assertSame(ScanStatus::Failed, $scan->status);
        $this->assertSame('SCAN_STATE_LOST', $scan->error_code);
        $this->assertTrue(WebsiteEvent::where('event', 'security.scan_failed')->exists());
        $this->assertNull($site['website']->refresh()->security_scanned_at, 'A failed scan is not a scan.');
    }

    /** @return array<string, array{0: array}> */
    public static function invalidResults(): array
    {
        $file = ['type' => 'suspicious_file', 'code' => 'UPLOADS_EXECUTABLE_FILE', 'severity' => 'high', 'confidence' => 'medium', 'title' => 'x', 'path' => 'wp-content/uploads/a.php'];

        return [
            'traversal path' => [['findings' => [['path' => 'wp-content/../../wp-config.php'] + $file]]],
            'absolute path' => [['findings' => [['path' => '/etc/passwd'] + $file]]],
            'windows path' => [['findings' => [['path' => 'C:/Windows/win.ini'] + $file]]],
            'confirmed by a scanner' => [['findings' => [['confidence' => 'confirmed'] + $file]]],
            'unknown type' => [['findings' => [['type' => 'malware_confirmed'] + $file]]],
            'vulnerability from the site' => [['findings' => [['type' => 'vulnerability'] + $file]]],
            'bad code' => [['findings' => [['code' => 'drop table;'] + $file]]],
            'another scan' => [['scan_uuid' => '00000000-0000-4000-8000-000000000000']],
        ];
    }

    #[DataProvider('invalidResults')]
    public function test_a_result_that_does_not_validate_is_rejected_and_nothing_is_stored(array $overrides): void
    {
        $site = $this->securitySite();
        $scan = $this->requestScan($site, ScanType::Malware);

        $this->reportScan($site, $scan, overrides: $overrides);

        $this->assertSame(ScanStatus::Failed, $scan->refresh()->status);
        $this->assertSame('INVALID_RESULT', $scan->error_code);
        $this->assertSame(0, SecurityFinding::count());
    }

    public function test_evidence_is_metadata_only_and_text_stays_text(): void
    {
        $site = $this->securitySite();
        $scan = $this->requestScan($site, ScanType::Malware);
        $finding = $this->suspiciousFile();
        $finding['evidence'] = array_merge($finding['evidence'], ['content' => '<?php system($_GET[1]);', 'snippet' => 'eval(', 'user_email' => 'owner@company.example', 'password' => 'hunter2', 'sha256' => 'not-a-hash']);
        $finding['title'] = '<script>alert(1)</script>Suspicious  file';

        $this->reportScan($site, $scan, [$finding]);

        $stored = SecurityFinding::sole();
        $this->assertEqualsCanonicalizing(['rules', 'size', 'lines'], array_keys($stored->evidence));
        // Stored as text (control characters and runs of spaces normalised), not as HTML.
        $this->assertSame('<script>alert(1)</script>Suspicious file', $stored->title);
        $json = json_encode($stored->toArray());
        foreach (['system($_GET', 'owner@company.example', 'hunter2'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }

        // Rendered escaped, never executed.
        $this->asOperator();
        $this->get(route('operator.websites.security', $site['website']))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;Suspicious file', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_scan_refusals(): void
    {
        $site = $this->securitySite();

        $this->requestScan($site);
        $this->assertRefused('SCAN_IN_PROGRESS', fn () => $this->requestScan($site));

        $old = $this->consoleSite('https://old-connector.example');
        $this->assertRefused('CAPABILITY_MISSING', fn () => $this->requestScan($old, ScanType::Malware));

        $old['website']->subscription->update(['status' => 'canceled']);
        $this->assertRefused('SUBSCRIPTION_INACTIVE', fn () => $this->requestScan($old));

        $never = $this->websiteWithPlan('https://never.example');
        $this->assertRefused('NOT_CONNECTED', fn () => $this->requestScan(['website' => $never], ScanType::Integrity));
    }

    public function test_a_vulnerability_only_scan_without_a_source_fails_rather_than_saying_nothing_was_found(): void
    {
        $site = $this->securitySite();

        $scan = $this->requestScan($site, ScanType::Vulnerability);

        $this->assertSame(ScanStatus::Failed, $scan->status);
        $this->assertSame('NO_SCANNER_COMPLETED', $scan->error_code);
        $this->assertSame(0, WebsiteJob::where('type', 'SECURITY_SCAN')->count(), 'No connector part for a server-side scan.');
        $this->assertNotSame(HealthStatus::Healthy, $site['website']->refresh()->security_status);
    }

    public function test_scans_without_progress_time_out(): void
    {
        $site = $this->securitySite();
        $scan = $this->requestScan($site, ScanType::Malware);

        $this->travel(config('security.scan.timeout_minutes') + 1)->minutes();
        $this->artisan('security:monitor')->assertSuccessful();

        $scan->refresh();
        $this->assertSame(ScanStatus::Failed, $scan->status);
        $this->assertSame('SCAN_TIMEOUT', $scan->error_code);
        $this->assertSame(JobStatus::Expired, WebsiteJob::where('type', 'SECURITY_SCAN')->sole()->status);
    }

    public function test_cancel_stops_the_part_that_has_not_run(): void
    {
        $site = $this->securitySite();
        $scan = $this->requestScan($site, ScanType::Malware);

        app(SecurityScanService::class)->cancel($scan, 'operator:1');

        $this->assertSame(ScanStatus::Cancelled, $scan->refresh()->status);
        $this->assertSame([], collect($this->pollJobs($site))->where('type', 'SECURITY_SCAN')->all());
    }

    public function test_scheduled_scans_are_off_until_enabled_and_use_the_plans_own_frequency(): void
    {
        $site = $this->securitySite();

        $this->artisan('security:schedule')->assertSuccessful();
        $this->assertSame(0, SecurityScan::count());

        config(['security.schedule.enabled' => true]);
        $this->artisan('security:schedule')->assertSuccessful();
        $this->assertSame('scheduler', SecurityScan::sole()->requested_by);
        $this->assertSame(ScanType::Full, SecurityScan::sole()->type);

        // Once per plan window (business plan: weekly security checks).
        $this->reportScan($site, SecurityScan::sole());
        $this->artisan('security:schedule')->assertSuccessful();
        $this->assertSame(1, SecurityScan::count());
        $this->assertSame('weekly', config('pricing.plans.wordpress.business.security_check_frequency'));
    }

    public function test_severity_and_confidence_are_stored_independently(): void
    {
        $site = $this->securitySite();
        $scan = $this->requestScan($site, ScanType::Malware);

        $this->reportScan($site, $scan, [$this->suspiciousFile('wp-content/uploads/x.php', 'critical', 'low', 'PHP_REQUEST_EXECUTION')]);

        $finding = SecurityFinding::sole();
        $this->assertSame(FindingType::SuspiciousFile, $finding->type);
        $this->assertSame(Severity::Critical, $finding->severity);
        $this->assertSame(Confidence::Low, $finding->confidence);
        $this->assertSame(FindingStatus::Open, $finding->status);
        $this->assertFalse($finding->isConfirmedMalware());
    }

    /** reportScan when the part has already been delivered by an earlier poll. */
    private function reportScanDelivered(array $site, SecurityScan $scan, array $findings): void
    {
        $job = WebsiteJob::where('type', 'SECURITY_SCAN')->sole();
        $this->reportJob($site, $job, 'success', 'SCAN_COMPLETED', ['security' => [
            'scan_uuid' => $scan->uuid, 'part' => 1, 'complete' => true, 'scanner_version' => '0.4.0', 'rules_version' => '2026.10.1',
            'coverage' => $this->coverage(ScanType::Full), 'findings' => $findings,
        ]])->assertStatus(202);
    }

    private function assertRefused(string $code, callable $request): void
    {
        try {
            $request();
            $this->fail("Expected {$code}");
        } catch (SecurityRefused $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }
}
