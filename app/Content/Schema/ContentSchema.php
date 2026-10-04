<?php

namespace App\Content\Schema;

use App\Pricing\PlanTier;
use App\Pricing\PricingCatalog;
use App\Pricing\ProductType;
use Illuminate\Support\Facades\Lang;

/**
 * Every editable section and its fields. The public pages are built from
 * these sections; the owner editor is generated from the same definitions,
 * so a field cannot be edited that the page does not use, and the page
 * cannot read a field the editor does not validate.
 *
 * Built-in copy comes from lang/{locale}/*.php, so a locale whose
 * translation files have no copy has no built-in content (it is "missing"
 * in the editor, and the public page uses the fallback locale).
 */
final class ContentSchema
{
    public const GROUPS = ['homepage', 'site', 'pricing', 'seo'];

    public const ROBOTS = ['index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'];

    /** @var array<string, Section>|null */
    private static ?array $sections = null;

    /** @return array<string, Section> */
    public static function sections(): array
    {
        return self::$sections ??= self::build();
    }

    public static function section(string $key): ?Section
    {
        return self::sections()[$key] ?? null;
    }

    /** @return array<string, Section> */
    public static function group(string $group): array
    {
        return array_filter(self::sections(), fn (Section $section) => $section->group === $group);
    }

    /** The section's name for the editor (lang/{locale}/content.php, "home.hero" → "home_hero"). */
    public static function label(string $key): string
    {
        $translation = 'content.sections.'.str_replace('.', '_', $key);

        return __($translation) !== $translation ? __($translation) : $key;
    }

    /** @return list<string> */
    public static function locales(): array
    {
        return array_keys((array) config('content.locales'));
    }

    public static function fallbackLocale(): string
    {
        return (string) config('content.fallback_locale', 'en');
    }

