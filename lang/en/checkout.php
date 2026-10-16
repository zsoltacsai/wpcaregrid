<?php

return [

    'meta' => [
        'pricing_title' => 'WordPress and WooCommerce Plans and Pricing | WP CareGrid',
        'pricing_description' => 'Compare current WordPress and WooCommerce maintenance plans, included features and billing options.',
        'checkout_title' => 'Checkout – WP CareGrid',
        'success_title' => 'Order status – WP CareGrid',
        'cancel_title' => 'Checkout cancelled – WP CareGrid',
    ],

    'breadcrumb' => 'Breadcrumb',
    'back_to_pricing' => 'Back to pricing',

    'title' => 'Complete your order',
    'lead' => 'Confirm your plan and details. Payment is handled by Stripe.',
    'identity' => [
        'required' => 'Complete your billing details before continuing to payment.',
        'incomplete' => 'Add the required billing details before you can continue to payment.',
    ],
    'tax_note' => 'Prices are per website. This is the final price; no VAT is added.',

    'plan_heading' => 'Plan',
    'change_plan' => 'Change plan',
    'interval_heading' => 'Billing period',
    'details_heading' => 'Your details',
    'fields' => [
        'email' => 'Email address',
        'email_hint' => 'Order confirmation and onboarding instructions are sent here.',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'customer_type' => 'Billing as',
        'billing_name' => 'Billing name',
        'company_name' => 'Company name',
        'tax_id' => 'Tax number (optional)',
        'address_line1' => 'Street address',
        'address_line2' => 'Address line 2',
        'postal_code' => 'Postal code',
        'city' => 'City',
        'country' => 'Billing country',
        'country_hint' => 'Your price and currency depend on your billing country.',
        'currency' => 'Price currency',
        'currency_hint' => 'Currency availability depends on your billing country.',
        'currency_locked' => 'This account has active subscriptions in :currency. New subscriptions must use :currency.',
        'promotion_code' => 'Promotion code',
        'optional' => 'optional',
    ],

    'international_unavailable' => 'Pricing for this billing country is not available yet. Please contact us if you would like to sign up.',

    'submit' => 'Continue to payment',
    'redirect_note' => 'You will be redirected to Stripe to enter your payment details.',
    'legal' => ['accept_terms' => 'I have read and accept the current Terms of Service.'],
    'secure_note' => 'Card details are handled by Stripe and never reach our servers.',

    'preview' => [
        'apply' => 'Apply code',
        'checking' => 'Checking promotion…',
        'applied' => 'Promotion applied to this estimate.',
        'regular' => 'Regular price',
        'today' => 'Today',
        'then' => 'Then',
        'renews' => 'Renews at',
        'year' => 'year',
        'month' => 'month',
        'once' => 'off the first billing cycle',
        'ongoing' => 'ongoing discount',
        'fixed_once' => 'Discount on the first billing cycle',
        'fixed_ongoing' => 'Ongoing discount',
        'repeating_unknown' => 'Depends on coupon end date',
        'estimate' => 'Preview only. The promotion is checked again at checkout.',
        'invalid_input' => 'Check the promotion details and try again.',
        'unavailable' => 'This promotion is unavailable for this selection. Check the code and try again.',
    ],

    'summary' => [
        'heading' => 'Order summary',
        'billed' => [
            'monthly' => 'Billed monthly. No long-term contract.',
            'annual' => 'Billed annually.',
        ],
        'includes' => 'Includes',
        'next_heading' => 'What happens next',
        'next' => [
            'Pay securely with Stripe.',
            'We confirm your order by email.',
            'We send onboarding instructions for connecting your website.',
        ],
    ],

    'errors' => [
        'invalid_selection' => 'The selected plan is not available. Please choose a plan again.',
        'market_not_available' => 'Pricing for this billing country is not available yet. Please contact us if you would like to sign up.',
        'market_currency_mismatch' => 'Hungarian billing requires HUF. For other billing countries, choose EUR, USD or GBP.',
        'customer_currency_mismatch' => 'This account has active subscriptions in :currency. Choose :currency to continue.',
        'customer_currency_ambiguous' => 'We cannot confirm this account’s billing currency. Please contact support before starting another subscription.',
        'price_inactive' => 'This plan is not available for purchase at the moment.',
        'price_not_purchasable' => 'Online checkout for this plan is not available yet. Please contact us to sign up.',
        'unavailable' => 'Online checkout is temporarily unavailable. Please try again later or contact us.',
    ],

    'promotion_errors' => [
        'unknown' => 'This promotion code is not valid.',
        'inactive' => 'This promotion code is not active.',
        'not_started' => 'This promotion code is not valid yet.',
        'expired' => 'This promotion code has expired.',
        'already_used' => 'You have already used this promotion code the maximum number of times.',
        'used_up' => 'This promotion code has already been used the maximum number of times.',
        'not_applicable' => 'This promotion code cannot be used with the selected plan or billing period.',
    ],

    'success' => [
        'title' => 'Payment received',
        'lead' => 'Thank you. Your order has been received.',
        'body' => 'We will email you to confirm the order once Stripe has confirmed the payment, followed by instructions for connecting your website. Your website is not connected yet; nothing changes on it until you complete onboarding.',
        'order' => 'Order reference',
        'plan' => 'Plan',
        'email_note' => 'Emails go to :email.',
        'home' => 'Back to homepage',
    ],

    'cancel' => [
        'title' => 'Checkout cancelled',
        'lead' => 'No payment was taken.',
        'body' => 'You can return to the checkout with the same plan, or choose a different plan.',
        'retry' => 'Return to checkout',
        'pricing' => 'View plans',
    ],

];
