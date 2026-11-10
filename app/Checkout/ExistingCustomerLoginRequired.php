<?php

namespace App\Checkout;

use RuntimeException;

/**
 * A logged-out visitor submitted the email of a customer who already has a Stripe customer. Stripe would create a second
 * customer for the same email, and the payment could then never be matched to the subscription: the purchase has to be made
 * from the customer's own login, where the existing Stripe customer and the currency lock apply.
 */
final class ExistingCustomerLoginRequired extends RuntimeException {}
