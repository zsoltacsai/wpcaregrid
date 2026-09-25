<?php

return [

    'meta' => [
        'pricing_title' => 'Pricing – WP CareGrid',
        'pricing_description' => 'WordPress and WooCommerce maintenance plans. Pay monthly, or annually and get two months free.',
        'checkout_title' => 'Checkout – WP CareGrid',
        'success_title' => 'Order status – WP CareGrid',
        'cancel_title' => 'Checkout cancelled – WP CareGrid',
    ],

    'breadcrumb' => 'Breadcrumb',
    'back_to_pricing' => 'Back to pricing',

    'title' => 'Complete your order',
    'lead' => 'Confirm your plan and details. Payment is handled by Stripe.',

    'plan_heading' => 'Plan',
    'change_plan' => 'Change plan',
    'interval_heading' => 'Billing period',
    'details_heading' => 'Your details',
    'fields' => [
        'email' => 'Email address',
        'email_hint' => 'Order confirmation and onboarding instructions are sent here.',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'country' => 'Billing country',
        'country_hint' => 'Your price and currency depend on your billing country.',
        'promotion_code' => 'Promotion code',
        'optional' => 'optional',
    ],

    'international_unavailable' => 'Pricing for customers billed outside Hungary is not available yet. Please contact us if you would like to sign up.',

    'submit' => 'Continue to payment',
    'redirect_note' => 'You will be redirected to Stripe to enter your payment details.',
    'secure_note' => 'Card details are handled by Stripe and never reach our servers.',

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
        'market_not_available' => 'Pricing for your billing country is not available yet. Checkout is currently open to customers billed in Hungary.',
        'price_inactive' => 'This plan is not available for purchase at the moment.',
        'price_not_purchasable' => 'Online checkout for this plan is not available yet. Please contact us to sign up.',
        'unavailable' => 'Online checkout is temporarily unavailable. Please try again later or contact us.',
    ],

    'promotion_errors' => [
        'unknown' => 'This promotion code is not valid.',
        'inactive' => 'This promotion code is not active.',
        'not_started' => 'This promotion code is not valid yet.',
        'expired' => 'This promotion code has expired.',
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
