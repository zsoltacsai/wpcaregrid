<?php

namespace App\Pricing;

enum ProductType: string
{
    case WordPress = 'wordpress';
    case WooCommerce = 'woocommerce';

    public function label(): string
    {
        return __("pricing.product_types.{$this->value}.label");
    }

    public function summary(): string
    {
        return __("pricing.product_types.{$this->value}.summary");
    }
}
