<?php

namespace App\SiteHealth;

use App\Connector\HealthStatus;

final readonly class SiteHealthReport
{
    /** @param  array<string, DimensionResult>  $dimensions */
    private function __construct(
        public HealthStatus $overall,
        public array $dimensions,
    ) {}

    /** @param  list<DimensionResult>  $results */
    public static function from(array $results): self
    {
        $rank = [HealthStatus::Healthy->value => 1, HealthStatus::Attention->value => 2, HealthStatus::Critical->value => 3];
        $known = array_filter($results, fn (DimensionResult $r) => $r->state !== HealthStatus::Unknown);

        $overall = $known === []
            ? HealthStatus::Unknown
            : array_reduce($known, fn (?HealthStatus $worst, DimensionResult $r) => $worst === null || $rank[$r->state->value] > $rank[$worst->value] ? $r->state : $worst);

        $keyed = [];
        foreach ($results as $result) {
            $keyed[$result->dimension->value] = $result;
        }

        return new self($overall, $keyed);
    }

    public function dimension(Dimension $dimension): DimensionResult
    {
        return $this->dimensions[$dimension->value];
    }

    /** @return list<Dimension> Dimensions nothing is known about. */
    public function unknown(): array
    {
        return array_values(array_map(fn (DimensionResult $r) => $r->dimension, array_filter($this->dimensions, fn (DimensionResult $r) => $r->state === HealthStatus::Unknown)));
    }
}
