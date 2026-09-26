<?php

namespace App\Models;

use App\AdminAccess\AdminAccessService;
use App\Connector\Capability;
use App\Connector\Presence;
use App\Websites\AuditLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One WP CareGrid Connector installation. The bearer credential is stored
 * only as a SHA-256 hash; the raw value exists once, in the pairing response.
 */
class Connector extends Model
{
    use HasFactory, HasUuids;

    public const ACTIVE = 'active';

    public const REVOKED = 'revoked';

    protected $guarded = ['id', 'uuid'];

    protected $hidden = ['credential_hash'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'last_seen_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'last_successful_poll_at' => 'datetime',
            'last_inventory_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hashCredential(string $credential): string
    {
        return hash('sha256', $credential);
    }

    public static function findByCredential(string $credential): ?self
    {
        return static::where('credential_hash', static::hashCredential($credential))->first();
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function can(Capability $capability): bool
    {
        return in_array($capability->value, $this->capabilities ?? [], true);
    }

    public function presence(): Presence
    {
        if (! $this->isActive()) {
            return Presence::Disconnected;
        }

        if (! $this->last_seen_at) {
            return Presence::NeverConnected;
        }

        $silence = $this->last_seen_at->diffInSeconds(now(), absolute: true);

        return match (true) {
            $silence <= config('connector.stale_after') => Presence::Connected,
            $silence <= config('connector.disconnected_after') => Presence::Stale,
            default => Presence::Disconnected,
        };
    }

    public function isOutdated(): bool
    {
        return $this->connector_version !== null
            && version_compare($this->connector_version, config('connector.latest_version'), '<');
    }

    /** Every disconnect path ends here, so admin access always ends with the connector. */
    public function revoke(string $reason, string $actor = 'system'): void
    {
        $this->forceFill(['status' => self::REVOKED, 'revoked_at' => now(), 'revoked_reason' => $reason])->save();

        AuditLog::record($this->website, 'connector.disconnected', actor: $actor, data: ['reason' => $reason, 'connector_version' => $this->connector_version]);

        app(AdminAccessService::class)->revokeAll($this->website, 'connector_'.$reason);
    }
}
