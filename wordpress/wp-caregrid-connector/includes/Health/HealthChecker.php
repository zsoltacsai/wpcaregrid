<?php

namespace WPCareGrid\Connector\Health;

/**
 * Runs the health checks and summarises them without hiding the findings.
 *
 *   critical   a critical check failed (home page error, broken theme,
 *              plugins paused after a fatal error)
 *   attention  any other failure or warning
 *   healthy    at least one check passed and nothing failed
 *   unknown    nothing could be verified
 *
 * A single loopback failure caused by the host is "unknown", not critical.
 */
final class HealthChecker
{
    private const OPTION = 'wp_caregrid_health';

    /** @return Check[] */
    public function checks(): array
    {
        $checks = [
            new Checks\HomepageCheck,
            new Checks\RestApiCheck,
            new Checks\ActiveThemeCheck,
            new Checks\PluginLoadingCheck,
            new Checks\SiteHealthStatusCheck,
            new Checks\PhpVersionCheck,
            new Checks\DebugDisplayCheck,
        ];

        return array_values(array_filter($checks, fn ($check) => $check instanceof Check));
    }

    /** @return array{status: string, findings: array, checked_at: string} */
    public function run(): array
    {
        $findings = [];

        foreach ($this->checks() as $check) {
            try {
                $findings[] = $check->run();
            } catch (\Throwable $e) {
                $findings[] = new Finding('check_error', Finding::UNKNOWN, get_class($check), 'The check could not run.');
            }
        }

        $report = [
            'status' => self::summarise($findings),
            'findings' => array_map(fn (Finding $f) => $f->to_array(), $findings),
            'checked_at' => gmdate('c'),
        ];

        update_option(self::OPTION, $report, false);

        return $report;
    }

    /** The most recent report, for the heartbeat (checks are not re-run every heartbeat). */
    public function last(): ?array
    {
        $report = get_option(self::OPTION);

        return is_array($report) ? $report : null;
    }

    /** @param Finding[] $findings */
    public static function summarise(array $findings): string
    {
        $statuses = array_map(fn (Finding $f) => $f->status, $findings);

        foreach ($findings as $finding) {
            if ($finding->status === Finding::FAIL && $finding->critical) {
                return 'critical';
            }
        }

        if (array_intersect([Finding::FAIL, Finding::WARNING], $statuses)) {
            return 'attention';
        }

        return in_array(Finding::PASS, $statuses, true) ? 'healthy' : 'unknown';
    }
}
