<?php

namespace App\Content;

/**
 * Copy for the marketing homepage, kept out of the Blade templates so it can
 * be edited (or moved to translation files) without touching the markup.
 */
final class HomepageContent
{
    /** @return list<array{label: string, href: string}> */
    public static function navigation(): array
    {
        return [
            ['label' => 'Services', 'href' => '#services'],
            ['label' => 'Pricing', 'href' => '#pricing'],
            ['label' => 'How it works', 'href' => '#how-it-works'],
            ['label' => 'Custom Development', 'href' => '#custom-development'],
            ['label' => 'FAQ', 'href' => '#faq'],
        ];
    }

    /** @return list<array{label: string, icon: string}> */
    public static function trustIndicators(): array
    {
        return [
            ['label' => 'No long-term contract', 'icon' => 'calendar'],
            ['label' => 'Secure connection', 'icon' => 'lock'],
            ['label' => 'For businesses', 'icon' => 'briefcase'],
        ];
    }

    /** @return list<array{title: string, text: string, icon: string}> */
    public static function services(): array
    {
        return [
            ['title' => 'Updates', 'text' => 'WordPress, plugins and themes', 'icon' => 'refresh'],
            ['title' => 'Backups', 'text' => 'Files and database stored remotely', 'icon' => 'archive'],
            ['title' => 'Security', 'text' => 'Vulnerability and malware checks', 'icon' => 'shield'],
            ['title' => 'Functional checks', 'text' => 'We verify important functions after updates', 'icon' => 'clipboard-check'],
        ];
    }

    /** @return list<array{title: string, text: string, icon: string}> */
    public static function problems(): array
    {
        return [
            ['title' => 'Outdated software', 'text' => 'Outdated plugins and themes are a common entry point for attacks. Updates close known vulnerabilities.', 'icon' => 'clock'],
            ['title' => 'No recent backup', 'text' => 'Without a recent backup stored off the server, a failed update or a hosting problem can mean lost content and orders.', 'icon' => 'archive-x'],
            ['title' => 'Updates can break things', 'text' => 'A plugin update can break a form, a layout or the checkout. Often nobody notices until a customer does.', 'icon' => 'alert-triangle'],
        ];
    }

    /** @return list<array{title: string, text: string, icon: string, result: string}> */
    public static function verificationSteps(): array
    {
        return [
            ['title' => 'Backup', 'text' => 'A fresh backup is made and verified before anything changes.', 'icon' => 'archive', 'result' => 'Verified'],
            ['title' => 'Update', 'text' => 'Core, plugins and themes are updated in a controlled order.', 'icon' => 'refresh', 'result' => 'Updated'],
            ['title' => 'Check', 'text' => 'We confirm the site loads without errors.', 'icon' => 'search', 'result' => 'No errors'],
            ['title' => 'Functional test', 'text' => 'Key pages, forms, logins and checkout are tested.', 'icon' => 'clipboard-check', 'result' => 'Passed'],
            ['title' => 'Done', 'text' => 'The result is recorded in your activity log.', 'icon' => 'check-circle', 'result' => 'Logged'],
        ];
    }

    /** @return list<array{title: string, icon: string}> */
    public static function woocommerceFlow(): array
    {
        return [
            ['title' => 'Product', 'icon' => 'package'],
            ['title' => 'Cart', 'icon' => 'cart'],
            ['title' => 'Checkout', 'icon' => 'clipboard'],
            ['title' => 'Payment', 'icon' => 'credit-card'],
            ['title' => 'Order', 'icon' => 'receipt'],
            ['title' => 'Email', 'icon' => 'mail'],
        ];
    }

    /** @return list<array{title: string, text: string, icon: string}> */
    public static function developmentServices(): array
    {
        return [
            ['title' => 'Custom plugins', 'text' => 'Functionality built for your site, following WordPress coding standards.', 'icon' => 'plug'],
            ['title' => 'API integrations', 'text' => 'Connect WordPress to invoicing, CRM, shipping or inventory systems.', 'icon' => 'arrows-exchange'],
            ['title' => 'WooCommerce development', 'text' => 'Custom checkout steps, product types, pricing rules and extensions.', 'icon' => 'store'],
            ['title' => 'Automation', 'text' => 'Scheduled tasks, imports and exports that replace manual work.', 'icon' => 'cog'],
        ];
    }

    /** @return list<array{number: string, title: string, text: string}> */
    public static function steps(): array
    {
        return [
            ['number' => '01', 'title' => 'Choose a plan', 'text' => 'Pick the plan that fits your site. Monthly or annual billing.'],
            ['number' => '02', 'title' => 'Connect your website', 'text' => 'Connect your site through a secure connection. No need to share your admin password.'],
            ['number' => '03', 'title' => 'Initial check', 'text' => 'We review your site, make a first backup and note anything that needs attention.'],
            ['number' => '04', 'title' => 'Ongoing maintenance', 'text' => 'Updates, backups and checks run on schedule. Everything is logged.'],
        ];
    }

    /** @return list<array{question: string, answer: string}> */
    public static function faqs(): array
    {
        return [
            [
                'question' => 'Do I need to give you my WordPress password?',
                'answer' => 'No. You connect your website through a secure connection that you can revoke at any time. You do not need to send us your administrator password.',
            ],
            [
                'question' => 'What happens if an update breaks my website?',
                'answer' => 'Every update is preceded by a backup. If a check after the update fails, we restore the last working state and investigate the cause before trying the update again.',
            ],
            [
                'question' => 'Where are backups stored?',
                'answer' => 'Backups are stored remotely, separate from your hosting server, so a server problem does not take your backups with it.',
            ],
            [
                'question' => 'How often are backups made?',
                'answer' => 'WordPress Basic includes weekly backups; all other plans include daily backups. A backup is also made before every update.',
            ],
            [
                'question' => 'What happens if a plugin license expires?',
                'answer' => 'We let you know when we find an expired plugin or theme license. Renewing third-party licenses remains your responsibility. Until a license is renewed, that plugin or theme may not receive updates.',
            ],
            [
                'question' => 'What happens if my website is infected?',
                'answer' => 'We notify you as soon as a security check finds a problem and agree the next steps with you. Malware cleanup is included in Secure plans; on other plans we quote it separately.',
            ],
            [
                'question' => 'Can you develop custom plugins?',
                'answer' => 'Yes. We build custom plugins, API integrations and WooCommerce extensions. Tell us what you need and we will reply with a proposal.',
            ],
        ];
    }

    /** @return list<array{heading: string, links: list<array{label: string, href: string}>}> */
    public static function footerColumns(): array
    {
        $links = config('marketing.links');

        return [
            [
                'heading' => 'Product',
                'links' => [
                    ['label' => 'Services', 'href' => '#services'],
                    ['label' => 'Pricing', 'href' => '#pricing'],
                    ['label' => 'How it works', 'href' => '#how-it-works'],
                    ['label' => 'Custom Development', 'href' => '#custom-development'],
                    ['label' => 'FAQ', 'href' => '#faq'],
                ],
            ],
            [
                'heading' => 'Account',
                'links' => [
                    ['label' => 'Login', 'href' => $links['login']],
                    ['label' => 'Contact', 'href' => $links['contact']],
                ],
            ],
            [
                'heading' => 'Legal',
                'links' => [
                    ['label' => 'Terms', 'href' => $links['terms']],
                    ['label' => 'Privacy', 'href' => $links['privacy']],
                    ['label' => 'Cookie Policy', 'href' => $links['cookies']],
                ],
            ],
        ];
    }
}
