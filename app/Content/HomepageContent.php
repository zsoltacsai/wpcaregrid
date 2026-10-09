<?php

namespace App\Content;

use App\Localization\MarketingRoutes;

/**
 * Structure of the marketing homepage: which items appear, in what order,
 * with which icon or link. Wording comes from the content sections
 * (App\Content\Schema\ContentSchema): published owner content, else the
 * built-in copy in lang/{locale}/home.php, per the fallback policy in
 * ContentRepository. Icons stay tied to the page structure.
 */
final class HomepageContent
{
    public function __construct(private readonly ContentRepository $content) {}

    public static function current(): self
    {
        return new self(app(ContentRepository::class));
    }

    public function section(string $key): ContentValues
    {
        return $this->content->section($key);
    }

    /** @return list<array{label: string, href: string}> */
    public function navigation(): array
    {
        $routes = app(MarketingRoutes::class);

        return array_values(array_map(
            fn (array $item) => ['label' => (string) $item['label'], 'href' => $routes->localize(SafeUrl::orFallback($item['url'] ?? null))],
            $this->content->section('site.navigation')->items('items'),
        ));
    }

    /** @return list<array{label: string, icon: string}> */
    public function trustIndicators(): array
    {
        return $this->items('home.hero', 'trust', [
            'contract' => 'calendar',
            'connection' => 'lock',
            'business' => 'briefcase',
        ]);
    }

    /** @return list<array{title: string, text: string, icon: string}> */
    public function services(): array
    {
        return $this->items('home.services', 'items', [
            'updates' => 'refresh',
            'backups' => 'archive',
            'security' => 'shield',
            'functional' => 'clipboard-check',
        ]);
    }

    /** @return list<array{title: string, text: string, icon: string, tone: string}> */
    public function problems(): array
    {
        $tones = ['outdated' => 'warning', 'backup' => 'danger', 'updates' => 'warning'];

        return array_map(
            fn (array $item) => $item + ['tone' => $tones[$item['key']]],
            $this->items('home.why', 'items', [
                'outdated' => 'clock',
                'backup' => 'archive-x',
                'updates' => 'alert-triangle',
            ]),
        );
    }

    /** @return list<array{title: string, text: string, result: string, icon: string}> */
    public function verificationSteps(): array
    {
        return $this->items('home.update_process', 'steps', [
            'backup' => 'archive',
            'update' => 'refresh',
            'check' => 'search',
            'functional' => 'clipboard-check',
            'done' => 'check-circle',
        ]);
    }

    /** @return list<array{title: string, icon: string}> */
    public function woocommerceFlow(): array
    {
        return $this->items('home.woocommerce', 'flow', [
            'product' => 'package',
            'cart' => 'cart',
            'checkout' => 'clipboard',
            'payment' => 'credit-card',
            'order' => 'receipt',
            'email' => 'mail',
        ]);
    }

    /** @return list<array{title: string, text: string, icon: string}> */
    public function developmentServices(): array
    {
        return $this->items('home.development', 'items', [
            'plugins' => 'plug',
            'api' => 'arrows-exchange',
            'woocommerce' => 'store',
            'automation' => 'cog',
        ]);
    }

    /** @return list<array{number: string, title: string, text: string}> */
    public function steps(): array
    {
        $steps = $this->items('home.how', 'steps', ['plan' => null, 'connect' => null, 'audit' => null, 'ongoing' => null]);

        foreach ($steps as $index => $step) {
            $steps[$index]['number'] = sprintf('%02d', $index + 1);
        }

        return $steps;
    }

    /**
     * Enabled questions in their order. Answers are sanitised HTML (checked
     * again here, whatever is stored).
     *
     * @return list<array{question: string, answer: string}>
     */
    public function faqs(): array
    {
        return array_values(array_map(
            fn (array $item) => ['question' => (string) $item['question'], 'answer' => HtmlSanitizer::clean((string) $item['answer'])],
            $this->content->section('home.faq')->items('items'),
        ));
    }

    /** @return list<array{heading: string, links: list<array{label: string, href: string}>}> */
    public function footerColumns(): array
    {
        $links = config('marketing.links');
        $footer = $this->content->section('site.footer');
        $navigation = $this->content->section('site.navigation');

        return [
            [
                'heading' => (string) $footer['product_heading'],
                'links' => $this->navigation(),
            ],
            [
                'heading' => (string) $footer['account_heading'],
                'links' => [
                    ['label' => (string) $navigation['login_label'], 'href' => $links['login']],
                    ['label' => (string) $footer['contact_label'], 'href' => $links['contact']],
                ],
            ],
            [
                'heading' => (string) $footer['legal_heading'],
                'links' => [
                    ['label' => (string) $footer['terms_label'], 'href' => $links['terms']],
                    ['label' => (string) $footer['privacy_label'], 'href' => $links['privacy']],
                    ['label' => (string) $footer['cookies_label'], 'href' => $links['cookies']],
                ],
            ],
        ];
    }

    /**
     * Enabled items of a group, in page order, with their icon.
     *
     * @param  array<string, string|null>  $icons
     * @return list<array<string, mixed>>
     */
    private function items(string $section, string $field, array $icons): array
    {
        $values = $this->content->section($section)->items($field);
        $items = [];

        foreach ($icons as $key => $icon) {
            if (isset($values[$key])) {
                $items[] = ['key' => $key, 'icon' => $icon] + $values[$key];
            }
        }

        return $items;
    }
}
