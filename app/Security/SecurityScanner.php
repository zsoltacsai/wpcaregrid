<?php

namespace App\Security;

use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where the operator console reads security findings from. The console
 * never decides what a finding is: the Security/Malware milestone provides
 * an implementation backed by real scan results. Until then
 * NotConfiguredScanner reports that no scanner exists, and nothing is invented.
 */
interface SecurityScanner
{
    /** Whether any scanner is set up at all. */
    public function isConfigured(): bool;

    /**
     * One summary per website, keyed by website ID (one query for a page of websites).
     *
     * @param  iterable<Website>  $websites
     * @return array<int, SecuritySummary>
     */
    public function summaries(iterable $websites): array;

    /** Open findings across all websites. */
    public function openFindings(): int;

    /**
     * Limit a website query to one security state, in the database.
     *
     * @param  Builder<Website>  $websites
     */
    public function whereState(Builder $websites, SecurityState $state): void;
}