    /** @return array<string, Section> */
    private static function build(): array
    {
        $contact = fn () => (string) config('marketing.links.contact');
        $sections = [
            self::make('home.hero', 'homepage', [
                Field::text('eyebrow', max: 80),
                Field::text('title'),
                Field::textarea('description', max: 400),
                Field::text('primary_cta_label', max: 40),
                Field::url('primary_cta_url'),
                Field::text('secondary_cta_label', max: 40),
                Field::url('secondary_cta_url'),
                Field::group('trust', ['contract', 'connection', 'business'], [Field::text('label', max: 60), Field::boolean('enabled')]),
            ], 'home', fn ($t) => [
                'eyebrow' => $t('home.tagline'),
                'title' => $t('home.hero.title'),
                'description' => $t('home.hero.text'),
                'primary_cta_label' => $t('home.hero.primary_cta'),
                'primary_cta_url' => '#pricing',
                'secondary_cta_label' => $t('home.hero.secondary_cta'),
                'secondary_cta_url' => '#how-it-works',
                'trust' => array_map(fn ($label) => ['label' => $label, 'enabled' => true], (array) $t('home.hero.trust')),
            ]),
            self::make('home.services', 'homepage', [
                Field::text('title'),
                Field::group('items', ['updates', 'backups', 'security', 'functional'], [Field::text('title', max: 60), Field::text('text', max: 160), Field::boolean('enabled')]),
            ], 'home', fn ($t) => [
                'title' => $t('home.services.title'),
                'items' => self::enabled((array) $t('home.services.items')),
            ]),
            self::make('home.why', 'homepage', [
                Field::text('eyebrow', max: 60),
                Field::text('title'),
                Field::textarea('body', max: 400),
                Field::group('items', ['outdated', 'backup', 'updates'], [Field::text('title', max: 80), Field::textarea('text', max: 300)]),
            ], 'home', fn ($t) => [
                'eyebrow' => $t('home.why.eyebrow'),
                'title' => $t('home.why.title'),
                'body' => $t('home.why.lead'),
                'items' => (array) $t('home.why.items'),
            ]),
            self::make('home.update_process', 'homepage', [
                Field::text('eyebrow', max: 60),
                Field::text('title'),
                Field::textarea('description', max: 400),
                Field::group('steps', ['backup', 'update', 'check', 'functional', 'done'], [Field::text('title', max: 60), Field::textarea('text', max: 200), Field::text('result', max: 30)]),
            ], 'home', fn ($t) => [
                'eyebrow' => $t('home.verification.eyebrow'),
                'title' => $t('home.verification.title'),
                'description' => $t('home.verification.lead'),
                'steps' => (array) $t('home.verification.steps'),
            ]),
            self::make('home.pricing_intro', 'homepage', [
                Field::text('eyebrow', max: 60),
                Field::text('title'),
                Field::textarea('description', max: 400),
            ], 'home', fn ($t) => [
                'eyebrow' => $t('home.pricing.eyebrow'),
                'title' => $t('home.pricing.title'),
                'description' => $t('home.pricing.lead'),
            ]),
            self::make('home.dashboard', 'homepage', [
                Field::text('eyebrow', max: 60),
                Field::text('title'),
                Field::textarea('description', max: 400),
                Field::text('demo_note', max: 200),
            ], 'home', fn ($t) => [
                'eyebrow' => $t('home.dashboard.eyebrow'),
                'title' => $t('home.dashboard.title'),
                'description' => $t('home.dashboard.lead'),
                'demo_note' => $t('home.dashboard.demo_note'),
            ]),
            self::make('home.licenses', 'homepage', [
                Field::text('eyebrow', max: 60),
                Field::text('title'),
                Field::textarea('description', max: 600),
                Field::textarea('limitation', max: 400),
                Field::textarea('disclaimer', max: 400),
            ], 'home', fn ($t) => [
                'eyebrow' => $t('home.licenses.eyebrow'),
                'title' => $t('home.licenses.title'),
                'description' => $t('home.licenses.lead'),
                'limitation' => $t('home.licenses.limitation'),
                'disclaimer' => $t('home.licenses.responsibility'),
            ]),
            self::make('home.woocommerce', 'homepage', [
                Field::text('eyebrow', max: 60),
                Field::text('title'),
                Field::textarea('description', max: 400),
                Field::text('cta_label', max: 40),
                Field::group('flow', ['product', 'cart', 'checkout', 'payment', 'order', 'email'], [Field::text('title', max: 30)]),
            ], 'home', fn ($t) => [
                'eyebrow' => $t('home.woocommerce.eyebrow'),
                'title' => $t('home.woocommerce.title'),
                'description' => $t('home.woocommerce.lead'),
                'cta_label' => $t('home.woocommerce.cta'),
                'flow' => array_map(fn ($title) => ['title' => $title], (array) $t('home.woocommerce.flow')),
            ]),
            self::make('home.development', 'homepage', [
                Field::text('eyebrow', max: 60),
                Field::text('title'),
                Field::textarea('description', max: 400),
                Field::text('cta_label', max: 40),
                // Contact links may be unset (MARKETING_CONTACT_URL): optional, like the config.
                Field::url('cta_url', false),
                Field::textarea('note', false, 300),
                Field::group('items', ['plugins', 'api', 'woocommerce', 'automation'], [Field::text('title', max: 60), Field::text('text', max: 160), Field::boolean('enabled')]),
            ], 'home', fn ($t) => [
                'eyebrow' => $t('home.development.eyebrow'),
                'title' => $t('home.development.title'),
                'description' => $t('home.development.lead'),
                'cta_label' => $t('home.development.cta'),
                'cta_url' => $contact(),
                'note' => $t('home.development.note'),
                'items' => self::enabled((array) $t('home.development.items')),
            ]),
            self::make('home.how', 'homepage', [
                Field::text('eyebrow', max: 60),
                Field::text('title'),
                Field::group('steps', ['plan', 'connect', 'audit', 'ongoing'], [Field::text('title', max: 60), Field::text('text', max: 160)]),
            ], 'home', fn ($t) => [
                'eyebrow' => $t('home.how.eyebrow'),
                'title' => $t('home.how.title'),
                'steps' => (array) $t('home.how.steps'),
            ]),
            self::make('home.activity', 'homepage', [
                Field::text('eyebrow', max: 60),
                Field::text('title'),
                Field::textarea('description', max: 400),
            ], 'home', fn ($t) => [
                'eyebrow' => $t('home.activity.eyebrow'),
                'title' => $t('home.activity.title'),
                'description' => $t('home.activity.lead'),
            ]),
            self::make('home.faq', 'homepage', [
                Field::text('eyebrow', max: 60),
                Field::text('title'),
                Field::text('contact_prompt', max: 120),
                Field::text('contact_label', max: 40),
                Field::url('contact_url', false),
                Field::repeater('items', [
                    Field::text('question', max: 200),
                    Field::richText('answer'),
                    Field::boolean('enabled'),
                    Field::number('position', 1, 99),
                ], 30),
            ], 'home', fn ($t) => [
                'eyebrow' => $t('home.faq.eyebrow'),
                'title' => $t('home.faq.title'),
                'contact_prompt' => $t('home.faq.contact_prompt'),
                'contact_label' => $t('home.faq.contact_link'),
                'contact_url' => $contact(),
                'items' => array_map(
                    fn (array $item, int $index) => ['question' => $item['question'], 'answer' => e($item['answer']), 'enabled' => true, 'position' => $index + 1],
                    array_values((array) $t('home.faq.items')),
                    array_keys(array_values((array) $t('home.faq.items'))),
                ),
            ]),
            self::make('home.final_cta', 'homepage', [
                Field::text('title'),
                Field::textarea('description', max: 300),
                Field::text('primary_label', max: 40),
                Field::url('primary_url'),
                Field::text('secondary_label', max: 40),
                Field::url('secondary_url', false),
            ], 'home', fn ($t) => [
                'title' => $t('home.cta.title'),
                'description' => $t('home.cta.text'),
                'primary_label' => $t('home.cta.primary'),
                'primary_url' => '#pricing',
                'secondary_label' => $t('home.cta.secondary'),
                'secondary_url' => $contact(),
            ]),
            self::make('site.navigation', 'site', [
                Field::repeater('items', [
                    Field::text('label', max: 40),
                    Field::url('url'),
                    Field::boolean('enabled'),
                    Field::number('position', 1, 99),
                ], 10),
                Field::text('login_label', max: 30),
                Field::text('cta_label', max: 30),
            ], 'home', fn ($t) => [
                'items' => array_map(
                    fn (array $item, int $index) => $item + ['enabled' => true, 'position' => $index + 1],
                    $nav = [
                        ['label' => $t('home.nav.services'), 'url' => '/#services'],
                        ['label' => $t('home.nav.pricing'), 'url' => '/#pricing'],
                        ['label' => $t('home.nav.how_it_works'), 'url' => '/#how-it-works'],
                        ['label' => $t('home.nav.custom_development'), 'url' => '/#custom-development'],
                        ['label' => $t('home.nav.faq'), 'url' => '/#faq'],
                    ],
                    array_keys($nav),
                ),
                'login_label' => $t('home.nav.login'),
                'cta_label' => $t('home.nav.get_started'),
            ]),
            self::make('site.footer', 'site', [
                Field::text('description', max: 120),
                Field::text('descriptor', max: 60),
                Field::text('product_heading', max: 40),
                Field::text('account_heading', max: 40),
                Field::text('legal_heading', max: 40),
                Field::text('contact_label', max: 40),
                Field::text('terms_label', max: 40),
                Field::text('privacy_label', max: 40),
                Field::text('cookies_label', max: 40),
            ], 'home', fn ($t) => [
                'description' => $t('home.tagline'),
                'descriptor' => $t('home.descriptor'),
                'product_heading' => $t('home.footer.columns.product'),
                'account_heading' => $t('home.footer.columns.account'),
                'legal_heading' => $t('home.footer.columns.legal'),
                'contact_label' => $t('home.footer.links.contact'),
                'terms_label' => $t('home.footer.links.terms'),
                'privacy_label' => $t('home.footer.links.privacy'),
                'cookies_label' => $t('home.footer.links.cookies'),
            ]),
            self::seo('seo.home', 'home', fn ($t) => [$t('home.meta.title'), $t('home.meta.description')]),
            self::seo('seo.pricing', 'checkout', fn ($t) => [$t('checkout.meta.pricing_title'), $t('checkout.meta.pricing_description')]),
        ];

        foreach (ProductType::cases() as $product) {
            foreach (PlanTier::cases() as $tier) {
                $sections[] = self::make("pricing.{$product->value}.{$tier->value}", 'pricing', [
                    Field::text('name', max: 40),
                    Field::textarea('description', max: 200),
                    Field::text('badge', false, 30),
                    Field::text('cta_label', false, 40),
                    Field::repeater('features', [Field::text('label', max: 120)], 15),
                ], 'pricing', function ($t, string $locale) use ($product, $tier) {
                    $plan = (array) config("pricing.plans.{$product->value}.{$tier->value}");

                    return [
                        'name' => $t("pricing.plans.{$product->value}.{$tier->value}.name"),
                        'description' => $t("pricing.plans.{$product->value}.{$tier->value}.description"),
                        'badge' => isset($plan['badge']) ? $t("pricing.badges.{$plan['badge']}") : '',
                        'cta_label' => '',
                        'features' => array_map(fn (string $key) => ['label' => PricingCatalog::featureLabel($key, $plan, $locale)], $plan['features'] ?? []),
                    ];
                });
            }
        }

        $keyed = [];
        foreach ($sections as $section) {
            $keyed[$section->key] = $section;
        }

        return $keyed;
    }

