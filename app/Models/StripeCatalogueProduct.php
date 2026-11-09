<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The Stripe Product known for a product type in one Stripe mode (docs/STRIPE_CATALOGUE.md). */
class StripeCatalogueProduct extends Model
{
    protected $guarded = ['id'];
}
