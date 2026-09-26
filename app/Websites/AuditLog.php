<?php

namespace App\Websites;

use App\Models\AdminAccessRequest;
use App\Models\Backup;
use App\Models\BackupRestore;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteEvent;
use Illuminate\Support\Facades\Log;

/**
 * Records what happened to a website: backups, restores, updates, admin
 * access and the connector. Entries are shown to the customer and
 * operators, so they carry codes and sizes only: never credentials, paths
 * on the site or storage details.
 */
final class AuditLog
{
    public const EVENTS = [
        'backup.requested', 'backup.started', 'backup.uploaded', 'backup.verified', 'backup.failed',
        'backup.corrupt', 'backup.expired', 'backup.deleted',
        'restore.requested', 'restore.started', 'restore.completed', 'restore.failed',
        'update.requested', 'update.started', 'update.completed', 'update.failed', 'update.blocked',
        'admin_access.requested', 'admin_access.authorized', 'admin_access.started', 'admin_access.expired', 'admin_access.revoked', 'admin_access.failed',
        'connector.paired', 'connector.disconnected',
        'license.expired',
    ];

    /**
     * @param  array<string, scalar|null>  $data
     * @param  string  $actor  "customer:12", "operator:3" or "system"; the type and user are also stored as columns.
     */
    public static function record(Website $website, string $event, ?Backup $backup = null, ?BackupRestore $restore = null, string $actor = 'system', array $data = [], ?AdminAccessRequest $access = null): WebsiteEvent
    {
        if (! in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException("Unknown audit event {$event}.");
        }

        $data = array_filter($data, fn ($value) => is_scalar($value) || $value === null);
        $who = Actor::parse($actor);

        Log::info("Website event {$event}.", ['website' => $website->uuid, 'backup' => $backup?->uuid, 'restore' => $restore?->uuid, 'admin_access' => $access?->uuid, 'actor' => $who->label()] + $data);

        return WebsiteEvent::create([
            'website_id' => $website->id,
            'event' => $event,
            'backup_id' => $backup?->id,
            'restore_id' => $restore?->id,
            'admin_access_request_id' => $access?->id,
            'actor' => substr($actor, 0, 64),
            'actor_type' => $who->type,
            // A label naming a user that no longer exists keeps its type only.
            'actor_user_id' => $who->userId !== null && User::whereKey($who->userId)->exists() ? $who->userId : null,
            'data' => $data ?: null,
        ]);
    }
}
