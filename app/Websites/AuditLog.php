<?php

namespace App\Websites;

use App\Models\Backup;
use App\Models\BackupRestore;
use App\Models\Website;
use App\Models\WebsiteEvent;
use Illuminate\Support\Facades\Log;

/**
 * Records what happened to a website's backups and restores. Entries are
 * shown to the customer and operators, so they carry codes and sizes only:
 * never credentials, paths on the site or storage details.
 */
final class AuditLog
{
    public const EVENTS = [
        'backup.requested', 'backup.started', 'backup.uploaded', 'backup.verified', 'backup.failed',
        'backup.corrupt', 'backup.expired', 'backup.deleted',
        'restore.requested', 'restore.started', 'restore.completed', 'restore.failed',
        'update.blocked',
    ];

    /** @param  array<string, scalar|null>  $data */
    public static function record(Website $website, string $event, ?Backup $backup = null, ?BackupRestore $restore = null, string $actor = 'system', array $data = []): WebsiteEvent
    {
        if (! in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException("Unknown audit event {$event}.");
        }

        $data = array_filter($data, fn ($value) => is_scalar($value) || $value === null);

        Log::info("Website event {$event}.", ['website' => $website->uuid, 'backup' => $backup?->uuid, 'restore' => $restore?->uuid] + $data);

        return WebsiteEvent::create([
            'website_id' => $website->id,
            'event' => $event,
            'backup_id' => $backup?->id,
            'restore_id' => $restore?->id,
            'actor' => substr($actor, 0, 64),
            'data' => $data ?: null,
        ]);
    }
}
