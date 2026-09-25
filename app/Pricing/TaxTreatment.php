<?php

namespace App\Pricing;

/**
 * How prices in a market relate to tax. This records a business decision; it
 * does not calculate tax. An accounting/tax provider can later replace
 * `Undetermined` with a real determination per customer.
 */
enum TaxTreatment: string
{
    /** The shown price is the final price; no VAT is added on top. */
    case FinalPriceNoVatAdded = 'final_price_no_vat_added';

    /** Not decided yet; must be resolved before the market can sell. */
    case Undetermined = 'undetermined';
}
