<?php

return [

    'greeting' => 'Hello :name,',
    'signature' => 'WP CareGrid',
    'support' => 'Questions? Reply to this email.',

    'activation' => [
        'subject' => 'Activate your WP CareGrid account',
        'intro' => 'Thank you for your order. Your payment has been confirmed.',
        'body' => 'Set a password to activate your account. From your account you can follow your subscription and connect your website.',
        'action' => 'Set your password',
        'expiry' => 'This link is valid for 7 days. If it has expired, use "Forgot password" on the login page with this email address.',
        'ignore' => 'If you did not place this order, you can ignore this email.',
    ],

    'welcome' => [
        'subject' => 'Welcome to WP CareGrid',
        'intro' => 'Your account is active.',
        'body' => 'The next step is to add your website so we can start the initial check.',
        'action' => 'Go to your account',
    ],

    'payment_confirmation' => [
        'subject' => 'Payment received',
        'intro' => 'We have received your payment of :amount for :plan.',
        'body' => 'An invoice will follow separately.',
        'action' => 'View subscription',
    ],

    'payment_failed' => [
        'subject' => 'Your payment did not go through',
        'intro' => 'A payment of :amount for :plan could not be collected.',
        'body' => 'Stripe will retry the payment. Please check that your payment method is valid to avoid an interruption of the service.',
        'action' => 'View subscription',
    ],

    'subscription_activated' => [
        'subject' => 'Your subscription is active',
        'intro' => 'Your :plan subscription is active.',
        'body' => 'Add your website in your account to start onboarding.',
        'action' => 'Add your website',
    ],

    'subscription_canceled' => [
        'subject' => 'Your subscription has been canceled',
        'intro' => 'Your :plan subscription has been canceled.',
        'body' => 'Maintenance for the website on this plan has stopped. If this was not intended, reply to this email.',
        'action' => 'View subscription',
    ],

];
