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
        'title' => 'We work in the background. You see it all clearly.',
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
        'title' => 'Up and running in three steps.',
        'steps' => [
            'plan' => ['title' => 'Explore the plans', 'text' => 'Review the available options and contact us to ask about service availability for your site.'],
            'agree' => ['title' => 'Talk it through', 'text' => 'We discuss your site, assess the setup and confirm whether and how we can help. Backups depend on the plan and site support.'],
            'maintain' => ['title' => 'Ongoing maintenance', 'text' => 'Where service is available for your site, maintenance covers updates, backups, checks and monitoring as the plan and setup support.'],
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
        'cta' => 'Have questions? Let\'s talk!',
        'items' => [
            'includes' => [
                'question' => 'What does WordPress maintenance include?',
                'answer' => 'Depending on the plan: WordPress, plugin and theme updates, backups, security checks, uptime monitoring on supported websites and an activity log. Business and Secure also include functional checks after important updates and license checks; Secure adds priority support, a monthly report and malware cleanup in eligible, supported cases. Third-party licenses and custom development are not included.',
            ],
            'why' => [
                'question' => 'Why is regular WordPress maintenance important?',
                'answer' => 'WordPress, plugins and themes change constantly. Outdated software can cause security and compatibility problems, recovering from a problem is much harder without a recent backup, and a successful update does not automatically mean every important function still works. Regular maintenance reduces these risks but does not remove them.',
            ],
            'woocommerce' => [
                'question' => 'Do you maintain WooCommerce stores too?',
                'answer' => 'Yes, with separate WooCommerce plans. Besides WordPress maintenance, the Business and Secure plans also check the checkout flow and payment and order emails after updates. Backups are made weekly on Basic and daily on Business and Secure, where the plan and website setup support them.',
            ],
            'start' => [
                'question' => 'How does working together begin?',
                'answer' => 'For now it starts with getting in touch: send us your website address and what you need. We confirm whether we can help, which plan fits and what your website setup supports. We never ask for your WordPress, hosting or database password. Online ordering and self-service connecting are not available at the moment.',
            ],
            'downtime' => [
                'question' => 'Can my website become unavailable during maintenance?',
                'answer' => 'During an update WordPress can briefly switch to maintenance mode, so the site may be unavailable or slower for a short time. This cannot be ruled out entirely, and we do not guarantee availability. A backup is made before the update where the plan and website setup support it, and afterwards we check that the site loads without errors.',
            ],
            'failure' => [
                'question' => 'What happens if my website breaks?',
                'answer' => 'If a check after an update reports a problem, we review the available backup and recovery options; restoring also depends on the backup and the hosting environment. If a security check reports a finding, we notify you and agree the next steps. Malware cleanup is available for eligible Secure-plan cases and supported websites. We do not promise response or recovery times.',
            ],
            'tracking' => [
                'question' => 'How can I keep track of the maintenance?',
                'answer' => 'Every plan includes an activity log that shows the recorded results of supported backups, updates and checks; the Secure plan also includes a monthly maintenance report. The dashboard where you can follow this is not publicly available yet, so we agree the details with you when you get in touch.',
            ],
            'cancellation' => [
                'question' => 'How does cancelling the subscription work?',
                'answer' => 'With monthly billing there is no long-term contract. You can cancel at the end of the period you have already paid for, and the subscription stays active until then. Online ordering and the customer account are not public yet, so for now we agree cancellation when you get in touch. The details are set out in the terms.',
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
