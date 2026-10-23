<?php

namespace App\Websites;

use App\Pricing\ProductType;

final readonly class WebsitePurchaseTarget
{
    public function __construct(public string $url, public ProductType $type) {}

    /** @param array<string, mixed>|null $draft */
    public static function fromDraft(mixed $draft): ?self
    {
        if (! is_array($draft)
            || ! is_string($draft['url'] ?? null)
            || ! is_string($draft['type'] ?? null)
            || ! is_int($draft['created_at'] ?? null)
            || $draft['created_at'] < now()->subMinutes(30)->timestamp
            || $draft['created_at'] > now()->addMinute()->timestamp) {
            return null;
        }

        $type = ProductType::tryFrom($draft['type']);

        return $type ? new self($draft['url'], $type) : null;
    }
}
