<?php

/*
|--------------------------------------------------------------------------
| Homepage copy (English)
|--------------------------------------------------------------------------
|
| All text on the marketing homepage. Structure (icons, links, order) lives in
| App\Content\HomepageContent; add lang/hu/home.php with the same keys to
| translate the page.
|
*/

return [

    'meta' => [
        'title' => 'WordPress Operations and Maintenance | WP CareGrid',
        'description' => 'Manage WordPress and WooCommerce updates, backups, security checks and site health from one operations platform.',
    ],

    'skip_link' => 'Skip to content',
    'home_link' => 'WP CareGrid home',
    'tagline' => 'WordPress maintained. Website working.',
    'descriptor' => 'WordPress Maintenance',

    // Language selector (header and customer sidebar).
    'language' => [
        'label' => 'Language',
    ],

    'nav' => [
        'label' => 'Main',
        'mobile_label' => 'Mobile',
        'open_menu' => 'Open menu',
        'close_menu' => 'Close menu',
        'services' => 'Services',
        'pricing' => 'Pricing',
        'how_it_works' => 'How it works',
        'custom_development' => 'Custom Development',
        'faq' => 'FAQ',
        'login' => 'Login',
        'get_started' => 'Get started',
    ],

    'hero' => [
        'title' => 'WordPress maintenance, without the hassle.',
        'text' => 'WordPress and WooCommerce maintenance, backups, security checks and updates — with functional checks after important updates.',
        'primary_cta' => 'View plans',
        'secondary_cta' => 'How it works',
        'trust' => [
            'contract' => 'No long-term contract',
            'connection' => 'Secure connection',
            'business' => 'For businesses',
        ],
    ],

    'services' => [
        'title' => 'What maintenance includes',
        'items' => [
            'updates' => ['title' => 'Updates', 'text' => 'WordPress, plugins and themes'],
            'backups' => ['title' => 'Backups', 'text' => 'Backups for supported setups; separate storage where configured'],
            'security' => ['title' => 'Security', 'text' => 'Plan-specific scheduled security checks'],
            'functional' => ['title' => 'Functional checks', 'text' => 'We verify important functions after updates'],
        ],
    ],

    'why' => [
        'eyebrow' => 'Why maintenance',
        'title' => "WordPress doesn't maintain itself.",
        'lead' => 'Plugins, themes and WordPress itself change constantly. Without regular care, small problems add up.',
        'items' => [
            'outdated' => ['title' => 'Outdated software', 'text' => 'Old plugins and themes can create security and compatibility problems.'],
            'backup' => ['title' => 'No recent backup', 'text' => 'Without a recent backup, recovering from a problem can be much harder.'],
            'updates' => ['title' => 'Updates can break things', 'text' => 'A successful update does not always mean every important function still works.'],
        ],
    ],

    'verification' => [
        'eyebrow' => 'Update process',
        'title' => "We don't just update. We check.",
        'lead' => 'Where supported by the plan and site setup, maintenance includes a backup, updates, technical checks and functional verification.',
        'step_label' => 'Step :number',
        'steps' => [
            'backup' => ['title' => 'Backup', 'text' => 'A pre-update backup is made where the plan and site setup support it.', 'result' => 'Verified'],
            'update' => ['title' => 'Update', 'text' => 'Core, plugins and themes are updated in a controlled order.', 'result' => 'Updated'],
            'check' => ['title' => 'Check', 'text' => 'We confirm the site loads without errors.', 'result' => 'No errors'],
            'functional' => ['title' => 'Functional test', 'text' => 'Key pages, forms and logins are tested.', 'result' => 'Passed'],
            'done' => ['title' => 'Done', 'text' => 'The result is recorded in your activity log.', 'result' => 'Logged'],
        ],
    ],

    'pricing' => [
        'eyebrow' => 'Pricing',
        'title' => 'Plans and pricing',
        'lead' => 'A fixed price per website. Pay monthly, or annually and get two months free. No long-term contract.',
    ],

    'dashboard' => [
        'eyebrow' => 'Dashboard',
        'title' => 'All your websites in one place.',
        'lead' => 'Review available health, backup, update, security and license information for websites connected to your account.',
        'demo_note' => 'Illustrative figures, not real customer data.',
        'metrics_label' => 'Example dashboard metrics (demo data)',
    ],

    'licenses' => [
        'eyebrow' => 'License management',
        'title' => "Expired licenses don't go unnoticed.",
        'lead' => 'Premium plugins and themes stop receiving updates when their license runs out. When a license appears expired or invalid, WP CareGrid records it and notifies the website owner.',
        'limitation' => 'Not every plugin license can be checked automatically; some vendors do not expose license status.',
        'responsibility' => "Third-party plugin and theme licenses remain the customer's responsibility.",
    ],

    'woocommerce' => [
        'eyebrow' => 'WooCommerce',
        'title' => 'For stores, we check more.',
        'lead' => 'WooCommerce sites have more moving parts, so our WooCommerce plans include additional checks around the purchasing process.',
        'cta' => 'See WooCommerce plans',
        'flow_label' => 'Purchase flow checked after updates',
        'flow' => [
            'product' => 'Product',
            'cart' => 'Cart',
            'checkout' => 'Checkout',
            'payment' => 'Payment',
            'order' => 'Order',
            'email' => 'Email',
        ],
    ],

    'development' => [
        'eyebrow' => 'Custom development',
        'title' => 'Need more than maintenance?',
        'lead' => 'We also build custom WordPress and WooCommerce solutions.',
        'cta' => 'Talk about a project',
        'note' => 'Custom development is a separate service, quoted and billed independently of maintenance subscriptions.',
        'items' => [
            'plugins' => ['title' => 'Custom plugins', 'text' => 'Unique functionality for your website.'],
            'api' => ['title' => 'API integrations', 'text' => 'Connect WordPress or WooCommerce with external systems.'],
            'woocommerce' => ['title' => 'WooCommerce development', 'text' => 'Custom product, order and checkout functionality.'],
            'automation' => ['title' => 'Automation', 'text' => 'Reduce repetitive technical work.'],
        ],
    ],

    'how' => [
        'eyebrow' => 'How it works',
        'title' => 'Up and running in four steps.',
        'steps' => [
            'plan' => ['title' => 'Explore the plans', 'text' => 'Review the available options and contact us to ask about service availability for your site.'],
            'connect' => ['title' => 'Connect your website', 'text' => 'Connect your WordPress site using a secure connection.'],
            'audit' => ['title' => 'Initial check', 'text' => 'We assess the site setup and available service options; backups are subject to plan and site support.'],
            'ongoing' => ['title' => 'Ongoing maintenance', 'text' => 'Updates, backups, checks and monitoring according to the plan and supported site setup.'],
        ],
    ],

    'activity' => [
        'eyebrow' => 'Activity log',
        'title' => 'See recorded site activity.',
        'lead' => 'Supported backups, updates and checks are recorded with available dates and results, so you can review activity for your site.',
        'frame_title' => 'Activity log',
        'caption' => 'Example activity log entries (demo data).',
    ],

    'faq' => [
        'eyebrow' => 'FAQ',
        'title' => 'Frequently asked questions',
        'contact_prompt' => "Can't find what you're looking for?",
        'contact_link' => 'Contact us',
        'items' => [
            'password' => [
                'question' => 'Do I need to give you my WordPress password?',
                'answer' => 'No. You connect your website through a secure connection that you can revoke at any time. You do not need to send us your administrator password.',
            ],
            'broken_update' => [
                'question' => 'What happens if an update breaks my website?',
                'answer' => 'A pre-update backup is created where the plan and site setup support it. If a post-update check fails, we review the available backup and recovery options; restore capability depends on the backup and hosting environment.',
            ],
            'backup_location' => [
                'question' => 'Where are backups stored?',
                'answer' => 'Backup storage is configured separately from the website hosting for supported plans. Storage location and recovery options depend on the service configuration; a separate copy does not guarantee restoration in every hosting environment.',
            ],
            'backup_frequency' => [
                'question' => 'How often are backups made?',
                'answer' => 'It depends on the plan. WordPress: monthly on Basic, weekly on Business, daily on Secure. WooCommerce: weekly on Basic, daily on Business and Secure. Additional pre-update backups are made where the plan and site setup support them.',
            ],
            'license' => [
                'question' => 'What happens if a plugin license expires?',
                'answer' => 'When a license appears expired or invalid, we record it and notify you. Renewing third-party licenses remains your responsibility. Until a license is renewed, that plugin or theme may not receive updates.',
            ],
            'infected' => [
                'question' => 'What happens if my website is infected?',
                'answer' => 'We notify you when an applicable security check reports a finding and agree the next steps with you. Malware cleanup is available for eligible Secure-plan cases and supported sites; other cases are assessed and quoted separately.',
            ],
            'custom_plugins' => [
                'question' => 'Can you develop custom plugins?',
                'answer' => 'Yes. We build custom plugins, API integrations and WooCommerce functionality as a separate service. Tell us what you need and we will reply with a proposal.',
            ],
        ],
    ],

    'cta' => [
        'title' => 'Keep your WordPress maintained.',
        'text' => 'Explore the plans and contact us to discuss your website and service availability.',
        'primary' => 'View plans',
        'secondary' => 'Contact us',
    ],

    'footer' => [
        'columns' => [
            'product' => 'Product',
            'account' => 'Account',
            'legal' => 'Legal',
        ],
        'links' => [
            'contact' => 'Contact',
            'terms' => 'Terms',
            'privacy' => 'Privacy',
            'cookies' => 'Cookie Policy',
        ],
        'copyright' => '© :year WP CareGrid',
    ],

];
