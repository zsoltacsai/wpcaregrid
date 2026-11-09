<?php

namespace Tests\Unit;

use App\Pricing\StripeCatalogue\CatalogueMatcher;
use App\Pricing\StripeCatalogue\RemotePrice;
use App\Pricing\StripeCatalogue\RemoteProduct;
use App\Pricing\StripeCatalogue\StripeMode;
use PHPUnit\Framework\TestCase;

/** The matching rules, with no Stripe, database or framework. */
class StripeCatalogueMatcherTest extends TestCase
{
    private const KEY = 'wordpress.basic.monthly.hu';

    private function entry(array $override = []): array
    {
        return $override + [
            'key' => self::KEY, 'product_type' => 'wordpress', 'tier' => 'basic', 'interval' => 'monthly', 'market' => 'hu',
            'currency' => 'HUF', 'amount' => 899000, 'current_id' => null, 'draft' => false, 'label' => 'WordPress Basic · monthly · HUF',
        ];
    }

    private function price(string $id, array $o = []): RemotePrice
    {
        $o += ['product' => 'prod_wp', 'currency' => 'HUF', 'amount' => 899000, 'interval' => 'month', 'metadata' => [], 'active' => true, 'type' => 'recurring'];

        return new RemotePrice($id, $o['product'], $o['currency'], $o['amount'], $o['active'], $o['type'], $o['type'] === 'recurring' ? $o['interval'] : null, 1, metadata: $o['metadata']);
    }

    private function wordpress(): RemoteProduct
    {
        return new RemoteProduct('prod_wp', 'WordPress', true, [CatalogueMatcher::PRODUCT_TYPE_METADATA => 'wordpress']);
    }

    /** @return array<string, mixed> the plan for one catalogue price against the given Stripe objects */
    private function plan(array $prices, array $entry = [], array $products = [], array $mapped = []): array
    {
        return (new CatalogueMatcher)->plan(StripeMode::Test, [$this->entry($entry)], $mapped, $products ?: [$this->wordpress()], $prices);
    }

    private function outcome(array $plan): array
    {
        return $plan['prices'][self::KEY];
    }

    public function test_a_price_with_our_key_and_matching_attributes_is_an_exact_match_to_link(): void
    {
        $plan = $this->plan([$this->price('price_1', ['metadata' => [CatalogueMatcher::PRICE_KEY_METADATA => self::KEY]])]);

        $this->assertSame('exact', $this->outcome($plan)['state']);
        $this->assertSame('metadata', $this->outcome($plan)['via']);
        $this->assertSame('price_1', $this->outcome($plan)['stripe_price_id']);
        $this->assertSame('link_price:'.self::KEY, $plan['actions'][1]['id']);
    }

    public function test_a_price_without_metadata_matches_on_product_currency_amount_and_interval(): void
    {
        $plan = $this->plan([$this->price('price_1')]);

        $this->assertSame(['exact', 'attributes'], [$this->outcome($plan)['state'], $this->outcome($plan)['via']]);
    }

    public function test_name_and_amount_alone_never_match_a_price_of_another_product(): void
    {
        $plan = $this->plan([$this->price('price_other', ['product' => 'prod_other'])]);

        $this->assertSame('missing', $this->outcome($plan)['state']);
        $this->assertSame('create', $this->outcome($plan)['action']);
    }

    public function test_a_missing_product_is_proposed_before_its_prices(): void
    {
        $plan = $this->plan([], products: [new RemoteProduct('prod_x', 'Something else', true)]);

        $this->assertSame('missing', $plan['products']['wordpress']['state']);
        $this->assertSame(['create_product:wordpress', 'create_price:'.self::KEY], array_column($plan['actions'], 'id'));
        $this->assertSame('create_product:wordpress', $plan['actions'][1]['depends_on']);
    }

    public function test_a_missing_price_under_an_existing_product_is_proposed(): void
    {
        $plan = $this->plan([]);

        $this->assertSame('missing', $this->outcome($plan)['state']);
        $this->assertSame('create_price:'.self::KEY, $plan['actions'][1]['id'] ?? $plan['actions'][0]['id']);
    }

