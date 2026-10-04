<?php

namespace App\Owner;

use App\Billing\SubscriptionStatus;
use App\Models\Customer;
use App\Pricing\PlanTier;
use App\Pricing\ProductType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The owner's customer list: search and filters in the database, one page at
 * a time. Search covers email, company and website URL only.
 */
final class CustomerDirectory
{
    public const PER_PAGE = 25;

    /**
     * The validated filters from a request; anything unknown is dropped.
     *
     * @param  array<string, mixed>  $input
     * @return array{q: ?string, subscription: SubscriptionStatus|'none'|null, country: ?string, type: ?ProductType, plan: ?PlanTier}
     */
    public static function filters(array $input): array
    {
        $text = fn (string $key) => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        $q = mb_substr($text('q'), 0, 100);
        $country = strtoupper($text('country'));

        return [
            'q' => $q !== '' ? $q : null,
            'subscription' => $text('subscription') === 'none' ? 'none' : SubscriptionStatus::tryFrom($text('subscription')),
            'country' => array_key_exists($country, (array) __('countries')) ? $country : null,
            'type' => ProductType::tryFrom($text('type')),
            'plan' => PlanTier::tryFrom($text('plan')),
        ];
    }

    /** @param  array<string, mixed>  $filters  From filters(). */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Customer::query()
            ->with(['subscriptions' => fn ($q) => $q->latest('id')])
            ->withCount('websites');

        if ($filters['q'] !== null) {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['q'])).'%';
            $query->where(fn (Builder $q) => $q
                ->whereRaw("lower(customers.email) like ? escape '!'", [$like])
                ->orWhereRaw("lower(customers.company_name) like ? escape '!'", [$like])
                ->orWhereHas('websites', fn (Builder $w) => $w->whereRaw("lower(websites.url) like ? escape '!'", [$like])));
        }

        if ($filters['subscription'] === 'none') {
            $query->whereDoesntHave('subscriptions');
        } elseif ($filters['subscription'] instanceof SubscriptionStatus) {
            $query->whereHas('subscriptions', fn (Builder $q) => $q->where('status', $filters['subscription']->value));
        }

        if ($filters['country']) {
            $query->where('country_code', $filters['country']);
        }

        if ($filters['type'] || $filters['plan']) {
            $query->whereHas('subscriptions', fn (Builder $q) => $q
                ->when($filters['type'], fn ($s) => $s->where('product_type', $filters['type']->value))
                ->when($filters['plan'], fn ($s) => $s->where('plan', $filters['plan']->value)));
        }

        return $query->latest('id')->paginate(self::PER_PAGE)->withQueryString();
    }
}
