<?php

namespace App\Content;

/**
 * Structure of the marketing homepage: which items appear, in what order,
 * with which icon or link. All wording comes from lang/{locale}/home.php, so
 * a new language only needs a translation file.
 */
final class HomepageContent
{
    /** @return list<array{label: string, href: string}> */
    public static function navigation(): array
    {
        return [
            ['label' => __('home.nav.services'), 'href' => '#services'],
            ['label' => __('home.nav.pricing'), 'href' => '#pricing'],
            ['label' => __('home.nav.how_it_works'), 'href' => '#how-it-works'],
            ['label' => __('home.nav.custom_development'), 'href' => '#custom-development'],
            ['label' => __('home.nav.faq'), 'href' => '#faq'],
        ];
    }

    /** @return list<array{label: string, icon: string}> */
    public static function trustIndicators(): array
    {
        return self::items('home.hero.trust', [
            'contract' => 'calendar',
            'connection' => 'lock',
            'business' => 'briefcase',
        ], fn (string $text) => ['label' => $text]);
    }

    /** @return list<array{title: string, text: string, icon: string}> */
    public static function services(): array
    {
        return self::items('home.services.items', [
            'updates' => 'refresh',
            'backups' => 'archive',
            'security' => 'shield',
            'functional' => 'clipboard-check',
        ]);
    }

    /** @return list<array{title: string, text: string, icon: string, tone: string}> */
    public static function problems(): array
    {
        $tones = ['outdated' => 'warning', 'backup' => 'danger', 'updates' => 'warning'];

        return array_map(
            fn (array $item) => $item + ['tone' => $tones[$item['key']]],
            self::items('home.why.items', [
                'outdated' => 'clock',
                'backup' => 'archive-x',
                'updates' => 'alert-triangle',
            ]),
        );
    }

    /** @return list<array{title: string, text: string, result: string, icon: string}> */
    public static function verificationSteps(): array
    {
        return self::items('home.verification.steps', [
            'backup' => 'archive',
            'update' => 'refresh',
            'check' => 'search',
            'functional' => 'clipboard-check',
            'done' => 'check-circle',
        ]);
    }

    /** @return list<array{title: string, icon: string}> */
    public static function woocommerceFlow(): array
    {
        return self::items('home.woocommerce.flow', [
            'product' => 'package',
            'cart' => 'cart',
            'checkout' => 'clipboard',
            'payment' => 'credit-card',
            'order' => 'receipt',
            'email' => 'mail',
        ], fn (string $text) => ['title' => $text]);
    }

    /** @return list<array{title: string, text: string, icon: string}> */
    public static function developmentServices(): array
    {
        return self::items('home.development.items', [
            'plugins' => 'plug',
            'api' => 'arrows-exchange',
            'woocommerce' => 'store',
            'automation' => 'cog',
        ]);
    }

    /** @return list<array{number: string, title: string, text: string}> */
    public static function steps(): array
    {
        $steps = self::items('home.how.steps', ['plan' => null, 'connect' => null, 'audit' => null, 'ongoing' => null]);

        foreach ($steps as $index => $step) {
            $steps[$index]['number'] = sprintf('%02d', $index + 1);
        }

        return $steps;
    }

    /** @return list<array{question: string, answer: string}> */
    public static function faqs(): array
    {
        return array_values((array) __('home.faq.items'));
    }

    /** @return list<array{heading: string, links: list<array{label: string, href: string}>}> */
    public static function footerColumns(): array
    {
        $links = config('marketing.links');

        return [
            [
                'heading' => __('home.footer.columns.product'),
                'links' => self::navigation(),
            ],
            [
                'heading' => __('home.footer.columns.account'),
                'links' => [
                    ['label' => __('home.nav.login'), 'href' => $links['login']],
                    ['label' => __('home.footer.links.contact'), 'href' => $links['contact']],
                ],
            ],
            [
                'heading' => __('home.footer.columns.legal'),
                'links' => [
                    ['label' => __('home.footer.links.terms'), 'href' => $links['terms']],
                    ['label' => __('home.footer.links.privacy'), 'href' => $links['privacy']],
                    ['label' => __('home.footer.links.cookies'), 'href' => $links['cookies']],
                ],
            ],
        ];
    }

    /**
     * Pair each key with its icon and translated text, keeping the order of
     * $icons (the page structure) rather than the translation file.
     *
     * @param  array<string, string|null>  $icons
     * @param  (callable(mixed): array<string, mixed>)|null  $map  Turns a translation value into fields.
     * @return list<array<string, mixed>>
     */
    private static function items(string $key, array $icons, ?callable $map = null): array
    {
        $text = (array) __($key);
        $items = [];

        foreach ($icons as $itemKey => $icon) {
            $fields = $map ? $map($text[$itemKey]) : $text[$itemKey];
            $items[] = ['key' => $itemKey, 'icon' => $icon] + $fields;
        }

        return $items;
    }
}
