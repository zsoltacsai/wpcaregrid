<?php

namespace App\Release;

/**
 * Queue monitoring policy for staging-next. The fixed allowlist mirrors the
 * intentionally active default worker; production and legacy staging are unchanged.
 */
final class StagingWorkerQueuePolicy
{
    public const ACTIVE_STAGING_NEXT_QUEUE = 'default';

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

        $settings = $configured[self::ACTIVE_STAGING_NEXT_QUEUE] ?? null;
        $connection = (string) config('queue.default');
        if (! is_array($settings)
            || ($settings['queue'] ?? null) !== self::ACTIVE_STAGING_NEXT_QUEUE
            || ($settings['connection'] ?? null) !== $connection
            || config("queue.connections.{$connection}.queue") !== self::ACTIVE_STAGING_NEXT_QUEUE
            || ! self::usesApplicationDatabase($connection)) {
            return [];
        }

        return [self::ACTIVE_STAGING_NEXT_QUEUE => $settings];
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

        return array_values(array_filter(
            array_map('strval', array_keys($configured)),
            fn (string $name): bool => $name !== self::ACTIVE_STAGING_NEXT_QUEUE,
        ));
    }

    /**
     * The database queue must resolve through Laravel's application DB connection,
     * because queue health reads that same connection via the DB facade.
     */
    private static function usesApplicationDatabase(string $queueConnection): bool
    {
        $applicationConnection = config('database.default');
        $queueDatabaseConnection = config("queue.connections.{$queueConnection}.connection");
        if (! is_string($applicationConnection) || $applicationConnection === '') {
            return false;
        }

        return $queueDatabaseConnection === null
            ? true
            : is_string($queueDatabaseConnection)
                && $queueDatabaseConnection !== ''
                && $queueDatabaseConnection === $applicationConnection;
    }

    public static function activeQueueName(string $connection): ?string
    {
        $configured = (array) config('monitoring.worker_queues', []);
        $workerSettings = $configured[self::ACTIVE_STAGING_NEXT_QUEUE] ?? null;
        if (! self::isStagingNext()
            || ! is_array($workerSettings)
            || ($workerSettings['queue'] ?? null) !== self::ACTIVE_STAGING_NEXT_QUEUE
            || ($workerSettings['connection'] ?? null) !== $connection
            || $connection !== (string) config('queue.default')
            || config("queue.connections.{$connection}.queue") !== self::ACTIVE_STAGING_NEXT_QUEUE
            || ! self::usesApplicationDatabase($connection)) {
            return null;
        }

        return self::ACTIVE_STAGING_NEXT_QUEUE;
    }
}
