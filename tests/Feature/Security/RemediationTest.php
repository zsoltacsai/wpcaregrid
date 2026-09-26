<?php

namespace Tests\Feature\Security;

use App\Backups\BackupReason;
use App\Backups\BackupStatus;
use App\Models\Backup;
use App\Models\SecurityFinding;
use App\Models\SecurityQuarantine;
use App\Models\SecurityRemediation;
use App\Models\User;
use App\Models\WebsiteEvent;
use App\Models\WebsiteJob;
use App\Operator\Permission;
use App\Security\FindingDecisions;
use App\Security\FindingStatus;
use App\Security\IncidentService;
use App\Security\RemediationService;
use App\Security\RemediationStatus;
use App\Security\RemediationType;
use App\Security\ScanType;
use App\Security\SecurityRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsSecurityScans;
use Tests\TestCase;

/**
 * Remediation: verified FULL backup first (BACKUP_REQUIRED_FAILED otherwise),
 * operator permission, confirmation, tenant isolation, idempotence,
 * recorded results and audit links, reversible quarantine.
 */
class RemediationTest extends TestCase
{
    use RefreshDatabase, RunsSecurityScans;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups(['manual' => true]);
        config(['security.remediation.enabled' => true]);
    }

    protected function tearDown(): void
    {
        $this->tearDownBackups();
        parent::tearDown();
    }

    /** A site with one confirmed suspicious file. */
    private function confirmedFinding(array $site): SecurityFinding
    {
        $this->reportScan($site, $this->requestScan($site, ScanType::Malware), [$this->suspiciousFile(sha: str_repeat('a', 64))]);
        $finding = SecurityFinding::where('website_id', $site['website']->id)->sole();
        app(FindingDecisions::class)->decide($finding, FindingStatus::Confirmed, $this->operator([Permission::SecurityManage]));

        return $finding->refresh();
    }

    private function remediator(): User
    {
        return $this->operator([Permission::SecurityView, Permission::SecurityRemediate]);
    }

    /** Deliver the remediation job and report what the site did. */
    private function reportRemediation(array $site, SecurityRemediation $remediation, string $status = 'success', string $code = 'FILE_QUARANTINED', ?array $data = null, ?array $error = null): void
    {
        $delivered = collect($this->pollJobs($site))->firstWhere('uuid', $remediation->refresh()->job->uuid);
        $this->assertNotNull($delivered, 'The remediation job was not delivered.');
        $this->reportJob($site, $remediation->job, $status, $code, $data ?? ['remediation' => [
            'remediation_uuid' => $remediation->uuid,
            'after' => ['path' => $remediation->finding->path, 'exists' => false],
            'quarantine' => ['original_path' => $remediation->finding->path, 'sha256' => str_repeat('a', 64), 'size' => 120, 'modified_at' => '2026-09-01T10:00:00+00:00', 'permissions' => '0644'],
        ]], $error)->assertStatus(202);
    }

    public function test_a_recent_verified_full_backup_allows_the_change_and_everything_is_linked(): void
    {
        $site = $this->securitySite();
        $finding = $this->confirmedFinding($site);
        $incident = app(IncidentService::class)->open($site['website'], 'Webshell', null, 'operator:1', [$finding]);
        $backup = $this->verifiedBackup($site['website']);
        $operator = $this->remediator();

        $remediation = app(RemediationService::class)->request($finding, RemediationType::QuarantineFile, $operator);

        $this->assertSame(RemediationStatus::Queued, $remediation->status);
        $this->assertSame($backup->id, $remediation->backup_id);
        $this->assertSame($incident->id, $remediation->incident_id);
        $this->assertSame('operator:'.$operator->id, $remediation->requested_by);
        $this->assertSame(['path' => $finding->path, 'sha256' => str_repeat('a', 64), 'size' => 120, 'finding_status' => 'confirmed'], $remediation->before);
        $job = $remediation->job;
        $this->assertSame('SECURITY_QUARANTINE', $job->type->value);
        $this->assertSame(['remediation_uuid' => $remediation->uuid, 'finding_uuid' => $finding->uuid, 'path' => $finding->path, 'sha256' => str_repeat('a', 64)], $job->payload);

        $this->reportRemediation($site, $remediation);

        $remediation->refresh();
        $this->assertSame(RemediationStatus::Completed, $remediation->status);
        $this->assertSame(['path' => $finding->path, 'exists' => false], $remediation->after);
        $this->assertSame(FindingStatus::Quarantined, $finding->refresh()->status);
        $quarantine = SecurityQuarantine::sole();
        $this->assertSame([$finding->path, str_repeat('a', 64), 120, '0644', $finding->id, $remediation->id], [$quarantine->original_path, $quarantine->sha256, $quarantine->size, $quarantine->original_permissions, $quarantine->finding_id, $quarantine->remediation_id]);
        $this->assertNotNull($quarantine->quarantined_at);
        $this->assertStringContainsString($finding->code, $quarantine->reason);

        $event = WebsiteEvent::where('event', 'security.finding_quarantined')->sole();
        $this->assertSame($backup->id, $event->backup_id);
        $this->assertSame([$remediation->uuid, $finding->uuid, $incident->uuid, $backup->uuid], [$event->data['remediation'], $event->data['finding'], $event->data['incident'], $event->data['backup_uuid']]);
        $this->assertSame('operator', WebsiteEvent::where('event', 'security.remediation_requested')->sole()->actor_type);
        $this->assertTrue($incident->events()->where('type', 'remediation')->exists());
    }

    public function test_without_a_recent_backup_one_is_made_first_and_the_change_waits_for_it(): void
    {
        $site = $this->securitySite();
        $finding = $this->confirmedFinding($site);

        $remediation = app(RemediationService::class)->request($finding, RemediationType::QuarantineFile, $this->remediator());

        $this->assertSame(RemediationStatus::AwaitingBackup, $remediation->status);
        $backup = Backup::sole();
        $this->assertSame(BackupReason::Recovery, $backup->reason);
        $this->assertSame('full', $backup->type->value);
        $this->assertSame($backup->id, $remediation->backup_id);
        $this->assertSame(0, WebsiteJob::where('type', 'SECURITY_QUARANTINE')->count(), 'Nothing is sent before the backup is verified.');

        // Backup verified → the change is released.
        $this->makeVerifiedBackupThroughApi($site, $backup);
        $this->assertSame(BackupStatus::Verified, $backup->refresh()->status);
        $this->assertSame(RemediationStatus::Queued, $remediation->refresh()->status);
        $this->assertSame(1, WebsiteJob::where('type', 'SECURITY_QUARANTINE')->count());
    }

    public function test_a_failed_backup_blocks_the_change_with_backup_required_failed(): void
    {
        $site = $this->securitySite();
        $finding = $this->confirmedFinding($site);
        $remediation = app(RemediationService::class)->request($finding, RemediationType::QuarantineFile, $this->remediator());
        $backup = Backup::sole();
        $this->pollJobs($site);

        $this->reportJob($site, $this->backupJob($backup), 'failed', 'DISK_FULL', [], ['code' => 'DISK_FULL', 'message' => 'No space.'])->assertStatus(202);

        $remediation->refresh();
        $this->assertSame(RemediationStatus::Blocked, $remediation->status);
        $this->assertSame('BACKUP_REQUIRED_FAILED', $remediation->error_code);
        $this->assertSame(0, WebsiteJob::where('type', 'SECURITY_QUARANTINE')->count(), 'No destructive job ever existed.');
        $this->assertSame(FindingStatus::Confirmed, $finding->refresh()->status);
        $this->assertSame('BACKUP_REQUIRED_FAILED', WebsiteEvent::where('event', 'security.remediation_blocked')->sole()->data['code']);
    }

    public function test_a_backup_that_cannot_even_start_blocks_the_change(): void
    {
        $site = $this->securitySite();
        $finding = $this->confirmedFinding($site);
        config(['backup.storage' => null, 'backup.providers.local.root' => null]);

        $remediation = app(RemediationService::class)->request($finding, RemediationType::QuarantineFile, $this->remediator());

        $this->assertSame(RemediationStatus::Blocked, $remediation->status);
        $this->assertSame('BACKUP_REQUIRED_FAILED', $remediation->error_code);
        $this->assertSame(0, WebsiteJob::where('type', 'SECURITY_QUARANTINE')->count());
    }

    public function test_refusals(): void
    {
        $site = $this->securitySite();
        $finding = $this->confirmedFinding($site);
        $service = app(RemediationService::class);
        $backups = fn () => Backup::count();
        $refused = function (string $code, callable $request) {
            try {
                $request();
                $this->fail("Accepted instead of {$code}");
            } catch (SecurityRefused $e) {
                $this->assertSame($code, $e->errorCode);
            }
        };

        $refused('NOT_AUTHORIZED', fn () => $service->request($finding, RemediationType::QuarantineFile, $this->operator([Permission::SecurityManage, Permission::SecurityView])));
        $refused('NOT_AUTHORIZED', fn () => $service->request($finding, RemediationType::QuarantineFile, $site['website']->customer->user));

        config(['security.remediation.enabled' => false]);
        $refused('REMEDIATION_DISABLED', fn () => $service->request($finding, RemediationType::QuarantineFile, $this->remediator()));
        config(['security.remediation.enabled' => true]);

        $refused('DISABLE_PLUGIN_NOT_ALLOWED', fn () => $service->request($finding, RemediationType::DisablePlugin, $this->remediator()));
        $refused('NOT_REPAIRABLE', fn () => $service->request($finding, RemediationType::RepairCoreFile, $this->remediator()));

        // A scanner's suspicion is not enough to remove a file.
        $this->reportScan($site, $this->requestScan($site, ScanType::Malware), [$this->suspiciousFile(sha: str_repeat('a', 64)), $this->suspiciousFile('wp-content/uploads/other.php')]);
        $unconfirmed = SecurityFinding::where('path', 'wp-content/uploads/other.php')->sole();
        $refused('FINDING_NOT_CONFIRMED', fn () => $service->request($unconfirmed, RemediationType::QuarantineFile, $this->remediator()));

        $before = $backups(); // the confirmed file seen again joined an incident, which made its own backup
        $site['website']->activeConnector->forceFill(['last_seen_at' => now()->subHours(3)])->save();
        $refused('CONNECTOR_OFFLINE', fn () => $service->request($finding->refresh(), RemediationType::QuarantineFile, $this->remediator()));

        $this->assertSame(0, SecurityRemediation::count());
        $this->assertSame($before, $backups(), 'A refused request starts no backup.');
        $this->assertGreaterThanOrEqual(6, WebsiteEvent::where('event', 'security.remediation_refused')->count());
    }

    public function test_it_is_idempotent(): void
    {
        $site = $this->securitySite();
        $finding = $this->confirmedFinding($site);
        $this->verifiedBackup($site['website']);
        $service = app(RemediationService::class);

        $remediation = $service->request($finding, RemediationType::QuarantineFile, $this->remediator());
        try {
            $service->request($finding->refresh(), RemediationType::QuarantineFile, $this->remediator());
            $this->fail('Second request accepted while the first is running');
        } catch (SecurityRefused $e) {
            $this->assertSame('REMEDIATION_IN_PROGRESS', $e->errorCode);
        }

        $this->reportRemediation($site, $remediation);
        // The same result reported again changes nothing.
        $this->reportJob($site, $remediation->job, 'success', 'FILE_QUARANTINED', ['remediation' => ['remediation_uuid' => $remediation->uuid]])->assertOk();
        $this->assertSame(1, SecurityQuarantine::count());

        // A quarantined finding is not quarantined again.
        try {
            $service->request($finding->refresh(), RemediationType::QuarantineFile, $this->remediator());
            $this->fail('Quarantined twice');
        } catch (SecurityRefused $e) {
            $this->assertSame('FINDING_NOT_CONFIRMED', $e->errorCode);
        }
        $this->assertSame(1, SecurityRemediation::count());
    }

    public function test_a_failed_change_is_recorded_and_the_finding_keeps_its_status(): void
    {
        $site = $this->securitySite();
        $finding = $this->confirmedFinding($site);
        $this->verifiedBackup($site['website']);
        $remediation = app(RemediationService::class)->request($finding, RemediationType::QuarantineFile, $this->remediator());

        $this->reportRemediation($site, $remediation, 'failed', 'FILE_CHANGED', [], ['code' => 'FILE_CHANGED', 'message' => 'The file changed since it was scanned.']);

        $this->assertSame(RemediationStatus::Failed, $remediation->refresh()->status);
        $this->assertSame('FILE_CHANGED', $remediation->error_code);
        $this->assertSame(FindingStatus::Confirmed, $finding->refresh()->status);
        $this->assertSame(0, SecurityQuarantine::count());
        $this->assertSame('FILE_CHANGED', WebsiteEvent::where('event', 'security.remediation_failed')->sole()->data['code']);
    }

    public function test_quarantine_can_be_undone_and_a_failed_undo_keeps_the_quarantine(): void
    {
        $site = $this->securitySite();
        $finding = $this->confirmedFinding($site);
        $this->verifiedBackup($site['website']);
        $service = app(RemediationService::class);
        $this->reportRemediation($site, $service->request($finding, RemediationType::QuarantineFile, $this->remediator()));
        $quarantine = SecurityQuarantine::sole();

        // Undo refused by the site (a file exists at the original path now).
        $undo = $service->restoreQuarantine($quarantine, $this->remediator());
        $this->assertSame(RemediationStatus::Queued, $undo->status, 'Undoing needs no new backup.');
        $this->assertSame(['remediation_uuid' => $undo->uuid, 'quarantine_uuid' => $quarantine->uuid, 'path' => $quarantine->original_path, 'sha256' => $quarantine->sha256], $undo->job->payload);
        $this->reportRemediation($site, $undo, 'failed', 'QUARANTINE_TARGET_EXISTS', [], ['code' => 'QUARANTINE_TARGET_EXISTS', 'message' => 'A file exists.']);
        $this->assertSame(SecurityQuarantine::QUARANTINED, $quarantine->refresh()->status);
        $this->assertSame(FindingStatus::Quarantined, $finding->refresh()->status);

        // Undo that works: the file is back, the finding is open again.
        $undo = $service->restoreQuarantine($quarantine, $this->remediator());
        $this->reportRemediation($site, $undo, 'success', 'QUARANTINE_RESTORED', ['remediation' => ['remediation_uuid' => $undo->uuid, 'after' => ['path' => $quarantine->original_path, 'exists' => true]]]);
        $this->assertSame(SecurityQuarantine::RESTORED, $quarantine->refresh()->status);
        $this->assertNotNull($quarantine->restored_at);
        $this->assertSame(FindingStatus::Open, $finding->refresh()->status);
        $this->assertTrue(WebsiteEvent::where('event', 'security.quarantine_restored')->exists());
    }

    public function test_repairs_and_deactivation_use_fixed_jobs_without_urls_or_content(): void
    {
        config(['security.remediation.allow_disable_plugin' => true]);
        $site = $this->securitySite();
        $this->reportScan($site, $this->requestScan($site, ScanType::Integrity), [$this->integrityFinding()]);
        $core = SecurityFinding::sole();
        $this->verifiedBackup($site['website']);
        $service = app(RemediationService::class);

        $repair = $service->request($core, RemediationType::RepairCoreFile, $this->remediator());
        $this->assertSame(['remediation_uuid' => $repair->uuid, 'source' => 'core', 'path' => 'wp-includes/version.php', 'sha256' => $core->sha256()], $repair->job->payload);
        $this->reportRemediation($site, $repair, 'success', 'FILE_REPAIRED', ['remediation' => ['remediation_uuid' => $repair->uuid, 'after' => ['path' => 'wp-includes/version.php', 'sha256' => str_repeat('c', 64), 'exists' => true]]]);
        $this->assertSame(FindingStatus::Remediated, $core->refresh()->status);
        $this->assertTrue(WebsiteEvent::where('event', 'security.finding_remediated')->exists());

        $this->reportScan($site, $this->requestScan($site, ScanType::Malware), [['component' => 'plugin:akismet', 'path' => 'wp-content/plugins/akismet/evil.php'] + $this->suspiciousFile('wp-content/plugins/akismet/evil.php')]);
        $pluginFinding = SecurityFinding::where('path', 'wp-content/plugins/akismet/evil.php')->sole();
        $disable = $service->request($pluginFinding, RemediationType::DisablePlugin, $this->remediator());
        $this->assertSame(['remediation_uuid' => $disable->uuid, 'plugin' => 'akismet/akismet.php'], $disable->job->payload);

        foreach (WebsiteJob::whereIn('type', ['SECURITY_REPAIR_FILE', 'SECURITY_DISABLE_PLUGIN'])->get() as $job) {
            $this->assertStringNotContainsString('http', json_encode($job->payload));
        }
    }

    public function test_remediation_requests_through_the_console_are_scoped_to_the_website(): void
    {
        $mine = $this->securitySite('https://company.example');
        $other = $this->securitySite('https://other.example');
        $theirs = $this->confirmedFinding($other);
        $this->verifiedBackup($other['website']);
        $this->asOperator([Permission::SecurityView, Permission::SecurityRemediate]);

        $this->post(route('operator.websites.security.findings.remediate', [$mine['website'], $theirs]), ['type' => 'quarantine_file', 'confirm' => '1'])->assertNotFound();
        $this->assertSame(0, SecurityRemediation::count());

        // An incident of another website cannot be attached either.
        $incident = app(IncidentService::class)->open($mine['website'], 'x', null, 'operator:1');
        try {
            app(RemediationService::class)->request($theirs, RemediationType::QuarantineFile, $this->remediator(), $incident);
            $this->fail('Cross-website incident accepted');
        } catch (SecurityRefused $e) {
            $this->assertSame('NOT_AUTHORIZED', $e->errorCode);
        }

        // The console needs an explicit confirmation too.
        $this->post(route('operator.websites.security.findings.remediate', [$other['website'], $theirs]), ['type' => 'quarantine_file'])->assertSessionHasErrors('confirm');
        $this->post(route('operator.websites.security.findings.remediate', [$other['website'], $theirs]), ['type' => 'restore_quarantined_file', 'confirm' => '1'])->assertSessionHasErrors('type');
        $this->assertSame(0, SecurityRemediation::count());
        $this->assertSame(0, WebsiteJob::whereIn('type', ['SECURITY_QUARANTINE', 'SECURITY_RESTORE_QUARANTINE'])->count());
    }
}
