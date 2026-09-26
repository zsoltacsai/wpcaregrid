<?php

namespace App\Operator;

use App\Connector\Presence;
use App\Models\Website;
use App\Websites\ConnectorStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * A website's connector as the operator sees it. Derived from the same
 * timestamps as Presence; the query scope and of() must agree.
 */
enum ConnectorState: string
{
    case Connected = 'connected';
    case Stale = 'stale';
    case Disconnected = 'disconnected';
    case Revoked = 'revoked';
    case NotConnected = 'not_connected';

    public static function of(Website $website): self
    {
        if ($connector = $website->activeConnector) {
            return match ($connector->presence()) {
                Presence::Connected => self::Connected,
                Presence::Stale => self::Stale,
                Presence::Disconnected => self::Disconnected,
                Presence::NeverConnected => self::NotConnected,
            };
        }

        return match (true) {
            $website->connector_status === ConnectorStatus::Revoked => self::Revoked,
            (bool) ($website->connectors_exists ?? $website->connectors()->exists()) => self::Disconnected,
            default => self::NotConnected,
        };
    }

    /** Limit a website query to websites in this state. */
    public function scope(Builder $websites): void
    {
        $stale = now()->subSeconds((int) config('connector.stale_after'));
        $gone = now()->subSeconds((int) config('connector.disconnected_after'));
        $active = fn (Builder $c) => $c->where('status', 'active');

        match ($this) {
            self::Connected => $websites->whereHas('connectors', fn (Builder $c) => $active($c)->where('last_seen_at', '>=', $stale)),
            self::Stale => $websites->whereHas('connectors', fn (Builder $c) => $active($c)->where('last_seen_at', '<', $stale)->where('last_seen_at', '>=', $gone)),
            self::Disconnected => $websites->where(fn (Builder $q) => $q
                ->whereHas('connectors', fn (Builder $c) => $active($c)->where('last_seen_at', '<', $gone))
                ->orWhere(fn (Builder $q) => $q->whereDoesntHave('connectors', $active)->whereHas('connectors')->where('connector_status', '!=', ConnectorStatus::Revoked->value))),
            self::Revoked => $websites->whereDoesntHave('connectors', $active)->where('connector_status', ConnectorStatus::Revoked->value),
            self::NotConnected => $websites->where(fn (Builder $q) => $q
                ->whereHas('connectors', fn (Builder $c) => $active($c)->whereNull('last_seen_at'))
                ->orWhere(fn (Builder $q) => $q->whereDoesntHave('connectors')->where('connector_status', '!=', ConnectorStatus::Revoked->value))),
        };
    }

    /** Not reporting in although it should: what "connectors offline" counts. */
    public function isOffline(): bool
    {
        return in_array($this, [self::Stale, self::Disconnected], true);
    }

    public function label(): string
    {
        return __('operator.connector_states.'.$this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Connected => 'positive',
            self::Stale => 'warning',
            self::Disconnected, self::Revoked => 'danger',
            self::NotConnected => 'neutral',
        };
    }
}
