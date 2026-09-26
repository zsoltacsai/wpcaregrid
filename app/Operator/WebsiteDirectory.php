<?php

namespace App\Operator;

use App\Connector\HealthStatus;
use App\Models\Connector;
use App\Models\Customer;
use App\Models\Website;
use App\Models\WebsiteInventory;
use App\Pricing\ProductType;
use App\Security\SecurityScanner;
use App\Security\SecurityState;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The operator's website list: search, filters and sorting, all in the
 * database, one page at a time. Search covers the website URL and the
 * customer's email and company only, never credentials or other fields.
 */
final class WebsiteDirectory
{
    public const PER_PAGE = 25;

    public const SORTS = ['status', 'website', 'customer', 'last_seen', 'updates'];

    public function __construct(private readonly SecurityScanner $security) {}

    /**
     * The validated filters from a request; anything unknown is dropped.
     *
     * @param  array<string, mixed>  $input
     * @return array{q: ?string, status: ?HealthStatus, type: ?ProductType, backup: ?BackupState, security: ?SecurityState, connector: ?ConnectorState, sort: string, direction: string}
     */
    public static function filters(array $input): array
    {
        $text = fn (string $key) => is_string($input[$key] ?? null) ? $input[$key] : '';
        $q = mb_substr(trim($text('q')), 0, 100);
        $sort = in_array($text('sort'), self::SORTS, true) ? $text('sort') : 'status';

        return [
            'q' => $q !== '' ? $q : null,
            'status' => HealthStatus::tryFrom($text('status')),
            'type' => ProductType::tryFrom($text('type')),
            'backup' => BackupState::tryFrom($text('backup')),
            'security' => SecurityState::tryFrom($text('security')),
            'connector' => ConnectorState::tryFrom($text('connector')),
            'sort' => $sort,
            'direction' => $text('direction') === 'asc' || ($text('direction') === '' && in_array($sort, ['website', 'customer'], true)) ? 'asc' : 'desc',
        ];
    }

    /** @param  array<string, mixed>  $filters  From filters(). */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->query($filters)
            ->with(['customer', 'subscription', 'activeConnector', 'inventory'])
            ->withExists('connectors')
            ->selectRaw(BackupState::LATEST_STATUS_SQL.' as latest_backup_status')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /** @param  array<string, mixed>  $filters */
    public function query(array $filters): Builder
    {
        $query = Website::query()->select('websites.*');

        if ($filters['q'] !== null) {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['q'])).'%';
            $query->where(fn (Builder $q) => $q
                ->whereRaw("lower(websites.url) like ? escape '!'", [$like])
                ->orWhereHas('customer', fn (Builder $c) => $c
                    ->whereRaw("lower(customers.email) like ? escape '!'", [$like])
                    ->orWhereRaw("lower(customers.company_name) like ? escape '!'", [$like])));
        }

        if ($filters['status']) {
            $filters['status'] === HealthStatus::Unknown
                ? $query->where(fn (Builder $q) => $q->whereNull('health_status')->orWhere('health_status', HealthStatus::Unknown->value))
                : $query->where('health_status', $filters['status']->value);
        }

        if ($filters['type']) {
            $query->where('type', $filters['type']->value);
        }

        $filters['backup']?->scope($query);
        $filters['connector']?->scope($query);

        if ($filters['security']) {
            $this->security->whereState($query, $filters['security']);
        }

        $direction = $filters['direction'];

        match ($filters['sort']) {
            'website' => $query->orderBy('websites.url', $direction),
            'customer' => $query->orderBy(Customer::select('email')->whereColumn('customers.id', 'websites.customer_id')->limit(1), $direction),
            'last_seen' => $query->orderBy(Connector::select('last_seen_at')->whereColumn('connectors.website_id', 'websites.id')->where('status', Connector::ACTIVE)->latest('id')->limit(1), $direction),
            'updates' => $query->orderBy(WebsiteInventory::select('updates_pending')->whereColumn('website_inventories.website_id', 'websites.id')->limit(1), $direction),
            // Most urgent first: critical, attention, unknown, healthy.
            default => $query->orderByRaw("case health_status when 'critical' then 3 when 'attention' then 2 when 'healthy' then 0 else 1 end ".($direction === 'asc' ? 'asc' : 'desc')),
        };

        return $query->orderBy('websites.id');
    }
}
