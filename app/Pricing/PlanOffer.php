<?php

namespace App\Pricing;

/** A plan with its monthly and annual price in one market, as shown on a pricing card. */
final readonly class PlanOffer
{
    public function __construct(
        public Plan $plan,
        public Price $monthly,
        public Price $annual,
        public ?Plan $previousPlan = null,
    ) {}

    public function price(BillingInterval $interval): Price
    {
        return $interval === BillingInterval::Annual ? $this->annual : $this->monthly;
    }

    /** What annual billing saves compared with twelve monthly payments. */
    public function annualSavings(): Money
    {
        return $this->monthly->money->multiply(12)->subtract($this->annual->money);
    }

    /** Annual savings in whole months of the monthly price. */
    public function freeMonthsOnAnnual(): int
    {
        $monthly = $this->monthly->money->amount;

        return $monthly > 0 ? intdiv($this->annualSavings()->amount, $monthly) : 0;
    }

    /** Features every card lists, even when unchanged from the tier below. */
    private const ALWAYS_LISTED = ['remote_backups'];

    /**
     * Features to list on the card: everything for the lowest tier, otherwise
     * what differs from the tier below (shown after "Everything in …") plus
     * the backup frequency, which every card states.
     *
     * @return list<string>
     */
    public function highlightedFeatures(): array
    {
        if (! $this->previousPlan) {
            return $this->plan->featureLabels();
        }

        $lower = $this->previousPlan->featureLabels();

        return array_values(array_map(
            fn (Feature $feature) => $feature->label,
            array_filter(
                $this->plan->features,
                fn (Feature $feature) => in_array($feature->key, self::ALWAYS_LISTED, true) || ! in_array($feature->label, $lower, true),
            ),
        ));
    }
}
