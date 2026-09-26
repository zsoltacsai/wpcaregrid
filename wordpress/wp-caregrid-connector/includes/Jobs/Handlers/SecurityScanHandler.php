<?php

namespace WPCareGrid\Connector\Jobs\Handlers;

use WPCareGrid\Connector\Jobs\InvalidPayload;
use WPCareGrid\Connector\Jobs\JobHandler;
use WPCareGrid\Connector\Jobs\JobResult;
use WPCareGrid\Connector\Security\Config\ConfigInspector;
use WPCareGrid\Connector\Security\Database\DatabaseInspector;
use WPCareGrid\Connector\Security\Integrity\IntegrityChecker;
use WPCareGrid\Connector\Security\Logger;
use WPCareGrid\Connector\Security\Malware\FileInspector;
use WPCareGrid\Connector\Security\Malware\RuleSet;
use WPCareGrid\Connector\Security\Scan\Deadline;
use WPCareGrid\Connector\Security\Scan\FileWalker;
use WPCareGrid\Connector\Security\Scan\ReportedFinding;
use WPCareGrid\Connector\Security\Scan\ScanState;

/**
 * SECURITY_SCAN: a read-only scan of this site, run here so that no file
 * contents or database values leave it. Only findings (codes, relative
 * paths, hashes, counts) are reported.
 *
 *   integrity  official checksums for core and WordPress.org plugins
 *   malware    files (rules), database, administrators, cron, configuration
 *   full       both
 *
 * A part stops at its time budget (or 80 % of memory_limit) and reports
 * "partial / SCAN_INCOMPLETE"; WP CareGrid then sends the next part, which
 * continues from the saved cursor. Nothing on the site is changed.
 */
final class SecurityScanHandler implements JobHandler
{
    private const PHASES = [
        'integrity' => ['core_integrity', 'plugin_integrity'],
        // The malware scan runs the checksum comparison too, silently: verified
        // official files are then skipped, which avoids most false positives.
        'malware' => ['core_integrity', 'plugin_integrity', 'files', 'database', 'users', 'cron', 'config'],
        'full' => ['core_integrity', 'plugin_integrity', 'files', 'database', 'users', 'cron', 'config'],
    ];

    /** Findings reported per part; a part with more stops early and continues. */
    private const MAX_FINDINGS = 400;

    public function capability(array $payload = []): string
    {
        return 'security_scan';
    }

