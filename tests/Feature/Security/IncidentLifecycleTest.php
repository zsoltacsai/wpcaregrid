<?php

namespace Tests\Feature\Security;

use App\Models\AdminAccessRequest;
use App\Models\Backup;
use App\Models\SecurityFinding;
use App\Models\SecurityIncident;
use App\Models\WebsiteEvent;
use App\Operator\Permission;
use App\Security\IncidentService;
use App\Security\IncidentStatus;
use App\Security\ScanType;
use App\Security\SecurityRefused;
use App\Security\Severity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RunsSecurityScans;
use Tests\TestCase;

/** The incident state machine, its resolution requirements, and automatic opening on detection. */
class IncidentLifecycleTest extends TestCase
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

    private function incidents(): IncidentService
    {
        return app(IncidentService::class);
    }

    /** Scan, health report and functional check: what resolving needs. */
    private function verify(array $site): void
    {
        $this->travel(1)->minutes();
        $this->reportScan($site, $this->requestScan($site, ScanType::Full));
        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.heartbeat'), $this->heartbeatPayload(['connector_version' => '0.4.0']))->assertOk();
    }

    public function test_the_full_lifecycle_is_recorded(): void
    {
        $site = $this->securitySite();
        $incident = $this->incidents()->open($site['website'], 'Suspicious files', Severity::High, 'operator:1');

        foreach ([IncidentStatus::Investigating, IncidentStatus::Containment, IncidentStatus::Remediation, IncidentStatus::Verification] as $next) {
            $this->incidents()->transition($incident, $next, 'operator:1');
            $this->assertSame($next, $incident->refresh()->status);
        }

        $this->verify($site);
        $this->incidents()->transition($incident->refresh(), IncidentStatus::Resolved, 'operator:1', 'All clean.', functionalCheck: true);
        $this->assertNotNull($incident->refresh()->resolved_at);
        $this->incidents()->transition($incident, IncidentStatus::Closed, 'operator:1');
        $this->assertNotNull($incident->refresh()->closed_at);

        $this->assertSame(['opened', 'status', 'status', 'status', 'status', 'status', 'status'], $incident->events()->where('type', '!=', 'scan')->orderBy('id')->pluck('type')->all(), 'Timeline: opened, then six status changes.');
        $this->assertSame(['security.incident_opened', 'security.incident_status', 'security.incident_status', 'security.incident_status', 'security.incident_status', 'security.incident_resolved', 'security.incident_closed'],
            WebsiteEvent::where('event', 'like', 'security.incident_%')->orderBy('id')->pluck('event')->all());
        $this->assertSame('operator', WebsiteEvent::where('event', 'security.incident_resolved')->sole()->actor_type);
    }

    /** @return array<string, array{0: IncidentStatus, 1: IncidentStatus}> */
    public static function invalidTransitions(): array
    {
        return [
            'open → remediation' => [IncidentStatus::Open, IncidentStatus::Remediation],
            'open → resolved' => [IncidentStatus::Open, IncidentStatus::Resolved],
            'investigating → resolved' => [IncidentStatus::Investigating, IncidentStatus::Resolved],
            'containment → resolved' => [IncidentStatus::Containment, IncidentStatus::Resolved],
            'remediation → closed' => [IncidentStatus::Remediation, IncidentStatus::Closed],
            'resolved → containment' => [IncidentStatus::Resolved, IncidentStatus::Containment],
            'resolved → remediation' => [IncidentStatus::Resolved, IncidentStatus::Remediation],
            'closed → remediation' => [IncidentStatus::Closed, IncidentStatus::Remediation],
            'closed → investigating' => [IncidentStatus::Closed, IncidentStatus::Investigating],
            'closed → open' => [IncidentStatus::Closed, IncidentStatus::Open],
        ];
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_transitions_are_refused(IncidentStatus $from, IncidentStatus $to): void
    {
        $site = $this->securitySite();
        $incident = $this->incidents()->open($site['website'], 'x', null, 'operator:1');
        $incident->forceFill(['status' => $from])->save();

        try {
            $this->incidents()->transition($incident, $to, 'operator:1', functionalCheck: true);
            $this->fail('Transition accepted');
        } catch (SecurityRefused $e) {
            $this->assertSame('INVALID_TRANSITION', $e->errorCode);
        }
        $this->assertSame($from, $incident->refresh()->status);
    }

    public function test_a_resolved_incident_can_only_be_reopened_into_investigation(): void
    {
        $site = $this->securitySite();
        $incident = $this->incidents()->open($site['website'], 'x', null, 'operator:1');
        $this->incidents()->transition($incident, IncidentStatus::Investigating, 'operator:1');
        $this->incidents()->transition($incident, IncidentStatus::Verification, 'operator:1');
        $this->verify($site);
        $this->incidents()->transition($incident->refresh(), IncidentStatus::Resolved, 'operator:1', functionalCheck: true);

        $this->incidents()->transition($incident->refresh(), IncidentStatus::Investigating, 'operator:1', 'It came back.');

        $this->assertSame(IncidentStatus::Investigating, $incident->refresh()->status);
        $this->assertNull($incident->resolved_at, 'Reopened: no longer resolved.');
    }

    public function test_resolving_needs_every_verification_step(): void
    {
        $site = $this->securitySite();
        $this->reportScan($site, $this->requestScan($site, ScanType::Malware), [$this->suspiciousFile('wp-content/uploads/x.php', 'high', 'high')]);
        $incident = SecurityIncident::sole(); // opened automatically for the high-confidence file
        $this->incidents()->transition($incident, IncidentStatus::Investigating, 'operator:1');
        $this->incidents()->transition($incident, IncidentStatus::Verification, 'operator:1');
        $resolve = fn (bool $functional = true) => $this->incidents()->transition($incident->refresh(), IncidentStatus::Resolved, 'operator:1', functionalCheck: $functional);
        $refused = function (string $code) use ($resolve) {
            try {
                $resolve();
                $this->fail("Resolved despite {$code}");
            } catch (SecurityRefused $e) {
                $this->assertSame($code, $e->errorCode);
            }
        };

        // 1. A scan after the incident (or its last remediation).
        $refused('RESCAN_REQUIRED');

        // 2. The high-confidence finding it is about must not still be open.
        $this->travel(1)->minutes();
        $this->reportScan($site, $this->requestScan($site, ScanType::Malware), [$this->suspiciousFile('wp-content/uploads/x.php', 'high', 'high')]);
        $refused('FINDINGS_STILL_OPEN');

        // 3. A health report after the change.
        $this->travel(1)->minutes();
        $this->reportScan($site, $this->requestScan($site, ScanType::Malware));
        $this->assertSame('resolved', SecurityFinding::sole()->status->value);
        $refused('HEALTH_CHECK_REQUIRED');

        // 4. The operator's functional check.
        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.heartbeat'), $this->heartbeatPayload(['connector_version' => '0.4.0']))->assertOk();
        try {
            $resolve(false);
            $this->fail('Resolved without the functional check');
        } catch (SecurityRefused $e) {
            $this->assertSame('FUNCTIONAL_CHECK_REQUIRED', $e->errorCode);
        }

        $resolve(true);
        $this->assertSame(IncidentStatus::Resolved, $incident->refresh()->status);
        $this->assertTrue($incident->events()->where('type', 'status')->get()->last()->data['functional_check']);
    }

    public function test_detection_opens_one_incident_and_starts_a_verified_backup_but_changes_nothing(): void
    {
        $site = $this->securitySite();

        // Weak or low-severity indicators do not open an incident.
        $this->reportScan($site, $this->requestScan($site, ScanType::Malware), [$this->suspiciousFile('wp-content/uploads/weak.php', 'medium', 'low')]);
        $this->assertSame(0, SecurityIncident::count());

        $this->reportScan($site, $this->requestScan($site, ScanType::Malware), [
            $this->suspiciousFile('wp-content/uploads/a.php', 'critical', 'high', 'PHP_REQUEST_EXECUTION'),
            $this->suspiciousFile('wp-content/uploads/b.php', 'high', 'high'),
        ]);

        $incident = SecurityIncident::sole();
        $this->assertSame(Severity::Critical, $incident->severity);
        $this->assertSame('system', $incident->actor_type);
        $this->assertSame(2, $incident->findings()->count());
        $backup = Backup::sole();
        $this->assertSame('recovery', $backup->reason->value);
        $this->assertSame('full', $backup->type->value);
        $this->assertSame($backup->uuid, $incident->events()->where('type', 'backup')->sole()->data['backup']);
        $this->assertSame(0, $site['website']->securityRemediations()->count(), 'Detection never remediates.');

        // A later scan adds to the open incident instead of opening another.
        $this->reportScan($site, $this->requestScan($site, ScanType::Malware), [$this->suspiciousFile('wp-content/uploads/c.php', 'high', 'high')]);
        $this->assertSame(1, SecurityIncident::count());
        $this->assertSame(3, $incident->findings()->count());
    }

    public function test_incidents_only_link_findings_of_their_own_website(): void
    {
        $mine = $this->securitySite('https://company.example');
        $other = $this->securitySite('https://other.example');
        $this->reportScan($other, $this->requestScan($other, ScanType::Malware), [$this->suspiciousFile()]);

        $incident = $this->incidents()->open($mine['website'], 'x', null, 'operator:1', SecurityFinding::all()->all());

        $this->assertSame(0, $incident->findings()->count());
    }

    public function test_investigation_uses_the_existing_admin_access_and_backup_services(): void
    {
        $site = $this->securitySite();
        $incident = $this->incidents()->open($site['website'], 'Investigation', null, 'operator:1');

        // Without admin_access.request: refused.
        $this->asOperator([Permission::SecurityView, Permission::SecurityManage]);
        $this->post(route('operator.websites.security.incidents.admin-access', [$site['website'], $incident]))->assertForbidden();

        $operator = $this->asOperator([Permission::SecurityView, Permission::AdminAccessRequest, Permission::AdminAccessFull, Permission::BackupsRun]);
        $this->post(route('operator.websites.security.incidents.admin-access', [$site['website'], $incident]))->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $access = AdminAccessRequest::sole();
        $this->assertSame('maintenance', $access->access_level->value, 'Never full admin from an incident.');
        $this->assertSame('operator', $access->actor_type->value);
        $this->assertSame($operator->id, $access->requested_by_user_id);
        $this->assertSame($access->uuid, $incident->events()->where('type', 'admin_access')->sole()->data['request']);

        // A verified full backup through the backup service, recorded on the incident.
        $this->post(route('operator.websites.security.incidents.backup', [$site['website'], $incident]))->assertRedirect();
        $backup = Backup::sole();
        $this->assertSame(['recovery', 'full', 'operator:'.$operator->id], [$backup->reason->value, $backup->type->value, $backup->requested_by]);
        $this->assertSame($backup->uuid, $incident->events()->where('type', 'backup')->sole()->data['backup']);
    }
}
