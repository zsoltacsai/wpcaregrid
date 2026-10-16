<?php

namespace Tests\Concerns;

use App\Compatibility\CapabilitySnapshotStore;
use App\Models\SecurityScan;
use App\Models\WebsiteJob;
use App\Security\ScanType;
use App\Security\SecurityScanService;
use Illuminate\Testing\TestResponse;

/**
 * Security scans through the real connector API: the site polls, gets the
 * SECURITY_SCAN part, and reports findings as connector 0.4.0 does.
 */
trait RunsSecurityScans
{
    use OperatesConsole;

    /** A console site whose connector also advertises the security capabilities. */
    protected function securitySite(string $url = 'https://company.example'): array
    {
        $site = $this->consoleSite($url);
        $site['website']->activeConnector->update(['capabilities' => [...$site['website']->activeConnector->capabilities, 'security_scan', 'security_quarantine', 'security_remediate']]);
        app(CapabilitySnapshotStore::class)->refresh($site['website']->fresh(), $site['website']->activeConnector()->first(), $this->inventoryPayload()['compatibility']);

        return ['website' => $site['website']->refresh(), 'credential' => $site['credential']];
    }

    protected function requestScan(array $site, ScanType $type = ScanType::Full, string $actor = 'operator:1'): SecurityScan
    {
        return app(SecurityScanService::class)->request($site['website']->refresh(), $type, $actor);
    }

    /** Deliver the scan's current part and report it. */
    protected function reportScan(array $site, SecurityScan $scan, array $findings = [], bool $complete = true, array $overrides = [], ?array $admins = null): TestResponse
    {
        $delivered = collect($this->pollJobs($site))->firstWhere('type', 'SECURITY_SCAN');
        $this->assertNotNull($delivered, 'No SECURITY_SCAN part was delivered.');
        $job = WebsiteJob::where('uuid', $delivered['uuid'])->sole();

        $security = array_replace([
            'scan_uuid' => $scan->uuid,
            'part' => $delivered['payload']['part'],
            'complete' => $complete,
            'scanner_version' => '0.4.0',
            'rules_version' => '2026.10.1',
            'coverage' => $this->coverage($scan->refresh()->type, $complete),
            'findings' => $findings,
        ], $overrides);
        if ($admins !== null) {
            $security['admins'] = $admins;
        }

        return $this->reportJob($site, $job, $complete ? 'success' : 'partial', $complete ? 'SCAN_COMPLETED' : 'SCAN_INCOMPLETE', ['security' => $security]);
    }

    protected function coverage(ScanType $type, bool $complete = true): array
    {
        $status = $complete ? 'completed' : 'partial';

        return array_filter([
            'integrity' => in_array('integrity', $type->scanners(), true) ? ['status' => $status, 'core' => 'verified', 'verified' => 3000, 'modified' => 0, 'missing' => 0, 'extra' => 0, 'unavailable' => 2] : null,
            'malware' => in_array('malware', $type->scanners(), true) ? ['status' => $status, 'files_scanned' => 4200, 'bytes_scanned' => 52_000_000, 'skipped' => 0, 'database' => 'completed'] : null,
        ]);
    }

    protected function suspiciousFile(string $path = 'wp-content/uploads/2026/cache.php', string $severity = 'high', string $confidence = 'medium', string $code = 'UPLOADS_EXECUTABLE_FILE', ?string $sha = null): array
    {
        return [
            'type' => 'suspicious_file', 'code' => $code, 'severity' => $severity, 'confidence' => $confidence,
            'title' => 'Suspicious file: PHP file in the uploads folder', 'component' => 'uploads', 'path' => $path,
            'evidence' => ['rules' => [$code], 'sha256' => $sha ?? hash('sha256', $path), 'size' => 120, 'lines' => [1]],
        ];
    }

    protected function integrityFinding(string $path = 'wp-includes/version.php', string $code = 'CORE_FILE_MODIFIED'): array
    {
        return [
            'type' => 'integrity', 'code' => $code, 'severity' => 'high', 'confidence' => 'high',
            'title' => 'WordPress core file differs from the official version', 'component' => 'core', 'path' => $path,
            'evidence' => ['integrity' => 'modified', 'version' => '7.1.2', 'sha256' => hash('sha256', 'modified '.$path)],
        ];
    }
}