    public function validate(array $payload): array
    {
        InvalidPayload::check($payload, ['scan_uuid', 'scan_type', 'part', 'time_budget'], ['scan_uuid', 'scan_type', 'part', 'time_budget']);

        if (! isset(self::PHASES[$payload['scan_type']])) {
            throw new InvalidPayload('Invalid scan type.');
        }

        $part = filter_var($payload['part'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
        $budget = filter_var($payload['time_budget'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 10, 'max_range' => 600]]);
        if ($part === false || $budget === false) {
            throw new InvalidPayload('Invalid scan part or time budget.');
        }

        return ['scan_uuid' => InvalidPayload::uuid((string) $payload['scan_uuid']), 'scan_type' => $payload['scan_type'], 'part' => $part, 'time_budget' => $budget];
    }

    public function handle(array $payload): JobResult
    {
        @set_time_limit($payload['time_budget'] + 60);
        $uuid = $payload['scan_uuid'];

        $state = $payload['part'] === 1 ? ScanState::start($uuid) : ScanState::load($uuid);
        if (! $state) {
            return JobResult::failed('SCAN_STATE_LOST', 'The scan cannot continue: its progress on this site was lost.');
        }

        $state->parts++;
        $deadline = new Deadline($payload['time_budget']);
        $report_integrity = in_array($payload['scan_type'], ['integrity', 'full'], true);
        $findings = [];
        $admins = null;
        $complete = true;

        foreach (self::PHASES[$payload['scan_type']] as $phase) {
            if (in_array($phase, $state->done, true)) {
                continue;
            }

            if ($deadline->reached() || count($findings) >= self::MAX_FINDINGS) {
                $complete = false;
                break;
            }

            try {
                $result = $this->run_phase($phase, $state, $deadline, $report_integrity);
            } catch (\Throwable $e) {
                // One failing phase does not stop the others; it is reported as failed.
                Logger::warning('security.phase_failed', ['scan' => $uuid, 'phase' => $phase, 'exception' => get_class($e)]);
                $state->phases[$phase] = 'failed';
                $state->done[] = $phase;

                continue;
            }

            if (isset($result['admins'])) {
                $admins = $result['admins'];
            }
            array_push($findings, ...$result['findings']);

            if (! $result['done']) {
                $complete = false;
                break;
            }

            $state->phases[$phase] ??= 'completed';
            $state->done[] = $phase;
        }

        $data = ['security' => array_filter([
            'scan_uuid' => $uuid,
            'part' => $payload['part'],
            'complete' => $complete,
            'scanner_version' => WP_CAREGRID_VERSION,
            'rules_version' => RuleSet::VERSION,
            'coverage' => $this->coverage($payload['scan_type'], $state, $complete),
            'findings' => array_map(fn (ReportedFinding $f) => $f->to_array(), $findings),
            'admins' => $admins,
        ], fn ($value) => $value !== null)];

        if ($complete) {
            ScanState::forget($uuid);
            Logger::info('security.scan_completed', ['scan' => $uuid, 'parts' => $state->parts, 'findings' => count($findings)]);

            return JobResult::success('SCAN_COMPLETED', $data);
        }

        $state->save();

        return JobResult::partial('SCAN_INCOMPLETE', 'The scan reached its time budget and continues in the next part.', $data);
    }

    /** @return array{findings: list<ReportedFinding>, done: bool, admins?: array} done=false: stopped at the deadline. */
    private function run_phase(string $phase, ScanState $state, Deadline $deadline, bool $report_integrity): array
    {
        return match ($phase) {
            'core_integrity' => (new IntegrityChecker($state, $deadline))->core($report_integrity),
            'plugin_integrity' => (new IntegrityChecker($state, $deadline))->plugins($report_integrity),
            'files' => $this->files($state, $deadline),
            'database' => ['findings' => [...(new DatabaseInspector)->options(), ...(new DatabaseInspector)->posts()], 'done' => true],
            'users' => ['done' => true] + (new DatabaseInspector)->administrators(),
            'cron' => ['findings' => (new DatabaseInspector)->cron(), 'done' => true],
            'config' => ['findings' => (new ConfigInspector)->inspect(), 'done' => true],
        };
    }

    /** @return array{findings: list<ReportedFinding>, done: bool} */
    private function files(ScanState $state, Deadline $deadline): array
    {
        $walker = new FileWalker;
        $inspector = new FileInspector;
        $start = (int) ($state->cursor['files'] ?? 0);
        $index = 0;
        $findings = [];

        foreach ($walker->files() as [$relative, $absolute]) {
            if ($index++ < $start) {
                continue;
            }

            if ($deadline->reached() || count($findings) >= self::MAX_FINDINGS) {
                $this->record_files($state, $walker, $inspector, $index - 1 - $start);
                $state->cursor['files'] = $index - 1;

                return ['findings' => $findings, 'done' => false];
            }

            // Official files matching their checksum are clean by definition.
            if (isset($state->verified[$relative])) {
                continue;
            }

            if ($finding = $inspector->inspect($relative, $absolute, $state->flagged[$relative] ?? null)) {
                $findings[] = $finding;
            }
        }

        $this->record_files($state, $walker, $inspector, $index - $start);
        $state->cursor['files'] = $index;

        return ['findings' => $findings, 'done' => true];
    }

    private function record_files(ScanState $state, FileWalker $walker, FileInspector $inspector, int $scanned): void
    {
        $state->count('malware', 'files_scanned', max(0, $scanned));
        $state->count('malware', 'bytes_scanned', $inspector->bytes);
        $state->count('malware', 'skipped', $inspector->too_large + array_sum($walker->skipped));
    }

    private function coverage(string $type, ScanState $state, bool $complete): array
    {
        $coverage = [];
        $counts = $state->counts;

        if (in_array($type, ['integrity', 'full'], true)) {
            $checked = ($counts['integrity']['verified'] ?? 0) + ($counts['integrity']['modified'] ?? 0) + ($counts['integrity']['missing'] ?? 0);
            $coverage['integrity'] = array_filter([
                'status' => match (true) {
                    ! $complete && ! in_array('plugin_integrity', $state->done, true) => 'partial',
                    $checked === 0 => 'unavailable',
                    default => 'completed',
                },
                'core' => $state->integrity['core'] ?? null,
                'verified' => $counts['integrity']['verified'] ?? 0,
                'modified' => $counts['integrity']['modified'] ?? 0,
                'missing' => $counts['integrity']['missing'] ?? 0,
                'extra' => $counts['integrity']['extra'] ?? 0,
                'unavailable' => $counts['integrity']['unavailable'] ?? 0,
            ], fn ($v) => $v !== null);
        }

        if (in_array($type, ['malware', 'full'], true)) {
            $coverage['malware'] = [
                'status' => $complete ? (in_array('failed', array_intersect_key($state->phases, array_flip(['files'])), true) ? 'failed' : 'completed') : 'partial',
                'files_scanned' => $counts['malware']['files_scanned'] ?? 0,
                'bytes_scanned' => $counts['malware']['bytes_scanned'] ?? 0,
                'skipped' => $counts['malware']['skipped'] ?? 0,
                'database' => in_array('database', $state->done, true) ? ($state->phases['database'] ?? 'completed') : null,
            ];
            $coverage['malware'] = array_filter($coverage['malware'], fn ($v) => $v !== null);
        }

        return $coverage;
    }
}
