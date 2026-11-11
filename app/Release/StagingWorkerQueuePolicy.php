<?php

namespace App\Release;

/**
 * Queue monitoring policy for staging-next. The fixed allowlist mirrors the
 * intentionally active default worker; production and legacy staging are unchanged.
 */
final class StagingWorkerQueuePolicy
{
    public const ACTIVE_STAGING_NEXT_QUEUE = 'default';

    public const PAUSED_STAGING_NEXT_QUEUE = 'backups';

    public static function isStagingNext(): bool
    {
        return config('app.deployment_environment') === StagingActiveProfile::DEPLOYMENT_ENV;
    }

    /**
     * @param  array<string, mixed>  $configured
     * @return array<string, array<string, mixed>>
     */
    public static function monitorableWorkerQueues(array $configured): array
    {
        if (! self::isStagingNext()) {
            return $configured;
        }

        if (! self::hasValidActiveQueueConfiguration($configured)) {
            return [];
        }

        return [self::ACTIVE_STAGING_NEXT_QUEUE => $configured[self::ACTIVE_STAGING_NEXT_QUEUE]];
    }

    /**
     * @param  array<string, mixed>  $configured
     * @return list<string>
     */
    public static function inactiveWorkerQueueNames(array $configured): array
    {
        if (! self::isStagingNext()) {
            return [];
        }

        return self::hasValidPausedBackupsConfiguration($configured)
            ? [self::PAUSED_STAGING_NEXT_QUEUE]
            : [];
    }

    /**
     * @param  array<string, mixed>  $configured
     * @return array<string, string> Sanitized queue subject IDs mapped to fixed issue codes.
     */
    public static function queueConfigurationIssues(array $configured): array
    {
        if (! self::isStagingNext()) {
            return [];
        }

        $issues = [];
        if (! self::hasValidActiveQueueConfiguration($configured)) {
            $issues[self::ACTIVE_STAGING_NEXT_QUEUE] = 'invalid_active_queue_configuration';
        }

        if (! self::hasValidPausedBackupsConfiguration($configured)) {
            $issues[self::PAUSED_STAGING_NEXT_QUEUE] = 'invalid_paused_queue_configuration';
        }

        foreach (array_keys($configured) as $name) {
            $name = (string) $name;
            if (in_array($name, [self::ACTIVE_STAGING_NEXT_QUEUE, self::PAUSED_STAGING_NEXT_QUEUE], true)) {
                continue;
            }

            // Do not expose arbitrary queue names in health output.
            $issues['unrecognized-'.substr(hash('sha256', $name), 0, 16)] = 'unknown_worker_queue';
        }

        return $issues;
    }

    /**
     * The database queue must resolve through Laravel's application DB connection,
     * because queue health reads that same connection via the DB facade.
     */
    private static function usesApplicationDatabase(string $queueConnection): bool
    {
        $applicationConnection = config('database.default');
        $queueDriver = config("queue.connections.{$queueConnection}.driver");
        $queueDatabaseConnection = config("queue.connections.{$queueConnection}.connection");
        if ($queueDriver !== 'database' || ! is_string($applicationConnection) || $applicationConnection === '') {
            return false;
        }

        return $queueDatabaseConnection === null
            ? true
            : is_string($queueDatabaseConnection)
                && $queueDatabaseConnection !== ''
                && $queueDatabaseConnection === $applicationConnection;
    }

    private static function hasValidActiveQueueConfiguration(array $configured): bool
    {
        $settings = $configured[self::ACTIVE_STAGING_NEXT_QUEUE] ?? null;
        $connection = config('queue.default');

        return is_array($settings)
            && is_string($connection)
            && $connection !== ''
            && ($settings['queue'] ?? null) === self::ACTIVE_STAGING_NEXT_QUEUE
            && ($settings['connection'] ?? null) === $connection
            && config("queue.connections.{$connection}.driver") === 'database'
            && config("queue.connections.{$connection}.table", 'jobs') === 'jobs'
            && config("queue.connections.{$connection}.queue") === self::ACTIVE_STAGING_NEXT_QUEUE
            && self::usesApplicationDatabase($connection);
    }

    /**
     * The backups queue is considered intentionally paused only when its complete
     * queue and backup-transfer contract matches the staging-next profile.
     *
     * @param  array<string, mixed>  $configured
     */
    private static function hasValidPausedBackupsConfiguration(array $configured): bool
    {
        $settings = $configured[self::PAUSED_STAGING_NEXT_QUEUE] ?? null;
        $retryAfter = config('queue.connections.backups.retry_after');
        $timeout = config('backup.transfer.timeout');

        return is_array($settings)
            && ($settings['queue'] ?? null) === self::PAUSED_STAGING_NEXT_QUEUE
            && ($settings['connection'] ?? null) === self::PAUSED_STAGING_NEXT_QUEUE
            && config('queue.connections.backups.driver') === 'database'
            && config('queue.connections.backups.table', 'jobs') === 'jobs'
            && config('queue.connections.backups.queue') === self::PAUSED_STAGING_NEXT_QUEUE
            && config('backup.transfer.connection') === self::PAUSED_STAGING_NEXT_QUEUE
            && config('backup.transfer.queue') === self::PAUSED_STAGING_NEXT_QUEUE
            && is_numeric($retryAfter)
            && is_numeric($timeout)
            && (int) $retryAfter > (int) $timeout
            && self::usesApplicationDatabase(self::PAUSED_STAGING_NEXT_QUEUE);
    }

    public static function activeQueueName(string $connection): ?string
    {
        $configured = (array) config('monitoring.worker_queues', []);
        if (! self::isStagingNext()
            || $connection !== (string) config('queue.default')
            || ! self::hasValidActiveQueueConfiguration($configured)
            || ($configured[self::ACTIVE_STAGING_NEXT_QUEUE]['connection'] ?? null) !== $connection) {
            return null;
        }

        return self::ACTIVE_STAGING_NEXT_QUEUE;
    }
}