    public function test_two_fitting_prices_are_ambiguous_and_nothing_is_chosen(): void
    {
        $plan = $this->plan([$this->price('price_1'), $this->price('price_2')]);

        $this->assertSame('ambiguous', $this->outcome($plan)['state']);
        $this->assertSame(['price_1', 'price_2'], $this->outcome($plan)['candidates']);
        $this->assertNull($this->outcome($plan)['action']);
        $this->assertSame([], array_filter($plan['actions'], fn ($a) => str_contains($a['id'], 'price:')));
    }

    public function test_two_active_prices_with_our_key_are_ambiguous(): void
    {
        $metadata = [CatalogueMatcher::PRICE_KEY_METADATA => self::KEY];
        $plan = $this->plan([$this->price('price_1', ['metadata' => $metadata]), $this->price('price_2', ['metadata' => $metadata])]);

        $this->assertSame(['ambiguous', 'duplicate_metadata'], [$this->outcome($plan)['state'], $this->outcome($plan)['reason']]);
    }

    public function test_a_currency_difference_on_a_price_with_our_key_is_a_conflict(): void
    {
        $plan = $this->plan([$this->price('price_1', ['currency' => 'EUR', 'metadata' => [CatalogueMatcher::PRICE_KEY_METADATA => self::KEY]])]);

        $this->assertSame('conflict', $this->outcome($plan)['state']);
        $this->assertContains('currency', $this->outcome($plan)['details']);
        $this->assertNull($this->outcome($plan)['action']);
    }

    public function test_an_amount_difference_is_a_conflict_not_a_second_price(): void
    {
        $withKey = $this->plan([$this->price('price_1', ['amount' => 999000, 'metadata' => [CatalogueMatcher::PRICE_KEY_METADATA => self::KEY]])]);
        $withoutKey = $this->plan([$this->price('price_1', ['amount' => 999000])]);

        $this->assertSame(['conflict', ['amount']], [$this->outcome($withKey)['state'], $this->outcome($withKey)['details']]);
        $this->assertSame(['conflict', 'amount_differs'], [$this->outcome($withoutKey)['state'], $this->outcome($withoutKey)['reason']]);
        $this->assertNull($this->outcome($withoutKey)['action']);
    }

    public function test_a_billing_period_difference_is_a_conflict_with_our_key_and_a_different_slot_without_it(): void
    {
        $withKey = $this->plan([$this->price('price_1', ['interval' => 'year', 'metadata' => [CatalogueMatcher::PRICE_KEY_METADATA => self::KEY]])]);
        $withoutKey = $this->plan([$this->price('price_1', ['interval' => 'year'])]);

        $this->assertContains('interval', $this->outcome($withKey)['details']);
        $this->assertSame('conflict', $this->outcome($withKey)['state']);
        // The same amount yearly is simply another price: the monthly one is still missing.
        $this->assertSame('missing', $this->outcome($withoutKey)['state']);
    }

    public function test_huf_amounts_are_compared_in_stripes_unit_and_must_be_whole_forint(): void
    {
        // 8 990 Ft is 899000 in Stripe's unit; 8990 would be 89.90 Ft.
        $this->assertSame('exact', $this->outcome($this->plan([$this->price('price_1', ['amount' => 899000])]))['state']);
        $this->assertSame('conflict', $this->outcome($this->plan([$this->price('price_1', ['amount' => 8990])]))['state']);
        $this->assertSame('amount_not_supported_by_stripe', $this->outcome($this->plan([], ['amount' => 899050]))['reason']);
    }

    public function test_archived_prices_are_ignored(): void
    {
        $plan = $this->plan([$this->price('price_1', ['active' => false, 'metadata' => [CatalogueMatcher::PRICE_KEY_METADATA => self::KEY]]), $this->price('price_2', ['active' => false])]);

        $this->assertSame('missing', $this->outcome($plan)['state']);
    }

