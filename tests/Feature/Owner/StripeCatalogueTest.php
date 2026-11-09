<?php

namespace Tests\Feature\Owner;

use App\Models\AdminAuditEvent;
use App\Models\PriceDefinition;
use App\Models\StripeCatalogueOperation;
use App\Models\StripeCatalogueProduct;
use App\Models\User;
use App\Owner\IntegrationCredentials;
use App\Pricing\BillingInterval;
use App\Pricing\PlanTier;
use App\Pricing\PricingCatalog;
use App\Pricing\ProductType;
use App\Pricing\StripeCatalogue\CatalogueGuard;
use App\Pricing\StripeCatalogue\CatalogueMatcher;
use App\Pricing\StripeCatalogue\StripeMode;
use App\Pricing\StripePriceVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Concerns\ActsAsOwner;
use Tests\Fakes\FakeCatalogueGuard;
use Tests\Fakes\FakeStripeCatalogue;
use Tests\Fakes\FakeStripePriceVerifier;
use Tests\TestCase;

/** Owner → Pricing → Stripe Catalogue: dry run, matching, approved creation, isolation and safety. */
class StripeCatalogueTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    private const SECRET = 'sk_test_ownermanaged1234567890';

    private FakeStripeCatalogue $stripe;

    private FakeCatalogueGuard $guard;

    private FakeStripePriceVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.mode' => 'test',
            'services.stripe.secret' => 'sk_test_forverifier',
            'services.stripe.catalogue.apply_enabled' => true,
            'services.stripe.catalogue.live_apply_approved' => false,
            'pricing.stripe_products' => ['wordpress' => null, 'woocommerce' => null],
        ]);
        // The Owner-managed (encrypted) secret is the one the catalogue manager uses.
        app(IntegrationCredentials::class)->store('stripe_secret', self::SECRET);

        $this->stripe = new FakeStripeCatalogue;
        $this->guard = new FakeCatalogueGuard(app(IntegrationCredentials::class), $this->stripe);
        $this->app->instance(CatalogueGuard::class, $this->guard);
        $this->verifier = new FakeStripePriceVerifier;
        $this->app->instance(StripePriceVerifier::class, $this->verifier);

        $this->asOwner();
    }

    // ---------------------------------------------------------------- helpers

    private function preview(): StripeCatalogueOperation
    {
        $this->post(route('owner.pricing.stripe-catalogue.preview'))->assertRedirect();

        return StripeCatalogueOperation::query()->latest('created_at')->latest('id')->firstOrFail();
    }

    /** @param  list<string>|null  $items */
    private function apply(StripeCatalogueOperation $operation, ?array $items = null, array $extra = [])
    {
        $items ??= array_column($operation->plan['actions'], 'id');

        return $this->post(route('owner.pricing.stripe-catalogue.apply', $operation), $extra + [
            'items' => $items,
            'confirm' => '1',
            'confirm_text' => 'APPLY '.strtoupper($operation->mode),
        ]);
    }

    private function seedTaggedProducts(): void
    {
        $this->stripe->addProduct('prod_wordpress', 'WP CareGrid WordPress maintenance', [CatalogueMatcher::PRODUCT_TYPE_METADATA => 'wordpress']);
        $this->stripe->addProduct('prod_woocommerce', 'WP CareGrid WooCommerce maintenance', [CatalogueMatcher::PRODUCT_TYPE_METADATA => 'woocommerce']);
    }

    /** The sandbox as the Owner described it: one untagged WordPress product with 24 correct prices. */
    private function seedLegacyWordPress(): void
    {
        $this->stripe->addProduct('prod_legacy', 'WordPress');
        $n = 0;
        foreach (config('pricing.prices') as $row) {
            if ($row['product_type'] === 'wordpress') {
                $id = 'price_wordpress_legacy_'.++$n;
                $this->stripe->addPrice($id, 'prod_legacy', $row['currency'], $row['amount'], $row['interval'] === 'annual' ? 'year' : 'month');
                $this->verifier->products[$id] = 'prod_legacy';
            }
        }
    }

    private function catalogue(): PricingCatalog
    {
        $this->app->forgetInstance(PricingCatalog::class);

        return app(PricingCatalog::class);
    }

    private function auditCount(string $event): int
    {
        return AdminAuditEvent::where('event', $event)->count();
    }

    // ---------------------------------------------------------------- dry run

    public function test_the_first_run_is_a_dry_run_that_creates_and_links_nothing(): void
    {
        $this->stripe->addProduct('prod_other', 'Unrelated');
        $customer = $this->customerWithWebsite();
        $before = $customer['subscription']->fresh()->getAttributes();

        $operation = $this->preview();

        $this->assertSame(StripeCatalogueOperation::PREVIEWED, $operation->status);
        $this->assertSame('test', $operation->mode);
        $this->assertSame(48, $operation->plan['summary']['total_prices']);
        $this->assertSame(0, $this->stripe->created('createProduct') + $this->stripe->created('createPrice'));
        $this->assertSame(0, PriceDefinition::count());
        $this->assertSame(0, StripeCatalogueProduct::count());
        $this->assertEquals($before, $customer['subscription']->fresh()->getAttributes());
        $this->assertSame(1, $this->auditCount('stripe_catalogue.previewed'));
        $this->assertSame(0, $this->auditCount('stripe_catalogue.object_created'));
    }

    public function test_everything_missing_is_listed_with_the_two_products_before_the_forty_eight_prices(): void
    {
        $plan = $this->preview()->plan;

        $this->assertSame(['create_price' => 48, 'create_product' => 2], $plan['summary']['actions']);
        $this->assertEqualsCanonicalizing(['create_product:wordpress', 'create_product:woocommerce'], array_slice(array_column($plan['actions'], 'id'), 0, 2));
        $this->assertSame(['missing' => 48], $plan['summary']['prices']);
    }

    public function test_the_preview_page_shows_every_state_and_the_ids(): void
    {
        $this->seedTaggedProducts();
        $meta = fn (string $key) => [CatalogueMatcher::PRICE_KEY_METADATA => $key];
        $this->stripe->addPrice('price_ok', 'prod_wordpress', 'HUF', 899000, 'month', $meta('wordpress.basic.monthly.hu'));
        $this->stripe->addPrice('price_a1', 'prod_wordpress', 'HUF', 8990000, 'year');
        $this->stripe->addPrice('price_a2', 'prod_wordpress', 'HUF', 8990000, 'year');
        $this->stripe->addPrice('price_bad', 'prod_wordpress', 'HUF', 1, 'month', $meta('wordpress.business.monthly.hu'));

        $operation = $this->preview();
        $page = $this->get(route('owner.pricing.stripe-catalogue.show', $operation))->assertOk();

        $page->assertSee('Exact match')->assertSee('Ambiguous')->assertSee('Conflict')->assertSee('Missing')
            ->assertSee('price_ok')->assertSee('price_a1')->assertSee('Sandbox (test)')->assertSee('Dry run')
            ->assertDontSee(self::SECRET);
        // price_bad conflicts by its key; the other two yearly HUF prices of the product conflict because
        // a differently priced price already sits in their slot (Product, currency, period).
        $this->assertSame(['ambiguous' => 1, 'conflict' => 3, 'exact' => 1, 'missing' => 43], $operation->plan['summary']['prices']);
    }

    public function test_the_index_page_shows_the_environment_and_that_changes_are_off_by_default(): void
    {
        config(['services.stripe.catalogue.apply_enabled' => false]);

        $this->get(route('owner.pricing.stripe-catalogue.index'))->assertOk()
            ->assertSee('Sandbox (test)')->assertSee('Off (dry run only)')->assertSee('Load Stripe catalogue (dry run)');
    }

    // ---------------------------------------------------------------- approved execution

    public function test_an_approved_run_creates_the_products_and_all_prices_and_links_their_ids(): void
    {
        $customer = $this->customerWithWebsite();
        $before = $customer['subscription']->fresh()->getAttributes();
        $operation = $this->preview();

        // Nothing the browser sends besides the operation and the approved items matters.
        $this->apply($operation, extra: ['amount' => 1, 'currency' => 'USD', 'mode' => 'live', 'stripe_price_id' => 'price_evil'])
            ->assertRedirect(route('owner.pricing.stripe-catalogue.show', $operation));

        $operation->refresh();
        $this->assertSame(StripeCatalogueOperation::COMPLETED, $operation->status);
        $this->assertEqualsCanonicalizing(['prod_wordpress', 'prod_woocommerce'], array_keys($this->stripe->products));
        $this->assertCount(48, $this->stripe->prices);

        $catalog = $this->catalogue();
        $this->assertCount(48, array_filter($catalog->allPrices(), fn ($price) => $price->hasStripePrice()));
        $this->assertSame(48, PriceDefinition::count());
        $this->assertSame(['test' => 2], StripeCatalogueProduct::query()->selectRaw('mode, count(*) as n')->groupBy('mode')->pluck('n', 'mode')->all());

        $huf = collect($this->stripe->prices)->first(fn ($p) => $p->metadata['wpcg_price_key'] === 'wordpress.basic.monthly.hu');
        $this->assertSame([899000, 'HUF', 'month', 'prod_wordpress', 'recurring'], [$huf->unitAmount, $huf->currency, $huf->interval, $huf->productId, $huf->type]);
        $this->assertNotContains('price_evil', array_keys($this->stripe->prices));
        foreach ($this->stripe->prices as $price) {
            $this->assertContains($price->currency, ['HUF', 'EUR', 'USD', 'GBP']);
            $this->assertGreaterThan(1, $price->unitAmount, 'the amount comes from the catalogue, not from the browser');
        }
        $this->assertEquals($before, $customer['subscription']->fresh()->getAttributes());
        $this->assertSame(2, $this->auditCount('stripe_catalogue.product_linked'));
        $this->assertSame(48, $this->auditCount('stripe_catalogue.price_linked'));
        $this->assertSame(50, $this->auditCount('stripe_catalogue.object_created'));
        $this->assertSame(1, $this->auditCount('stripe_catalogue.applied'));
    }

    public function test_a_second_run_finds_everything_mapped_and_proposes_nothing(): void
    {
        $this->apply($this->preview());
        $creates = $this->stripe->created('createPrice') + $this->stripe->created('createProduct');

        $second = $this->preview();

        $this->assertSame([], $second->plan['actions']);
        $this->assertSame(['exact' => 48], $second->plan['summary']['prices']);
        $this->assertTrue(collect($second->plan['prices'])->every(fn ($p) => $p['already_mapped']));
        $this->assertSame($creates, $this->stripe->created('createPrice') + $this->stripe->created('createProduct'));
    }

    public function test_an_old_preview_run_after_a_complete_run_creates_nothing_twice(): void
    {
        $first = $this->preview();
        $second = $this->preview();

        $this->apply($first);
        $this->apply($second)->assertRedirect();

        $this->assertCount(48, $this->stripe->prices);
        $this->assertCount(2, $this->stripe->products);
        $this->assertSame(2, $this->stripe->created('createProduct'));
        $second->refresh();
        $this->assertSame(StripeCatalogueOperation::COMPLETED, $second->status);
        $this->assertTrue(collect($second->result['items'])->every(fn ($item) => $item['code'] === 'no_longer_needed'));
    }

    public function test_the_same_preview_cannot_be_applied_twice(): void
    {
        $operation = $this->preview();
        $this->apply($operation);

        $this->apply($operation)->assertSessionHas('owner_error.code', 'OPERATION_NOT_PREVIEWED');
        $this->assertCount(48, $this->stripe->prices);
    }

    public function test_a_partial_run_reports_what_failed_and_a_rerun_completes_it_without_duplicates(): void
    {
        $this->stripe->failPrice['wordpress.basic.monthly.hu'] = 'invalid_request';
        $operation = $this->preview();

        $this->apply($operation);

        $operation->refresh();
        $this->assertSame(StripeCatalogueOperation::PARTIAL, $operation->status);
        $this->assertSame(['status' => 'failed', 'code' => 'invalid_request'], $operation->result['items']['create_price:wordpress.basic.monthly.hu']);
        $this->assertSame(47, $operation->result['done'] - 2);
        $this->assertCount(47, $this->stripe->prices);
        $this->assertSame(47, PriceDefinition::count());

        $this->stripe->failPrice = [];
        $rerun = $this->preview();
        $this->assertSame(['create_price:wordpress.basic.monthly.hu'], array_column($rerun->plan['actions'], 'id'));
        $this->apply($rerun);

        $keys = collect($this->stripe->prices)->map(fn ($p) => $p->metadata['wpcg_price_key']);
        $this->assertCount(48, $keys);
        $this->assertCount(48, $keys->unique());
        $this->assertSame(48, PriceDefinition::count());
    }

    public function test_a_rate_limit_stops_the_run_and_the_remaining_items_are_not_run(): void
    {
        $this->stripe->failPrice['wordpress.basic.annual.hu'] = 'rate_limited';
        $operation = $this->preview();

        $this->apply($operation);

        $operation->refresh();
        $items = $operation->result['items'];
        $this->assertSame('failed', $items['create_price:wordpress.basic.annual.hu']['status']);
        $this->assertSame('not_run', $items['create_price:wordpress.business.annual.hu']['status']);
        $this->assertSame(StripeCatalogueOperation::PARTIAL, $operation->status);
        $this->assertLessThan(48, count($this->stripe->prices));
    }

    public function test_a_local_database_failure_after_stripe_succeeded_never_creates_a_second_price(): void
    {
        Event::listen('eloquent.saving: '.PriceDefinition::class, function (PriceDefinition $definition) {
            if ($definition->product_type === 'wordpress' && $definition->tier === 'basic' && $definition->interval === 'monthly' && $definition->market === 'hu') {
                throw new RuntimeException('database unavailable');
            }
        });
        $operation = $this->preview();
        $this->apply($operation);

        $operation->refresh();
        $failed = $operation->result['items']['create_price:wordpress.basic.monthly.hu'];
        $this->assertSame('created_but_not_linked', $failed['code']);
        $createdId = $failed['stripe_id'];
        $this->assertArrayHasKey($createdId, $this->stripe->prices);
        $pricesCreated = $this->stripe->created('createPrice');

        // The database recovers; the next preview adopts the Price Stripe already has.
        Event::forget('eloquent.saving: '.PriceDefinition::class);
        $rerun = $this->preview();
        $this->assertSame('exact', $rerun->plan['prices']['wordpress.basic.monthly.hu']['state']);
        $this->assertSame('metadata', $rerun->plan['prices']['wordpress.basic.monthly.hu']['via']);
        $this->assertSame(['link_price:wordpress.basic.monthly.hu'], array_column($rerun->plan['actions'], 'id'));
        $this->apply($rerun);

        $this->assertSame($pricesCreated, $this->stripe->created('createPrice'), 'no second Price was created');
        $this->assertSame($createdId, $this->catalogue()->price(
            ProductType::WordPress, PlanTier::Basic, BillingInterval::Monthly, $this->catalogue()->market('hu'),
        )->stripePriceId);
    }

    public function test_existing_stripe_objects_are_never_changed_and_the_existing_wordpress_prices_are_adopted(): void
    {
        $this->seedLegacyWordPress();
        $snapshot = $this->stripe->prices;

        $first = $this->preview();
        $this->assertSame('missing', $first->plan['products']['wordpress']['state']);
        $this->assertSame('prod_legacy', $first->plan['products']['wordpress']['suggestions'][0]['id']);

        // The Owner states explicitly that prod_legacy is the WordPress product.
        $this->post(route('owner.pricing.stripe-catalogue.products.map', 'wordpress'), ['stripe_product_id' => 'prod_legacy'])->assertSessionHasNoErrors();
        $second = $this->preview();
        $this->assertSame(24, collect($second->plan['prices'])->where('via', 'attributes')->count());
        $this->assertSame(24, $second->plan['summary']['actions']['link_price']);
        $this->assertSame(25, $second->plan['summary']['actions']['create_price'] + $second->plan['summary']['actions']['create_product']);

        $this->apply($second);

        $this->assertSame(StripeCatalogueOperation::COMPLETED, $second->refresh()->status);
        $this->assertCount(48, array_filter($this->catalogue()->allPrices(), fn ($price) => $price->hasStripePrice()));
        foreach ($snapshot as $id => $price) {
            $this->assertEquals($price, $this->stripe->prices[$id], 'existing price '.$id.' is unchanged');
        }
        $this->assertCount(24 + 24, $this->stripe->prices);
        $this->assertSame(1, $this->stripe->created('createProduct'));
    }

    public function test_a_stale_preview_is_refused(): void
    {
        $operation = $this->preview();

        $this->travel(31)->minutes();
        $this->apply($operation)->assertSessionHas('owner_error.code', 'PLAN_EXPIRED');

        $this->assertSame(StripeCatalogueOperation::STALE, $operation->refresh()->status);
        $this->assertSame(0, $this->stripe->created('createPrice'));
    }

    public function test_only_items_of_the_stored_plan_with_their_dependencies_can_be_approved(): void
    {
        $operation = $this->preview();

        $this->apply($operation, ['create_price:evil.key.monthly.hu'])->assertSessionHas('owner_error.code', 'ITEM_NOT_IN_PLAN');
        $this->apply($operation, ['create_price:wordpress.basic.monthly.hu'])->assertSessionHas('owner_error.code', 'DEPENDENCY_NOT_APPROVED');
        $this->post(route('owner.pricing.stripe-catalogue.apply', $operation), ['items' => ['create_product:wordpress'], 'confirm_text' => 'APPLY TEST'])->assertSessionHasErrors('confirm');

        $this->assertSame(0, $this->stripe->created('createProduct'));
        $this->assertSame(StripeCatalogueOperation::PREVIEWED, $operation->refresh()->status);
    }

    public function test_the_mode_must_be_typed_and_a_subset_can_be_approved(): void
    {
        $operation = $this->preview();

        $this->apply($operation, extra: ['confirm_text' => 'yes'])->assertSessionHas('owner_error.code', 'CONFIRM_TEXT');
        $this->assertSame(0, $this->stripe->created('createProduct'));

        $this->apply($operation, ['create_product:wordpress', 'create_price:wordpress.basic.monthly.hu']);
        $this->assertCount(1, $this->stripe->prices);
        $this->assertSame(['prod_wordpress'], array_keys($this->stripe->products));
    }

    public function test_a_stripe_failure_while_reading_is_reported_and_nothing_is_stored(): void
    {
        $this->stripe->failList = 'api_connection';

        $this->post(route('owner.pricing.stripe-catalogue.preview'))->assertSessionHas('owner_error.code', 'API_CONNECTION');

        $this->assertSame(0, StripeCatalogueOperation::count());
        $this->assertSame(1, $this->auditCount('stripe_catalogue.preview_failed'));
    }

    // ---------------------------------------------------------------- isolation

    public function test_changes_are_refused_while_apply_is_switched_off_but_the_dry_run_works(): void
    {
        config(['services.stripe.catalogue.apply_enabled' => false]);
        $operation = $this->preview();

        $this->apply($operation)->assertSessionHas('owner_error.code', 'APPLY_DISABLED');
        $this->post(route('owner.pricing.stripe-catalogue.products.map', 'wordpress'), ['stripe_product_id' => 'prod_xyz123'])->assertSessionHas('owner_error.code', 'APPLY_DISABLED');

        $this->assertSame(0, $this->stripe->created('createProduct') + $this->stripe->created('createPrice'));
        $this->assertSame(StripeCatalogueOperation::PREVIEWED, $operation->refresh()->status);
    }

    public function test_a_live_key_is_refused_on_staging_even_to_read(): void
    {
        app(IntegrationCredentials::class)->store('stripe_secret', 'sk_live_ownermanaged1234567890');
        config(['services.stripe.mode' => 'live', 'services.stripe.allow_live_mode' => true, 'app.deployment_environment' => 'staging_next']);

        $this->post(route('owner.pricing.stripe-catalogue.preview'))->assertSessionHas('owner_error.code', 'LIVE_FORBIDDEN_ON_STAGING');

        $this->assertSame(0, $this->guard->gatewaysBuilt, 'no Stripe client was even built');
        $this->assertSame([], $this->stripe->calls);
        $this->assertSame(0, StripeCatalogueOperation::count());
    }

    public function test_the_staging_release_profile_alone_also_forbids_live(): void
    {
        app(IntegrationCredentials::class)->store('stripe_secret', 'rk_live_restricted1234567890');
        config(['services.stripe.mode' => 'live', 'services.stripe.allow_live_mode' => true, 'marketing_release.staging_active_enabled' => true]);

        $this->post(route('owner.pricing.stripe-catalogue.preview'))->assertSessionHas('owner_error.code', 'LIVE_FORBIDDEN_ON_STAGING');
        $this->assertSame(0, $this->guard->gatewaysBuilt);
    }

    public function test_key_and_configured_mode_must_agree_and_be_recognised(): void
    {
        config(['services.stripe.mode' => 'live']);
        $this->post(route('owner.pricing.stripe-catalogue.preview'))->assertSessionHas('owner_error.code', 'KEY_MODE_MISMATCH');

        config(['services.stripe.mode' => 'test']);
        app(IntegrationCredentials::class)->store('stripe_secret', 'whsec_notasecretkey');
        $this->post(route('owner.pricing.stripe-catalogue.preview'))->assertSessionHas('owner_error.code', 'KEY_MODE_UNKNOWN');

        app(IntegrationCredentials::class)->clear('stripe_secret');
        config(['services.stripe.secret' => null]);
        $this->post(route('owner.pricing.stripe-catalogue.preview'))->assertSessionHas('owner_error.code', 'STRIPE_NOT_CONFIGURED');

        $this->assertSame(0, $this->guard->gatewaysBuilt);
    }

    public function test_live_outside_staging_needs_live_mode_enabled_and_a_separate_approval_to_change(): void
    {
        app(IntegrationCredentials::class)->store('stripe_secret', 'sk_live_ownermanaged1234567890');
        config(['services.stripe.mode' => 'live']);
        $this->post(route('owner.pricing.stripe-catalogue.preview'))->assertSessionHas('owner_error.code', 'LIVE_MODE_NOT_ENABLED');

        config(['services.stripe.allow_live_mode' => true]);
        $operation = $this->preview();
        $this->assertSame('live', $operation->mode);

        $this->apply($operation)->assertSessionHas('owner_error.code', 'LIVE_APPLY_NOT_APPROVED');
        $this->assertSame(0, $this->stripe->created('createProduct'));

        config(['services.stripe.catalogue.live_apply_approved' => true]);
        $this->apply($operation, extra: ['confirm_text' => 'APPLY TEST'])->assertSessionHas('owner_error.code', 'CONFIRM_TEXT');
        $this->apply($operation);
        $this->assertSame(StripeMode::Live, $this->stripe->mode);
        $this->assertCount(48, $this->stripe->prices);
    }

    public function test_a_preview_made_in_one_mode_cannot_be_applied_in_another(): void
    {
        $operation = $this->preview();
        $operation->forceFill(['mode' => 'live'])->save();

        $this->apply($operation)->assertSessionHas('owner_error.code', 'MODE_CHANGED');
        $this->assertSame(0, $this->stripe->created('createProduct'));
    }

    // ---------------------------------------------------------------- access and secrets

    public function test_only_owners_can_use_the_catalogue(): void
    {
        $operation = $this->preview();
        auth('owner')->logout();

        $this->get(route('owner.pricing.stripe-catalogue.index'))->assertRedirect(route('owner.login'));
        $this->post(route('owner.pricing.stripe-catalogue.preview'))->assertRedirect(route('owner.login'));

        foreach ([User::factory()->operator()->create(), User::factory()->create()] as $other) {
            $this->actingAs($other, 'owner');
            $this->assertContains($this->get(route('owner.pricing.stripe-catalogue.index'))->status(), [302, 403, 404]);
            $this->assertContains($this->post(route('owner.pricing.stripe-catalogue.preview'))->status(), [302, 403, 404]);
            $this->assertContains($this->post(route('owner.pricing.stripe-catalogue.apply', $operation), ['items' => ['create_product:wordpress'], 'confirm' => '1', 'confirm_text' => 'APPLY TEST'])->status(), [302, 403, 404]);
            auth('owner')->logout();
        }

        $this->assertSame(1, StripeCatalogueOperation::count(), 'no other login could create a preview');
        $this->assertSame(0, $this->stripe->created('createProduct') + $this->stripe->created('createPrice'));
        $this->assertSame(StripeCatalogueOperation::PREVIEWED, $operation->refresh()->status);
    }

    public function test_the_secret_never_reaches_responses_plans_audit_or_the_environment(): void
    {
        $operation = $this->preview();
        $this->apply($operation);

        $page = $this->get(route('owner.pricing.stripe-catalogue.show', $operation))->assertOk();
        $this->assertStringNotContainsString(self::SECRET, $page->getContent());
        $this->assertStringNotContainsString('sk_test_', $page->getContent());
        $this->assertStringNotContainsString(self::SECRET, json_encode($operation->refresh()->toArray()));
        $this->assertStringNotContainsString('sk_test_', AdminAuditEvent::all()->toJson());
        $this->assertStringNotContainsString(self::SECRET, (string) $this->get(route('owner.pricing.stripe-catalogue.index'))->getContent());
        $this->assertNotSame(self::SECRET, config('services.stripe.secret'), 'the Owner-managed key is not copied into configuration');
        $this->assertFalse(getenv('STRIPE_SECRET') === self::SECRET);
    }
}
