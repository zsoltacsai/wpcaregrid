<?php

namespace App\Owner;

use App\Billing\SubscriptionStatus;
use App\Connector\HealthStatus;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Website;
use App\Models\WebsiteInventory;
use App\Operator\BackupState;
use App\Pricing\BillingInterval;
use App\Pricing\Currency;
use App\Pricing\Money;
use App\Security\SecurityScanner;
use App\SiteHealth\SiteHealth;
use Illuminate\Database\Eloquent\Builder;

/**
 * Owner dashboard figures, all counted from stored data; nothing is
 * estimated, and an empty system shows zeros.
 *
 * - MRR: subscriptions with status "active" (trialing subscriptions pay
 *   nothing yet), the local amount per month (annual ÷ 12), one total per
 *   currency (amounts in different currencies are never added together).
 * - Active customers / subscriptions / websites: a subscription that grants
 *   service access (active or trialing), on an active customer.
 * - Healthy / attention / critical: Unified Site Health of active websites
 *   (App\SiteHealth\SiteHealth; no second calculation).
 * - Failed backups: websites whose latest backup failed (as in the operator console).
 */
final class BusinessMetrics
{
    public function __construct(
        private readonly SiteHealth $health,
        private readonly SecurityScanner $security,
    ) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        $health = $this->healthCounts();

        return [
            'mrr' => $this->mrr(),
            'active_customers' => Customer::where('active', true)->whereHas('subscriptions', fn (Builder $q) => $q->whereIn('status', self::granting()))->count(),
            'active_subscriptions' => Subscription::whereIn('status', self::granting())->count(),
            'active_websites' => $this->activeWebsites()->count(),
            'healthy_websites' => $health[HealthStatus::Healthy->value],
            'attention_websites' => $health[HealthStatus::Attention->value],
            'critical_websites' => $health[HealthStatus::Critical->value],
            'unknown_websites' => $health[HealthStatus::Unknown->value],
            'failed_backups' => tap(Website::query(), fn (Builder $q) => BackupState::Failed->scope($q))->count(),
            'open_security_findings' => $this->security->openFindings(),
            'expired_licenses' => (int) WebsiteInventory::sum('licenses_expired'),
        ];
    }

    /**
     * Monthly recurring revenue per currency, in minor units.
     *
     * @return list<Money>
     */
    public function mrr(): array
    {
        $totals = [];
        Subscription::where('status', SubscriptionStatus::Active->value)
            ->whereNotNull('amount')->whereNotNull('currency')
            ->select(['id', 'currency', 'interval', 'amount'])
            ->lazyById(500, 'id')
            ->each(function (Subscription $subscription) use (&$totals) {
                $months = $subscription->interval instanceof BillingInterval ? $subscription->interval->months() : 1;
                $totals[$subscription->currency] = ($totals[$subscription->currency] ?? 0) + intdiv($subscription->amount, $months);
            });

        if ($totals === []) {
            // Zero in the currency of the market the prices are shown in.
            $currency = (string) config('pricing.markets.'.config('pricing.display_market').'.currency', 'HUF');
            $totals[$currency] = 0;
        }

        ksort($totals);

        return array_values(array_map(
            fn (string $code, int $amount) => new Money($amount, Currency::fromConfig($code, (array) config("pricing.currencies.{$code}", []), app()->getLocale())),
            array_keys($totals),
            $totals,
        ));
    }

    /** @return array<string, int> Websites per overall Site Health state. */
    public function healthCounts(): array
    {
        $counts = array_fill_keys(array_map(fn (HealthStatus $s) => $s->value, HealthStatus::cases()), 0);

        $this->activeWebsites()
            ->with(['activeConnector', 'inventory', 'subscription', 'customer'])
            ->lazyById(200, 'websites.id', 'id')
            ->each(function (Website $website) use (&$counts) {
                $counts[$this->health->for($website)->overall->value]++;
            });

        return $counts;
    }

    private function activeWebsites(): Builder
    {
        return Website::query()
            ->whereHas('customer', fn (Builder $q) => $q->where('active', true))
            ->whereHas('subscription', fn (Builder $q) => $q->whereIn('status', self::granting()));
    }

    /** @return list<string> */
    private static function granting(): array
    {
        return array_values(array_map(fn (SubscriptionStatus $s) => $s->value, array_filter(SubscriptionStatus::cases(), fn (SubscriptionStatus $s) => $s->grantsAccess())));
    }
}