    /**
     * @param  list<Field>  $fields
     * @param  string  $file  Translation file that holds the built-in copy.
     * @param  callable(callable(string): mixed, string): array<string, mixed>  $copy
     */
    private static function make(string $key, string $group, array $fields, string $file, callable $copy): Section
    {
        return new Section($key, $group, $fields, function (string $locale) use ($file, $copy) {
            if (! Lang::has($file, $locale, false)) {
                return null;
            }

            return $copy(fn (string $key) => trans($key, [], $locale), $locale);
        });
    }

    private static function seo(string $key, string $file, callable $defaults): Section
    {
        return self::make($key, 'seo', [
            Field::text('title', max: 120),
            Field::textarea('description', max: 320),
            Field::url('canonical_url', false, sameHost: true),
            Field::select('robots', self::ROBOTS),
            Field::text('og_title', false, 120),
            Field::textarea('og_description', false, 320),
        ], $file, function ($t) use ($defaults) {
            [$title, $description] = $defaults($t);

            return ['title' => $title, 'description' => $description, 'canonical_url' => '', 'robots' => 'index,follow', 'og_title' => '', 'og_description' => ''];
        });
    }

    /** @param  array<string, array<string, mixed>>  $items */
    private static function enabled(array $items): array
    {
        return array_map(fn (array $item) => $item + ['enabled' => true], $items);
    }
}
