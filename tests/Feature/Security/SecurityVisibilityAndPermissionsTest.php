<?php

namespace Tests\Feature\Security;

use App\Connector\HealthStatus;
use App\Models\SecurityFinding;
use App\Models\SecurityIncident;
use App\Models\SecurityIncidentEvent;
use App\Models\SecurityQuarantine;
use App\Models\SecurityRemediation;
use App\Models\SecurityScan;
use App\Models\WebsiteEvent;
use App\Operator\Permission;
use App\Security\FindingDecisions;
use App\Security\FindingStatus;
use App\Security\IncidentService;
use App\Security\ScanType;
use App\Security\Vulnerabilities\WordfenceFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RunsSecurityScans;
use Tests\TestCase;

/**
 * Who sees what: customers get the state and an actionable summary only;
 * operators need security.view / scan / manage / remediate for each part.
 * Also the finding state machine through the console, integrity semantics
 * and the vulnerability source wording.
 */
class SecurityVisibilityAndPermissionsTest extends TestCase
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
        File::deleteDirectory($this->storageRoot.'-feed');
        $this->tearDownBackups();
        parent::tearDown();
    }

    /** A site with a vulnerability, a confirmed suspicious file, an operator note, an incident and a remediation. */
    private function busySite(string $url = 'https://company.example'): array
    {
        File::ensureDirectoryExists($this->storageRoot.'-feed');
        $feed = $this->storageRoot.'-feed/v.json';
        file_put_contents($feed.'.download', json_encode(['wf-1' => ['id' => 'wf-1', 'title' => 'Akismet <= 5.3 - Stored XSS', 'cvss' => ['score' => 6.4, 'rating' => 'Medium'], 'cve' => 'CVE-2026-10001',
            'software' => [['type' => 'plugin', 'slug' => 'akismet', 'affected_versions' => ['* - 5.3' => ['from_version' => '*', 'from_inclusive' => true, 'to_version' => '5.3', 'to_inclusive' => true]], 'patched_versions' => ['5.3.1']]]]]));
        WordfenceFeed::import($feed.'.download', WordfenceFeed::indexPathFor($feed));
        config(['security.vulnerabilities.source' => 'wordfence', 'security.vulnerabilities.feed_path' => $feed]);

        $site = $this->securitySite($url);
        $scan = $this->requestScan($site, ScanType::Full);
        $this->reportScan($site, $scan, [$this->suspiciousFile('wp-content/uploads/2026/secret-shell.php', 'high', 'medium', 'PHP_REQUEST_EXECUTION', str_repeat('f', 64))]);
        $file = SecurityFinding::where('type', 'suspicious_file')->sole();
        app(FindingDecisions::class)->decide($file, FindingStatus::Confirmed, $this->operator([Permission::SecurityManage]), 'OPERATOR-NOTE-customer-must-not-see');
        $incident = app(IncidentService::class)->open($site['website'], 'INCIDENT-TITLE-internal', null, 'operator:1', [$file]);
        app(IncidentService::class)->note($incident, 'operator:1', 'INCIDENT-NOTE-internal');

        return $site;
    }

    /* ----- Customers ----- */

    public function test_customers_see_the_state_and_an_actionable_summary_only(): void
    {
        $site = $this->busySite();
        $website = $site['website']->refresh();

        $response = $this->actingAs($website->customer->user)->get(route('account.websites.show', $website))->assertOk()
            ->assertSee(__('security.customer.title'))
            ->assertSee(__('security.states.critical'))
            ->assertSee(__('security.customer.states.critical'))
            ->assertSee(trans_choice('security.customer.issues', 2, ['count' => 2]))
            ->assertSee(__('security.customer.types.suspicious_file'))
            ->assertSee(__('security.customer.types.vulnerability'))
            ->assertSee('Akismet 5.3: update to 5.3.1')
            ->assertSee(__('security.customer.disclaimer'));

        foreach ([
            'wp-content/uploads/2026/secret-shell.php', 'secret-shell', str_repeat('f', 64), 'PHP_REQUEST_EXECUTION', 'UPLOADS_EXECUTABLE_FILE',
            'OPERATOR-NOTE-customer-must-not-see', 'INCIDENT-TITLE-internal', 'INCIDENT-NOTE-internal', 'CVE-2026-10001',
            'rules_version', '2026.10.1', $site['credential'], 'confidence', 'Stored XSS',
        ] as $hidden) {
            $response->assertDontSee($hidden, false);
        }
        $this->assertStringNotContainsString('100% secure', $response->getContent());
    }

    public function test_a_customer_without_scans_is_told_it_is_not_set_up_rather_than_healthy(): void
    {
        $site = $this->securitySite();

        $this->actingAs($site['website']->customer->user)->get(route('account.websites.show', $site['website']))->assertOk()
            ->assertSee(__('security.states.unknown'))
            ->assertSee(__('security.customer.not_scanned'))
            ->assertDontSee(__('security.states.healthy'));
    }

    public function test_customers_can_never_reach_security_actions_or_other_customers_findings(): void
    {
        $site = $this->busySite();
        $other = $this->securitySite('https://other.example');
        $customer = $other['website']->customer->user;
        $finding = SecurityFinding::where('type', 'suspicious_file')->sole();

        // Another customer's website: forbidden.
        $this->actingAs($customer)->get(route('account.websites.show', $site['website']))->assertForbidden();

        // The operator console does not exist for customers.
        foreach ([
            ['get', route('operator.websites.security', $site['website'])],
            ['post', route('operator.websites.security.scans.store', $site['website']), ['type' => 'full']],
            ['post', route('operator.websites.security.findings.status', [$site['website'], $finding]), ['status' => 'false_positive']],
            ['post', route('operator.websites.security.findings.remediate', [$site['website'], $finding]), ['type' => 'quarantine_file', 'confirm' => '1']],
        ] as $call) {
            [$method, $url] = $call;
            $this->actingAs($site['website']->customer->user)->{$method}($url, $call[2] ?? [])->assertRedirect(route('operator.login'));
        }

        $this->assertSame(FindingStatus::Confirmed, $finding->refresh()->status);
        $this->assertSame(0, SecurityRemediation::count());
        $this->assertSame(1, SecurityScan::count());
    }

    /* ----- Operator permissions ----- */

    /** @return array<string, array{0: string, 1: Permission, 2: string}> */
    public static function securityRoutes(): array
    {
        return [
            'security page' => ['security_page', Permission::SecurityView, 'get'],
            'overview' => ['overview', Permission::SecurityView, 'get'],
            'incident page' => ['incident_page', Permission::SecurityView, 'get'],
            'run scan' => ['scan', Permission::SecurityScan, 'post'],
            'cancel scan' => ['cancel', Permission::SecurityScan, 'post'],
            'finding decision' => ['decide', Permission::SecurityManage, 'post'],
            'open incident' => ['incident', Permission::SecurityManage, 'post'],
            'incident status' => ['incident_status', Permission::SecurityManage, 'post'],
            'incident note' => ['incident_note', Permission::SecurityManage, 'post'],
            'remediate' => ['remediate', Permission::SecurityRemediate, 'post'],
            'undo quarantine' => ['undo', Permission::SecurityRemediate, 'post'],
        ];
    }

    #[DataProvider('securityRoutes')]
    public function test_each_security_action_needs_its_own_permission(string $action, Permission $permission, string $method): void
    {
        $site = $this->busySite();
        $website = $site['website'];
        $finding = SecurityFinding::where('type', 'suspicious_file')->sole();
        $incident = SecurityIncident::sole();
        $running = $this->requestScan($this->securitySite('https://running.example'), ScanType::Malware);
        $quarantine = SecurityQuarantine::create(['website_id' => $website->id, 'finding_id' => $finding->id, 'original_path' => $finding->path, 'sha256' => str_repeat('f', 64), 'reason' => 'test', 'status' => 'quarantined', 'quarantined_at' => now()]);

        [$url, $data] = match ($action) {
            'security_page' => [route('operator.websites.security', $website), []],
            'overview' => [route('operator.security.index'), []],
            'incident_page' => [route('operator.websites.security.incidents.show', [$website, $incident]), []],
            'scan' => [route('operator.websites.security.scans.store', $website), ['type' => 'malware']],
            'cancel' => [route('operator.websites.security.scans.cancel', [$running->website, $running]), []],
            'decide' => [route('operator.websites.security.findings.status', [$website, $finding]), ['status' => 'false_positive']],
            'incident' => [route('operator.websites.security.incidents.store', $website), ['title' => 'New']],
            'incident_status' => [route('operator.websites.security.incidents.status', [$website, $incident]), ['status' => 'investigating']],
            'incident_note' => [route('operator.websites.security.incidents.notes', [$website, $incident]), ['note' => 'x']],
            'remediate' => [route('operator.websites.security.findings.remediate', [$website, $finding]), ['type' => 'quarantine_file', 'confirm' => '1']],
            'undo' => [route('operator.websites.security.quarantines.restore', [$website, $quarantine]), ['confirm' => '1']],
        };

        $snapshot = fn () => [SecurityScan::count(), SecurityScan::where('status', 'cancelled')->count(), $finding->refresh()->status, SecurityIncident::count(), $incident->refresh()->status, $incident->events()->count(), SecurityRemediation::count()];
        $before = $snapshot();

        // Every other permission, but not this one: refused, and nothing changed.
        $this->actingAs($this->operator(array_values(array_filter(Permission::cases(), fn ($p) => $p !== $permission))), 'operator')
            ->{$method}($url, $data)->assertForbidden();
        $this->assertSame($before, $snapshot());

        // With only this permission (plus viewing): not refused by authorization.
        $response = $this->actingAs($this->operator(array_values(array_unique([$permission, Permission::SecurityView, Permission::WebsitesView], SORT_REGULAR))), 'operator')->{$method}($url, $data);
        $this->assertNotSame(403, $response->status(), $action);
    }

    /* ----- Finding state machine through the console ----- */

    /** @return array<string, array{0: FindingStatus, 1: FindingStatus, 2: bool}> */
    public static function findingTransitions(): array
    {
        return [
            'open → confirmed' => [FindingStatus::Open, FindingStatus::Confirmed, true],
            'open → false positive' => [FindingStatus::Open, FindingStatus::FalsePositive, true],
            'open → ignored' => [FindingStatus::Open, FindingStatus::Ignored, true],
            'confirmed → open' => [FindingStatus::Confirmed, FindingStatus::Open, true],
            'false positive → open' => [FindingStatus::FalsePositive, FindingStatus::Open, true],
            'ignored → confirmed' => [FindingStatus::Ignored, FindingStatus::Confirmed, false],
            'open → quarantined' => [FindingStatus::Open, FindingStatus::Quarantined, false],
            'open → remediated' => [FindingStatus::Open, FindingStatus::Remediated, false],
            'confirmed → remediated' => [FindingStatus::Confirmed, FindingStatus::Remediated, false],
            'open → resolved' => [FindingStatus::Open, FindingStatus::Resolved, false],
            'quarantined → open' => [FindingStatus::Quarantined, FindingStatus::Open, false],
            'remediated → confirmed' => [FindingStatus::Remediated, FindingStatus::Confirmed, false],
            'resolved → false positive' => [FindingStatus::Resolved, FindingStatus::FalsePositive, false],
        ];
    }

    #[DataProvider('findingTransitions')]
    public function test_finding_transitions_through_controller_input(FindingStatus $from, FindingStatus $to, bool $allowed): void
    {
        $site = $this->securitySite();
        $this->reportScan($site, $this->requestScan($site, ScanType::Malware), [$this->suspiciousFile()]);
        $finding = SecurityFinding::sole();
        $finding->forceFill(['status' => $from])->save();
        $this->asOperator([Permission::SecurityView, Permission::SecurityManage]);

        $response = $this->post(route('operator.websites.security.findings.status', [$site['website'], $finding]), ['status' => $to->value]);

        if ($allowed) {
            $response->assertSessionHas('status');
            $this->assertSame($to, $finding->refresh()->status);
        } else {
            $this->assertTrue(session()->has('operator_error') || session()->has('errors'), "{$from->value} → {$to->value} was not refused");
            $this->assertSame($from, $finding->refresh()->status);
        }
    }

    public function test_a_heuristic_finding_is_never_confirmed_except_by_an_authorized_operator(): void
    {
        $site = $this->securitySite();
        $this->reportScan($site, $this->requestScan($site, ScanType::Malware), [$this->suspiciousFile(severity: 'critical', confidence: 'high', code: 'WEBSHELL_MARKER')]);
        $finding = SecurityFinding::sole();

        $this->assertSame(FindingStatus::Open, $finding->status);
        $this->assertSame('high', $finding->confidence->value);
        $this->assertFalse($finding->isConfirmedMalware());

        $this->asOperator([Permission::SecurityView, Permission::SecurityScan]);
        $this->post(route('operator.websites.security.findings.status', [$site['website'], $finding]), ['status' => 'confirmed'])->assertForbidden();
        $this->assertFalse($finding->refresh()->isConfirmedMalware());

        $manager = $this->asOperator([Permission::SecurityView, Permission::SecurityManage]);
        $this->post(route('operator.websites.security.findings.status', [$site['website'], $finding]), ['status' => 'confirmed'])->assertSessionHas('status');
        $this->assertTrue($finding->refresh()->isConfirmedMalware());
        $this->assertSame($manager->id, WebsiteEvent::where('event', 'security.finding_confirmed')->sole()->actor_user_id);
    }

    /* ----- Integrity and vulnerability semantics ----- */

    public function test_a_modified_file_is_an_integrity_finding_not_malware(): void
    {
        $site = $this->securitySite();

        $this->reportScan($site, $this->requestScan($site, ScanType::Integrity), [$this->integrityFinding()]);

        $finding = SecurityFinding::sole();
        $this->assertSame('integrity', $finding->type->value);
        $this->assertFalse($finding->isConfirmedMalware());
        $this->assertSame(0, SecurityIncident::count(), 'An integrity change alone opens no malware incident.');
        $this->assertSame(HealthStatus::Attention, $site['website']->refresh()->security_status, 'Attention, not critical.');
    }

    public function test_the_operator_is_told_when_vulnerability_scanning_is_not_configured(): void
    {
        $site = $this->securitySite();
        $this->reportScan($site, $this->requestScan($site, ScanType::Full));
        $this->asOperator();

        $this->get(route('operator.security.index'))->assertOk()->assertSee(__('security.unavailable_reasons.VULNERABILITY_SOURCE_NOT_CONFIGURED'))->assertDontSee('0 vulnerabilities');
        $this->get(route('operator.websites.security', $site['website']))->assertOk()->assertSee(__('security.unavailable_reasons.VULNERABILITY_SOURCE_NOT_CONFIGURED'));
        $this->actingAs($site['website']->customer->user)->get(route('account.websites.show', $site['website']))->assertOk()->assertSee(__('security.customer.not_configured'));
    }

    public function test_finding_titles_are_rendered_as_escaped_text(): void
    {
        $site = $this->busySite();
        $this->asOperator();

        $this->get(route('operator.websites.security', $site['website']))->assertOk()
            ->assertSee('Akismet <= 5.3 - Stored XSS')                 // escaped by assertSee
            ->assertSee('Akismet &lt;= 5.3 - Stored XSS', false);    // exactly as sent to the browser

        $this->reportScan($site, $this->requestScan($site, ScanType::Malware), [['title' => '<img src=x onerror=alert(1)>'] + $this->suspiciousFile('wp-content/uploads/2026/secret-shell.php', 'high', 'medium', 'PHP_REQUEST_EXECUTION', str_repeat('f', 64))]);
        $this->get(route('operator.websites.security', $site['website']))->assertOk()
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false);
    }

    public function test_operator_foreign_security_objects_are_rejected_before_mutation(): void
    {
        $mine = $this->busySite('https://operator-mine.example');
        $other = $this->busySite('https://operator-foreign.example');
        $foreignFinding = SecurityFinding::query()->where('website_id', $other['website']->id)->firstOrFail();
        $foreignIncident = SecurityIncident::query()->where('website_id', $other['website']->id)->oldest('id')->firstOrFail();
        $this->asOperator(Permission::cases());

        $before = [
            'findings' => SecurityFinding::count(),
            'incidents' => SecurityIncident::count(),
            'events' => SecurityIncidentEvent::count(),
            'remediations' => SecurityRemediation::count(),
            'scans' => SecurityScan::count(),
            'foreign_finding_status' => $foreignFinding->status,
            'foreign_incident_status' => $foreignIncident->status,
        ];

        $attempts = [
            fn () => $this->get(route('operator.websites.security.incidents.show', [$mine['website'], $foreignIncident]))->assertNotFound(),
            fn () => $this->post(route('operator.websites.security.incidents.notes', [$mine['website'], $foreignIncident]), ['note' => 'cross tenant'])->assertNotFound(),
            fn () => $this->post(route('operator.websites.security.findings.status', [$mine['website'], $foreignFinding]), ['status' => 'confirmed'])->assertNotFound(),
            fn () => $this->post(route('operator.websites.security.findings.remediate', [$mine['website'], $foreignFinding]), ['confirmation' => 'operator-foreign.example'])->assertNotFound(),
            fn () => $this->post(route('operator.websites.security.incidents.admin-access', [$mine['website'], $foreignIncident]))->assertNotFound(),
        ];

        foreach ($attempts as $attempt) {
            $attempt();
        }

        $this->assertCount(5, $attempts);
        $this->assertSame($before['findings'], SecurityFinding::count());
        $this->assertSame($before['incidents'], SecurityIncident::count());
        $this->assertSame($before['events'], SecurityIncidentEvent::count());
        $this->assertSame($before['remediations'], SecurityRemediation::count());
        $this->assertSame($before['scans'], SecurityScan::count());
        $this->assertSame($before['foreign_finding_status'], $foreignFinding->fresh()->status);
        $this->assertSame($before['foreign_incident_status'], $foreignIncident->fresh()->status);
    }
}
