<?php

namespace App\Models;

use App\Connector\HealthStatus;
use App\Connector\Presence;
use App\Pricing\ProductType;
use App\Websites\ConnectorStatus;
use App\Websites\WebsiteStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Website extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id', 'uuid'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'status' => WebsiteStatus::class,
            'connector_status' => ConnectorStatus::class,
            'last_seen_at' => 'datetime',
            'health_status' => HealthStatus::class,
            'health_findings' => 'array',
            'health_checked_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function connectors(): HasMany
    {
        return $this->hasMany(Connector::class);
    }

    /** The connector currently allowed to act for this website, if any. */
    public function activeConnector(): HasOne
    {
        return $this->hasOne(Connector::class)->where('status', Connector::ACTIVE)->latestOfMany();
    }

    public function pairingCodes(): HasMany
    {
        return $this->hasMany(ConnectorPairingCode::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(WebsiteJob::class);
    }

    public function inventory(): HasOne
    {
        return $this->hasOne(WebsiteInventory::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    public function restores(): HasMany
    {
        return $this->hasMany(BackupRestore::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(WebsiteEvent::class);
    }

    public function presence(): Presence
    {
        return $this->activeConnector?->presence()
            ?? ($this->connectors()->exists() ? Presence::Disconnected : Presence::NeverConnected);
    }

    /** Paid connector operations need an active plan for this site and an active customer. */
    public function allowsServiceOperations(): bool
    {
        return $this->customer?->active === true
            && $this->subscription?->grantsAccess() === true;
    }

    /** Connected only when the connector has confirmed it. */
    public function isConnected(): bool
    {
        return $this->connector_status->isConfirmed();
    }

    /**
     * Onboarding step states. Nothing past "connect" can be done until the
     * connector has confirmed the connection.
     *
     * @return array<string, string>
     */
    public function onboardingStates(): array
    {
        $connected = $this->isConnected();
        $checked = $connected && $this->health_checked_at !== null && $this->inventory()->exists();

        return [
            'connect' => 'done',
            'connection' => $connected ? 'done' : 'current',
            'initial_check' => $checked ? 'done' : ($connected ? 'current' : 'not_started'),
            'ready' => $checked && $this->health_status !== HealthStatus::Critical ? 'done' : 'not_started',
        ];
    }

    public function host(): string
    {
        return parse_url($this->url, PHP_URL_HOST) ?: $this->url;
    }
}
