<?php

/*
|--------------------------------------------------------------------------
| Custom recovery offers: the customer's pages and emails (English)
|--------------------------------------------------------------------------
*/

return [

    'title' => 'Offers',
    'lead' => 'Offers prepared for you for work on your website.',
    'none' => 'You have no offers.',
    'for_website' => 'For :host',
    'back' => 'Back to offers',

    'price' => 'Price',
    'one_time' => 'one-time payment',
    'valid_until' => 'Valid until',
    'website' => 'Website',
    'status' => 'Status',
    'paid_at' => 'Paid',
    'scope' => 'What the offer includes',
    'scope_note' => 'This is the work that was agreed for this offer. Nothing outside it is included.',
    'summary' => 'Your offer',

    'statuses' => [
        'draft' => 'Draft',
        'offered' => 'Waiting for your answer',
        'accepted' => 'Accepted, payment due',
        'declined' => 'Declined',
        'expired' => 'Expired',
        'paid' => 'Paid',
        'cancelled' => 'Cancelled',
        'completed' => 'Completed',
    ],

    'accept_lead' => 'If you accept, you pay the price shown once, on Stripe\'s secure page. The work starts after the payment is confirmed.',
    'accept' => 'Accept and pay',
    'decline' => 'Decline',
    'confirm_decline' => 'Decline this offer?',
    'pay_lead' => 'You have accepted this offer. Complete the payment to confirm it.',
    'pay' => 'Continue to payment',
    'confirming' => 'Thank you. We are waiting for Stripe to confirm your payment. This page updates once it is confirmed; you do not need to pay again.',
    'paid_note' => 'Payment confirmed. We will start the work described above.',
    'completed_note' => 'The work described in this offer has been completed.',
    'expired_note' => 'This offer is no longer valid. Contact us if you still want the work done.',
    'declined_note' => 'You declined this offer.',
    'cancelled_note' => 'This offer was withdrawn.',
    'declined' => 'You declined this offer.',

    'dashboard_open' => '{1} You have 1 offer waiting for your answer.|[2,*] You have :count offers waiting for your answer.',
    'dashboard_view' => 'View offers',

    'errors' => [
        'not_acceptable' => 'This offer can no longer be accepted.',
        'not_payable' => 'This offer can no longer be paid.',
        'not_declinable' => 'This offer can no longer be declined.',
        'not_eligible' => 'Recovery offers are currently available only in the Hungarian billing market.',
        'payment_unavailable' => 'The payment page could not be opened. Your acceptance is saved; please try again in a moment.',
    ],

    'mail' => [
        'greeting' => 'Hello :name,',
        'signature' => 'WP CareGrid',
        'support' => 'Questions? Reply to this email.',
        'sent' => [
            'subject' => 'An offer for your website',
            'intro' => 'We have prepared an offer for :host.',
            'price' => 'Price: :price (one-time payment)',
            'valid' => 'Valid until: :until',
            'body' => 'Sign in to read what the offer includes, and to accept or decline it.',
            'action' => 'View the offer',
        ],
        'paid' => [
            'subject' => 'Payment received for your offer',
            'intro' => 'We have received your payment of :price for "**:title**" (:host).',
            'body' => 'We will start the work described in the offer. You can follow its status in your account.',
            'action' => 'View the offer',
        ],
    ],

];