    public function test_one_time_and_metered_prices_never_match(): void
    {
        $oneTime = $this->plan([$this->price('price_1', ['type' => 'one_time'])]);
        $metered = (new CatalogueMatcher)->plan(StripeMode::Test, [$this->entry()], [], [$this->wordpress()], [new RemotePrice('price_2', 'prod_wp', 'HUF', 899000, true, 'recurring', 'month', 1, usageType: 'metered')]);

        $this->assertSame('missing', $this->outcome($oneTime)['state']);
        $this->assertSame('missing', $this->outcome($metered)['state']);
    }

    public function test_one_stripe_price_cannot_stand_for_two_catalogue_prices(): void
    {
        $plan = (new CatalogueMatcher)->plan(StripeMode::Test, [
            $this->entry(),
            $this->entry(['key' => 'wordpress.basic.monthly.eu', 'market' => 'eu']),
        ], [], [$this->wordpress()], [$this->price('price_1')]);

        $this->assertSame(['conflict', 'conflict'], array_column($plan['prices'], 'state'));
        $this->assertSame('price_shared_by_several', $plan['prices'][self::KEY]['reason']);
        $this->assertSame([], array_filter($plan['actions'], fn ($a) => str_contains($a['id'], 'link_price')));
    }

    public function test_a_price_already_in_the_catalogue_is_checked_never_replaced(): void
    {
        $same = $this->plan([$this->price('price_1')], ['current_id' => 'price_1']);
        $different = $this->plan([$this->price('price_1'), $this->price('price_2', ['amount' => 5])], ['current_id' => 'price_2']);
        $absent = $this->plan([], ['current_id' => 'price_gone']);

        $this->assertSame(['exact', true, null], [$this->outcome($same)['state'], $this->outcome($same)['already_mapped'], $this->outcome($same)['action']]);
        $this->assertSame(['conflict', 'mapped_price_differs'], [$this->outcome($different)['state'], $this->outcome($different)['reason']]);
        $this->assertSame('mapped_price_not_found', $this->outcome($absent)['reason']);
        $this->assertSame([], array_filter($different['actions'], fn ($a) => str_contains($a['id'], 'price')));
    }

    public function test_an_unpublished_draft_blocks_the_change(): void
    {
        $plan = $this->plan([$this->price('price_1')], ['draft' => true]);

        $this->assertSame(['exact', 'draft_pending', null], [$this->outcome($plan)['state'], $this->outcome($plan)['reason'], $this->outcome($plan)['action']]);
    }

    public function test_a_product_is_found_by_its_stable_metadata_and_only_hinted_by_name(): void
    {
        $named = new RemoteProduct('prod_named', 'WordPress maintenance (old)', true);
        $plan = $this->plan([], products: [$named]);

        $this->assertSame('missing', $plan['products']['wordpress']['state']);
        $this->assertSame([['id' => 'prod_named', 'name' => 'WordPress maintenance (old)']], $plan['products']['wordpress']['suggestions']);
        $this->assertSame('create', $plan['products']['wordpress']['action']);

        $tagged = $this->plan([]);
        $this->assertSame(['exact', 'metadata', 'link'], [$tagged['products']['wordpress']['state'], $tagged['products']['wordpress']['source'], $tagged['products']['wordpress']['action']]);
    }

    public function test_several_tagged_products_are_ambiguous_and_block_their_prices(): void
    {
        $plan = $this->plan([], products: [$this->wordpress(), new RemoteProduct('prod_wp2', 'WP', true, [CatalogueMatcher::PRODUCT_TYPE_METADATA => 'wordpress'])]);

        $this->assertSame('ambiguous', $plan['products']['wordpress']['state']);
        $this->assertSame(['blocked', 'product_unresolved'], [$this->outcome($plan)['state'], $this->outcome($plan)['reason']]);
        $this->assertSame([], $plan['actions']);
    }

    public function test_a_known_product_that_stripe_does_not_have_is_a_conflict(): void
    {
        $plan = $this->plan([], products: [new RemoteProduct('prod_x', 'X', true)], mapped: ['wordpress' => ['id' => 'prod_gone', 'source' => 'config']]);

        $this->assertSame(['conflict', 'mapped_product_not_found'], [$plan['products']['wordpress']['state'], $plan['products']['wordpress']['reason']]);
        $this->assertSame('blocked', $this->outcome($plan)['state']);
    }
}
