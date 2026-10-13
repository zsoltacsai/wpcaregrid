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

    'staff_invitation' => [
        'subject' => 'Your WP CareGrid login',
        'intro' => 'You have been given access to the WP CareGrid :area.',
        'body' => 'Set a password to activate your login. Nobody else knows or sets it.',
        'action' => 'Set your password',
        'expiry' => 'This link can be used once and is valid for :hours hours.',
        'ignore' => 'If you did not expect this email, you can ignore it.',
        'areas' => [
            'operator' => 'operator console',
            'owner' => 'owner administration',
        ],
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

    'lifecycle' => [
        'action' => 'View billing',
        'cancellation_scheduled' => ['subject' => 'Subscription cancellation scheduled', 'body' => 'Your :plan subscription will remain active until the end of the paid period, then it will end.'],
        'subscription_reactivated' => ['subject' => 'Subscription reactivated', 'body' => 'Your request to keep the :plan subscription active was confirmed.'],
        'upgrade_completed' => ['subject' => 'Plan change confirmed', 'body' => 'Your :plan plan change was confirmed after payment.'],
        'downgrade_scheduled' => ['subject' => 'Plan change scheduled', 'body' => 'Your :plan plan change is scheduled for the next renewal. Your current access remains until then.'],
        'payment_recovered' => ['subject' => 'Payment issue resolved', 'body' => 'Payment for your :plan subscription was confirmed and service access is active.'],
        'grace_started' => ['subject' => 'Action needed for your subscription payment', 'body' => 'We could not collect payment for :plan. Your account remains available during the payment grace period.'],
        'grace_reminder' => ['subject' => 'Reminder: subscription payment needs attention', 'body' => 'Please update the payment method for :plan before the grace period ends.'],
        'invoice_available' => ['subject' => 'Your invoice is available', 'body' => 'A billing document for :plan is available in your account.'],
        'billing_action_required' => ['subject' => 'Billing action required', 'body' => 'Please review the billing details for :plan in your account.'],
    ],

];
