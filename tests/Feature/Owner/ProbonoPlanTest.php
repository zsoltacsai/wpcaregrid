<?php

namespace Tests\Feature\Owner;

use App\AdminAccess\AccessLevel;
use App\AdminAccess\AdminAccessService;
use App\Backups\BackupPolicy;
use App\Backups\BackupReason;
use App\Backups\BackupRefused;
use App\Backups\BackupService;
use App\Billing\InternalPlan;
use App\Billing\InternalPlans;
use App\Billing\SubscriptionStatus;
use App\Connector\ConnectorError;
use App\Connector\JobQueue;
use App\Connector\JobStatus;
use App\Connector\JobType;
use App\Models\AdminAuditEvent;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Operator\Permission;
use App\Owner\BusinessMetrics;
use App\Pricing\PlanTier;
use App\Pricing\ProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsOwner;
use Tests\Concerns\CreatesBackups;
use Tests\Concerns\EnforcesCsrf;
use Tests\TestCase;

/** PROBONO: an internal plan the owner assigns. Never public, never purchasable, never any backup (docs/PROBONO.md). */
class ProbonoPlanTest extends TestCase
{
    use ActsAsOwner, CreatesBackups, EnforcesCsrf, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        // Every backup setting that could allow a backup is on: only the plan can say no.
        $this->setUpBackups(['manual' => true, 'pre_update_backup' => true, 'restore' => true]);
        config(['backup.restore.enabled' => true]);
    }

    protected function tearDown(): void
    {
        $this->tearDownBackups();
        parent::tearDown();
    }

    private function assign(Customer $customer, array $overrides = [])
    {
        return $this->post(route('owner.customers.internal-plans.store', $customer), $overrides + ['product_type' => 'wordpress', 'confirm' => '1']);
    }

    /** A connected site whose plan is PROBONO. */
    private function probonoSite(): array
    {
        $site = $this->backupReadySite();
        $website = $site['website'];
        $previous = $website->subscription_id;
        $subscription = app(InternalPlans::class)->assign($website->customer, InternalPlan::Probono, ProductType::WordPress, null, null, null, $this->owner());
        $website->update(['subscription_id' => $subscription->id]);
        Subscription::whereKey($previous)->delete(); // the customer is on PROBONO only

        return ['website' => $website->refresh(), 'credential' => $site['credential'], 'subscription' => $subscription];
    }

    // --- Assignment --------------------------------------------------------

    public function test_the_owner_assigns_probono_as_a_local_subscription_without_stripe_or_price(): void
    {
        $owner = $this->asOwner();
        $customer = Customer::factory()->withUser()->create();

        $this->assign($customer, ['note' => 'Charity, agreed by phone.'])->assertRedirect(route('owner.customers.show', $customer));

        $subscription = Subscription::sole();
        $this->assertTrue($subscription->isInternal());
        $this->assertSame(InternalPlan::Probono, $subscription->internal_plan);
        $this->assertSame($customer->id, $subscription->customer_id);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertTrue($subscription->grantsAccess());
        $this->assertNull($subscription->plan, 'No public tier.');
        $this->assertNull($subscription->stripe_subscription_id);
        $this->assertNull($subscription->stripe_price_id);
        $this->assertNull($subscription->amount);
        $this->assertNull($subscription->interval);
        $this->assertNull($subscription->nextBillingDate());
        $this->assertSame('PROBONO', $subscription->planName());
        $this->assertSame($owner->id, $subscription->assigned_by_user_id);
        $this->assertSame('Charity, agreed by phone.', $subscription->internal_note);
        $this->assertTrue($customer->refresh()->hasActiveSubscription());

        $event = AdminAuditEvent::where('event', 'probono.assigned')->sole();
        $this->assertSame($owner->id, $event->actor_user_id);
        $this->assertSame($subscription->uuid, $event->subject_key);
        $this->assertSame($customer->uuid, $event->data['customer']);
    }

    public function test_the_plan_is_fixed_on_the_server_and_the_browser_cannot_name_another(): void
    {
        $this->asOwner();
        $customer = Customer::factory()->withUser()->create();

        $this->assign($customer, ['plan' => 'secure', 'internal_plan' => 'secure', 'amount' => 1, 'stripe_price_id' => 'price_x', 'stripe_subscription_id' => 'sub_evil', 'status' => 'active', 'customer_id' => 999])->assertSessionDoesntHaveErrors();

        $subscription = Subscription::sole();
        $this->assertSame(InternalPlan::Probono, $subscription->internal_plan);
        $this->assertNull($subscription->plan);
        $this->assertNull($subscription->amount);
        $this->assertNull($subscription->stripe_subscription_id);
        $this->assertSame($customer->id, $subscription->customer_id);
    }

    public function test_assignment_needs_confirmation_a_valid_product_and_sensible_dates(): void
    {
        $this->asOwner();
        $customer = Customer::factory()->withUser()->create();

        $this->post(route('owner.customers.internal-plans.store', $customer), ['product_type' => 'wordpress'])->assertSessionHasErrors('confirm');
        $this->assign($customer, ['product_type' => 'joomla'])->assertSessionHasErrors('product_type');
        $this->assign($customer, ['ends_at' => now()->subDay()->format('Y-m-d\TH:i')])->assertSessionHasErrors('ends_at');
        $this->assign($customer, ['starts_at' => now()->addDays(10)->format('Y-m-d\TH:i'), 'ends_at' => now()->addDays(5)->format('Y-m-d\TH:i')])->assertSessionHasErrors('ends_at');
        $this->assertDatabaseCount('subscriptions', 0);

        Customer::whereKey($customer->id)->update(['active' => false]);
        $this->assign($customer)->assertSessionHasErrors('customer');
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_a_second_spare_plan_and_a_double_submit_are_refused_until_the_first_has_a_website(): void
    {
        $this->asOwner();
        $customer = Customer::factory()->withUser()->create();

        $this->assign($customer)->assertSessionDoesntHaveErrors();
        $this->assign($customer)->assertSessionHasErrors('customer');
        $this->assertSame(1, Subscription::count());

        Website::factory()->for($customer)->create(['subscription_id' => Subscription::sole()->id]);
        $this->assign($customer)->assertSessionDoesntHaveErrors();
        $this->assertSame(2, Subscription::count());
    }

    public function test_the_owner_changes_dates_and_note_and_ends_the_plan_and_both_are_audited(): void
    {
        $owner = $this->asOwner();
        $customer = Customer::factory()->withUser()->create();
        $this->assign($customer);
        $subscription = Subscription::sole();

        $end = now()->addMonths(3)->startOfMinute();
        $this->put(route('owner.customers.internal-plans.update', [$customer, $subscription]), ['ends_at' => $end->format('Y-m-d\TH:i'), 'note' => 'Review in March.'])->assertSessionHas('status', 'PROBONO changes saved.');
        $subscription->refresh();
        $this->assertTrue($end->equalTo($subscription->internal_ends_at));
        $this->assertEqualsCanonicalizing(['internal_ends_at', 'internal_note'], AdminAuditEvent::where('event', 'probono.changed')->sole()->data['fields']);

        $this->post(route('owner.customers.internal-plans.end', [$customer, $subscription]))->assertSessionHas('status', 'PROBONO ended.');
        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->status);
        $this->assertFalse($subscription->grantsAccess());
        $this->assertFalse($customer->refresh()->hasActiveSubscription());
        $this->assertSame($owner->id, AdminAuditEvent::where('event', 'probono.ended')->sole()->actor_user_id);

        // An ended plan cannot be changed or ended again.
        $this->put(route('owner.customers.internal-plans.update', [$customer, $subscription]), ['note' => 'x'])->assertSessionHasErrors('subscription');
        $this->post(route('owner.customers.internal-plans.end', [$customer, $subscription]))->assertSessionHasErrors('subscription');
    }

    public function test_a_plan_is_only_reachable_through_its_own_customer(): void
    {
        $this->asOwner();
        $alice = Customer::factory()->withUser()->create();
        $bob = Customer::factory()->withUser()->create();
        $this->assign($alice);
        $alices = Subscription::sole();

        // Alice's plan through Bob's page: no such plan for Bob.
        $this->post(route('owner.customers.internal-plans.end', [$bob, $alices]))->assertNotFound();
        $this->put(route('owner.customers.internal-plans.update', [$bob, $alices]), ['note' => 'x'])->assertNotFound();
        $this->assertSame(SubscriptionStatus::Active, $alices->refresh()->status);
    }

    public function test_a_stripe_subscription_cannot_be_turned_into_or_ended_as_an_internal_plan(): void
    {
        $this->asOwner();
        $data = $this->customerWithWebsite();

        $this->post(route('owner.customers.internal-plans.end', [$data['customer'], $data['subscription']]))->assertSessionHasErrors('subscription');
        $this->put(route('owner.customers.internal-plans.update', [$data['customer'], $data['subscription']]), ['note' => 'x'])->assertSessionHasErrors('subscription');
        $this->assertSame(SubscriptionStatus::Active, $data['subscription']->refresh()->status);
        $this->assertNull($data['subscription']->internal_plan);
    }

    public function test_dates_start_and_end_the_plan_even_between_scheduled_runs(): void
    {
        $owner = $this->owner();
        $customer = Customer::factory()->withUser()->create();
        $plans = app(InternalPlans::class);

        $future = $plans->assign($customer, InternalPlan::Probono, ProductType::WordPress, now()->addDays(2), null, null, $owner);
        $this->assertSame(SubscriptionStatus::Pending, $future->status);
        $this->assertFalse($future->grantsAccess());

        $this->travel(3)->days();
        $this->assertSame(['started' => 1, 'ended' => 0], $plans->sync());
        $this->assertSame(SubscriptionStatus::Active, $future->refresh()->status);
        $this->assertTrue($future->grantsAccess());
        $this->assertSame(['started' => 0, 'ended' => 0], $plans->sync(), 'Repeating changes nothing.');

        $future->forceFill(['internal_ends_at' => now()->addDay()])->save();
        $this->travel(2)->days();
        $this->assertFalse($future->refresh()->grantsAccess(), 'Ended by its date before the job runs.');
        $this->assertFalse($customer->refresh()->hasActiveSubscription());

        $this->artisan('internal-plans:sync')->assertSuccessful();
        $this->assertSame(SubscriptionStatus::Canceled, $future->refresh()->status);
        $this->assertSame(1, AdminAuditEvent::where('event', 'probono.expired')->count());
    }

    // --- Not public --------------------------------------------------------

    public function test_probono_is_not_a_public_plan_anywhere(): void
    {
        $this->assertNull(PlanTier::tryFrom('probono'));
        $this->assertNotContains('probono', array_map(fn ($tier) => $tier->value, PlanTier::cases()));
        $this->assertStringNotContainsStringIgnoringCase('probono', json_encode(config('pricing')));

        foreach (['en', 'hu'] as $locale) {
            $this->withCookie('caregrid_locale', $locale);
            foreach ([route('home'), route('pricing'), route('pricing', ['plan' => 'probono', 'product' => 'probono', 'currency' => 'PROBONO']), route('checkout.show', ['product' => 'wordpress', 'plan' => 'basic', 'interval' => 'monthly'])] as $url) {
                $this->get($url)->assertSuccessful()->assertDontSee('probono', false)->assertDontSee('PROBONO', false);
            }
        }

        // Search engines: the public sitemap-like surfaces do not exist for it, and the robots rules do not list it.
        $this->get('/probono')->assertNotFound();
        $this->get('/pricing/probono')->assertNotFound();
    }

    public function test_probono_cannot_be_chosen_in_the_public_checkout(): void
    {
        $this->get(route('checkout.show', ['product' => 'wordpress', 'plan' => 'probono', 'interval' => 'monthly']))->assertRedirect(route('pricing'));

        foreach (['probono', 'PROBONO', 'internal', ''] as $plan) {
            $this->post(route('checkout.store'), ['product' => 'wordpress', 'plan' => $plan, 'interval' => 'monthly', 'email' => 'a@b.example', 'first_name' => 'A', 'last_name' => 'B', 'country' => 'HU'])
                ->assertSessionHasErrors('plan');
        }
        $this->post(route('checkout.store'), ['product' => 'wordpress', 'plan' => 'basic', 'internal_plan' => 'probono', 'interval' => 'monthly', 'email' => 'a@b.example', 'first_name' => 'A', 'last_name' => 'B', 'country' => 'HU']);

        $this->assertDatabaseCount('checkout_intents', 0); // Nothing was recorded for a manipulated plan.
        $this->assertSame(0, Subscription::whereNotNull('internal_plan')->count());
    }

    // --- Authorization -----------------------------------------------------

    public function test_only_owners_can_assign_change_or_end_probono(): void
    {
        $customer = Customer::factory()->withUser()->create();
        $other = Customer::factory()->withUser()->create();

        $this->assign($customer)->assertRedirect(route('owner.login'));

        $operator = User::factory()->operator(Permission::cases())->create();
        foreach ([['operator', $operator], ['web', $other->user]] as [$guard, $user]) {
            $this->actingAs($user, $guard);
            $this->assign($customer)->assertRedirect(route('owner.login'));
        }
        $this->assertDatabaseCount('subscriptions', 0);

        // A customer cannot give themselves PROBONO from their own area either.
        $this->actingAs($customer->user, 'web');
        $this->post('/account/subscription', ['internal_plan' => 'probono'])->assertStatus(405);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_the_assignment_forms_are_csrf_protected_and_rate_limited_and_name_the_assign_ability(): void
    {
        $this->asOwner();
        $customer = Customer::factory()->withUser()->create();
        $this->enforceCsrf();
        $this->assign($customer)->assertStatus(419);
        $this->assertDatabaseCount('subscriptions', 0);

        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'owner.customers.internal-plans.'));
        $this->assertCount(3, $routes);
        foreach ($routes as $route) {
            $this->assertContains('can:owner.plans.assign', $route->gatherMiddleware(), $route->getName());
            $this->assertContains('throttle:owner-sensitive', $route->gatherMiddleware(), $route->getName());
        }
    }

    // --- No backups --------------------------------------------------------

    public function test_probono_has_the_backup_capability_explicitly_off(): void
    {
        $this->assertFalse(InternalPlan::Probono->includesBackups());
        $this->assertTrue(InternalPlan::Probono->includesUpdates());
        $this->assertTrue(InternalPlan::Probono->includesHealthChecks());
        $this->assertFalse(InternalPlan::Probono->includesScheduledScans());
        $this->assertFalse(InternalPlan::Probono->includesAdminAccess());
    }

    public function test_the_backup_policy_refuses_everything_for_a_probono_site_whatever_the_settings_say(): void
    {
        $site = $this->probonoSite();

        $decision = app(BackupPolicy::class)->for($site['website']);
        $this->assertFalse($decision->eligible);
        $this->assertSame('BACKUPS_NOT_INCLUDED', $decision->refusal);
        $this->assertNull($decision->frequency);
        $this->assertFalse($decision->preUpdateRequired);
        $this->assertFalse($decision->restoreAllowed);
        $this->assertFalse($decision->manualAllowed);
        $this->assertFalse($decision->hasRetention());
    }

    public function test_no_backup_can_be_requested_manually_scheduled_or_before_an_update(): void
    {
        $site = $this->probonoSite();
        $website = $site['website'];

        foreach ([BackupReason::Manual, BackupReason::Scheduled, BackupReason::PreUpdate] as $reason) {
            try {
                app(BackupService::class)->request($website, $reason, 'test');
                $this->fail("A {$reason->value} backup was accepted for PROBONO.");
            } catch (BackupRefused $e) {
                $this->assertSame('BACKUPS_NOT_INCLUDED', $e->errorCode);
            }
        }

        $this->artisan('backup:schedule')->assertSuccessful();
        $this->assertSame(0, $website->backups()->count());
        $this->assertSame(0, $website->jobs()->whereIn('type', [JobType::BackupFiles, JobType::BackupDatabase, JobType::BackupFull])->count());
    }

    public function test_an_update_runs_without_a_pre_update_backup_and_no_backup_job_is_created(): void
    {
        $site = $this->probonoSite();
        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.inventory'), $this->inventoryPayload())->assertStatus(202);

        $update = app(JobQueue::class)->queue($site['website']->refresh(), JobType::UpdatePlugin, ['plugin' => 'akismet/akismet.php']);

        $this->assertSame(JobStatus::Queued, $update->status, 'Not waiting for a backup.');
        $this->assertNull($update->backup_id);
        $this->assertSame(0, $site['website']->backups()->count());
        $this->assertSame(['UPDATE_PLUGIN'], array_column($this->pollJobs($site), 'type'));
    }

    public function test_a_backup_or_restore_job_cannot_be_queued_directly_and_a_queued_one_is_not_released(): void
    {
        $site = $this->probonoSite();
        $website = $site['website'];

        foreach ([JobType::BackupFull, JobType::BackupFiles, JobType::BackupDatabase] as $type) {
            try {
                app(JobQueue::class)->queue($website, $type, ['backup_uuid' => (string) Str::uuid(), 'reason' => 'manual', 'chunk_size' => 4194304]);
                $this->fail("{$type->value} was queued for PROBONO.");
            } catch (ConnectorError) {
                $this->addToAssertionCount(1);
            }
        }

        // A job queued before the plan was assigned (the website was on a public plan) is cancelled at delivery.
        $public = $this->backupReadySite('https://other.example');
        $job = app(BackupService::class)->request($public['website'], BackupReason::Manual, 'test');
        $this->assertSame(1, $public['website']->jobs()->where('status', JobStatus::Queued)->count());
        $plan = app(InternalPlans::class)->assign($public['website']->customer, InternalPlan::Probono, ProductType::WordPress, null, null, null, $this->owner());
        $public['website']->update(['subscription_id' => $plan->id]);

        $this->assertSame([], $this->pollJobs($public), 'Nothing is handed to the connector.');
        $this->assertSame(JobStatus::Cancelled, $public['website']->jobs()->sole()->status);
        $this->assertNotNull($job);
    }

    public function test_a_customer_and_the_owner_cannot_start_a_backup_or_restore_for_a_probono_site(): void
    {
        $site = $this->probonoSite();
        $website = $site['website'];

        $this->actingAs($website->customer->user)->post(route('account.websites.backups.store', $website))->assertRedirect();
        $this->assertSame(0, $website->backups()->count());
        $this->get(route('account.websites.backups.index', $website))->assertOk()->assertSee('Backups are not part of your plan.');

        $this->asOwner();
        $this->post(route('owner.websites.backups.store', $website))->assertRedirect()->assertSessionHas('owner_error');
        $this->assertSame(0, $website->backups()->count());
    }

    public function test_probono_does_not_receive_scheduled_scans_or_wordpress_admin_access(): void
    {
        $site = $this->probonoSite();

        $this->assertFalse(app(AdminAccessService::class)->levelAllowed($site['website'], AccessLevel::Maintenance));
        $this->assertFalse(app(AdminAccessService::class)->levelAllowed($site['website'], AccessLevel::FullAdmin));

        config(['security.schedule.enabled' => true, 'security.schedule.scans' => ['integrity' => 'daily']]);
        $this->artisan('security:schedule')->assertSuccessful();
        $this->assertSame(0, $site['website']->securityScans()->count());
    }

    public function test_public_plans_are_unaffected(): void
    {
        $site = $this->backupReadySite();

        $decision = app(BackupPolicy::class)->for($site['website']);
        $this->assertTrue($decision->eligible);
        $this->assertNull($decision->refusal);
        $this->assertTrue($decision->preUpdateRequired);
        $this->assertTrue($decision->manualAllowed);

        $backup = app(BackupService::class)->request($site['website'], BackupReason::Manual, 'test');
        $this->assertNotNull($backup->id);
        $this->assertTrue(app(AdminAccessService::class)->levelAllowed($site['website'], AccessLevel::Maintenance));
    }

    // --- Customer portal and tenant isolation ------------------------------

    public function test_the_customer_sees_probono_clearly_in_english_and_hungarian_and_nobody_else_does(): void
    {
        $site = $this->probonoSite();
        $customer = $site['website']->customer;
        $stranger = Customer::factory()->withUser()->create();

        $this->actingAs($customer->user);
        $page = $this->get(route('account.subscription'))->assertOk();
        $page->assertSee('PROBONO');
        $page->assertSee('Included in this plan');
        $page->assertSee('Not included in this plan');
        $page->assertSee('Backups: none are made or restored');
        $page->assertSee('No end date');
        $page->assertDontSee('Next billing date');
        $page->assertDontSee('Billing period');
        $page->assertDontSee('Renews automatically');
        $this->get(route('account.dashboard'))->assertOk()->assertSee('PROBONO');

        $this->withCookie('caregrid_locale', 'hu');
        $this->get(route('account.subscription'))->assertOk()
            ->assertSee('PROBONO')->assertSee('A csomag tartalmazza')->assertSee('A csomag nem tartalmazza')
            ->assertSee('Mentés: nem készül')->assertSee('Nincs lejárati dátum');

        $this->actingAs($stranger->user);
        $this->get(route('account.subscriptions.show', $site['subscription']))->assertForbidden();
        $this->get(route('account.subscription'))->assertOk()->assertDontSee('PROBONO');
    }

    public function test_every_page_that_shows_a_subscription_renders_for_a_probono_site(): void
    {
        // PROBONO has no price, interval, market or tier: nothing may assume they exist.
        $site = $this->probonoSite();
        $website = $site['website'];
        $customer = $website->customer;

        $this->actingAs($customer->user, 'web');
        foreach ([route('account.dashboard'), route('account.websites.index'), route('account.websites.show', $website), route('account.subscription'), route('account.subscriptions.show', $site['subscription']), route('account.billing'), route('account.profile'), route('account.websites.backups.index', $website)] as $url) {
            $this->get($url)->assertOk();
        }

        $this->actingAs(User::factory()->operator(Permission::cases())->create(), 'operator');
        foreach ([route('operator.dashboard'), route('operator.websites.index'), route('operator.websites.show', $website)] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(route('operator.websites.show', $website))->assertSee('PROBONO');

        $this->asOwner();
        foreach ([route('owner.dashboard'), route('owner.system'), route('owner.customers.index'), route('owner.customers.show', $customer), route('owner.websites.index'), route('owner.websites.show', $website), route('owner.customers.index', ['plan' => 'basic'])] as $url) {
            $this->get($url)->assertOk();
        }

        // The owner's revenue figure counts only what Stripe bills.
        $this->get(route('owner.dashboard'))->assertOk();
        $this->assertSame(0, app(BusinessMetrics::class)->mrr()[0]->amount);
    }

    public function test_the_owner_sees_probono_on_the_customer_page_and_in_the_lists(): void
    {
        $site = $this->probonoSite();
        $customer = $site['website']->customer;
        $this->asOwner();

        $this->get(route('owner.customers.show', $customer))->assertOk()->assertSee('Internal plan')->assertSee('PROBONO')->assertSee('End PROBONO now')->assertSee('does not include backups');
        $this->get(route('owner.customers.index'))->assertOk()->assertSee('PROBONO');
        $this->get(route('owner.websites.index'))->assertOk()->assertSee('PROBONO');
        $this->get(route('owner.websites.show', $site['website']))->assertOk()->assertSee('PROBONO');
    }
}
