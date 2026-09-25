<?php

namespace App\Models;

use App\Pricing\ProductType;
use App\Websites\ConnectorStatus;
use App\Websites\WebsiteStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        $checked = $connected && in_array($this->status, [WebsiteStatus::Connected, WebsiteStatus::Attention, WebsiteStatus::Critical], true);

        return [
            'connect' => 'done',
            'connection' => $connected ? 'done' : 'current',
            'initial_check' => $checked ? 'done' : ($connected ? 'current' : 'not_started'),
            'ready' => $checked && $this->status === WebsiteStatus::Connected ? 'done' : 'not_started',
        ];
    }

    public function host(): string
    {
        return parse_url($this->url, PHP_URL_HOST) ?: $this->url;
    }
}
