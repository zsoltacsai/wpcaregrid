<?php

namespace App\Connector;

use App\Models\Website;
use App\Models\WebsiteJob;
use Illuminate\Validation\ValidationException;

/**
 * Asks a site to install one update WordPress offers it. Only updates the
 * site's own inventory lists as available can be requested, one at a time
 * per target. The job goes through JobQueue, so authorisation, capability
 * and the pre-update backup (PreUpdateBackups) apply as for any update.
 */
final class RequestUpdate
{
    public const KINDS = ['plugin', 'theme', 'core'];

    public function __construct(private readonly JobQueue $jobs) {}

    /** @throws UpdateRefused */
    public function __invoke(Website $website, string $kind, ?string $identifier, string $actor): WebsiteJob
    {
        $inventory = $website->inventory;
        $connector = $website->activeConnector;

        [$type, $payload] = match ($kind) {
            'plugin' => [JobType::UpdatePlugin, ['plugin' => (string) $identifier]],
            'theme' => [JobType::UpdateTheme, ['stylesheet' => (string) $identifier]],
            'core' => [JobType::UpdateCore, ['policy' => $this->corePolicy($inventory?->core ?? [])]],
            default => throw new UpdateRefused('INVALID_UPDATE'),
        };

        $available = match ($kind) {
            'plugin' => collect($inventory?->plugins)->contains(fn (array $p) => $p['file'] === $identifier && $p['update_available'] === true),
            'theme' => collect($inventory?->themes)->contains(fn (array $t) => $t['stylesheet'] === $identifier && $t['update_available'] === true),
            'core' => ($inventory?->core['update_available'] ?? null) === true,
        };

        $refusal = match (true) {
            $connector === null => 'NOT_CONNECTED',
            ! $website->allowsServiceOperations() => 'SUBSCRIPTION_INACTIVE',
            ! $connector->can(Capability::Updates) => 'CONNECTOR_OUTDATED',
            $inventory === null => 'NO_INVENTORY',
            ! $available => 'NO_UPDATE_AVAILABLE',
            $this->inFlight($website, $type, $payload) => 'UPDATE_IN_PROGRESS',
            default => null,
        };

        if ($refusal) {
            throw new UpdateRefused($refusal);
        }

        try {
            return $this->jobs->queue($website, $type, $payload, substr($actor, 0, 32));
        } catch (ConnectorError) {
            throw new UpdateRefused('JOB_NOT_ALLOWED');
        } catch (ValidationException) {
            throw new UpdateRefused($type === JobType::UpdateCore ? 'MAJOR_CORE_UPDATE_NOT_ALLOWED' : 'INVALID_UPDATE');
        }
    }

    /** An update for the same target that has not finished yet. */
    private function inFlight(Website $website, JobType $type, array $payload): bool
    {
        return $website->jobs()->where('type', $type)->whereIn('status', [JobStatus::Queued, JobStatus::Waiting, JobStatus::Delivered])->get()
            ->contains(fn (WebsiteJob $job) => JobQueue::updateTarget($job->payload ?? []) === JobQueue::updateTarget($payload));
    }

    /** "7.1.2" → "7.1.3" is minor; "7.1" → "7.2" is a major WordPress release. */
    private function corePolicy(array $core): string
    {
        $branch = fn (?string $version) => implode('.', array_slice(explode('.', (string) $version), 0, 2));

        return isset($core['latest_version']) && $branch($core['latest_version']) !== $branch($core['version'] ?? null) ? 'major' : 'minor';
    }
}
