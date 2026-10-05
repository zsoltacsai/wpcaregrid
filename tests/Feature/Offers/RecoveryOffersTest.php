<?php

namespace Tests\Feature\Offers;

use App\Checkout\CheckoutUnavailable;
use App\Mail\RecoveryOfferPaid;
use App\Mail\RecoveryOfferSent;
use App\Models\AdminAuditEvent;
use App\Models\Customer;
use App\Models\RecoveryOffer;
use App\Models\StripeEvent;
use App\Models\Subscription;
use App\Models\User;
use App\Offers\OfferPaymentGateway;
use App\Offers\OfferStatus;
use App\Offers\RecoveryOffers;
use App\Offers\StripeOfferPaymentGateway;
use App\Operator\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ActsAsOwner;
use Tests\Concerns\EnforcesCsrf;
use Tests\Concerns\SendsStripeWebhooks;
use Tests\Fakes\FakeOfferPaymentGateway;
use Tests\TestCase;

/** Custom security recovery offers: owner, customer and the webhook (docs/RECOVERY_OFFERS.md). */
class RecoveryOffersTest extends TestCase
{
    use ActsAsOwner, EnforcesCsrf, RefreshDatabase, SendsStripeWebhooks;

    private FakeOfferPaymentGateway $stripe;

    private array $alice;

    private array $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->stripe = new FakeOfferPaymentGateway;
        $this->app->instance(OfferPaymentGateway::class, $this->stripe);
        $this->useWebhookSecret();
        Mail::fake();

        $this->alice = $this->customerWithWebsite('https://alice.example', ['email' => 'alice@company.example', 'first_name' => 'Alice']);
        $this->bob = $this->customerWithWebsite('https://bob.example', ['email' => 'bob@company.example', 'first_name' => 'Bob']);
    }

    /** @param  array<string, mixed>  $overrides */
    private function newOffer(array $overrides = [], ?array $for = null)
    {
        $for ??= $this->alice;

        return $this->post(route('owner.offers.store'), $overrides + [
            'customer' => $for['customer']->uuid,
            'website' => $for['website']->uuid,
            'title' => 'Malware cleanup and verification',
            'scope' => "Investigation of suspicious files\nCleanup of confirmed files\nA scan afterwards\nA short report",
            'internal_notes' => 'Secret margin note.',
            'amount' => '45000',
            'currency' => 'HUF',
            'valid_until' => now()->addDays(14)->format('Y-m-d\TH:i'),
        ]);
    }

    private function draft(array $overrides = [], ?array $for = null): RecoveryOffer
    {
        $this->asOwner();
        $this->newOffer($overrides, $for)->assertSessionDoesntHaveErrors();

        return RecoveryOffer::latest('id')->firstOrFail();
    }

    private function offered(array $overrides = [], ?array $for = null): RecoveryOffer
    {
        $offer = $this->draft($overrides, $for);
        $this->post(route('owner.offers.send', $offer), ['confirm' => '1'])->assertSessionDoesntHaveErrors();
        auth('owner')->logout();

        return $offer->refresh();
    }

    private function asCustomer(?array $who = null)
    {
        auth('owner')->logout();

        return $this->actingAs(($who ?? $this->alice)['customer']->user, 'web');
    }

    /** An accepted offer with an open Checkout Session. */
    private function accepted(array $overrides = [], ?array $for = null): RecoveryOffer
    {
        $offer = $this->offered($overrides, $for);
        $this->asCustomer($for)->post(route('account.offers.accept', $offer))->assertRedirect();

        return $offer->refresh();
    }

    /** @param  array<string, mixed>  $overrides */
    private function paymentEvent(RecoveryOffer $offer, array $overrides = [], ?string $eventId = null, string $type = 'checkout.session.completed')
    {
        return $this->stripeEvent($type, $overrides + [
            'id' => $offer->stripe_checkout_session_id,
            'object' => 'checkout.session',
            'client_reference_id' => $offer->uuid,
            'mode' => 'payment',
            'payment_status' => 'paid',
            'amount_total' => $offer->amount,
            'currency' => strtolower($offer->currency),
            'payment_intent' => 'pi_test_offer_1',
            'metadata' => ['recovery_offer' => $offer->uuid, 'kind' => 'recovery_offer'],
        ], $eventId);
    }

    // --- Owner: creating ----------------------------------------------------

    public function test_the_owner_creates_a_draft_bound_to_one_customer_and_one_of_their_websites(): void
    {
        $owner = $this->asOwner();

        $this->newOffer()->assertRedirect();

        $offer = RecoveryOffer::sole();
        $this->assertSame(OfferStatus::Draft, $offer->status);
        $this->assertSame($this->alice['customer']->id, $offer->customer_id);
        $this->assertSame($this->alice['website']->id, $offer->website_id);
        $this->assertSame(4500000, $offer->amount, 'Entered in forint, stored in the smallest unit like every other price.');
        $this->assertSame('HUF', $offer->currency);
        $this->assertSame($owner->id, $offer->created_by_user_id);
        $this->assertNull($offer->offered_at);
        $this->assertNull($offer->offer_notified_at);
        Mail::assertNothingQueued();

        $event = AdminAuditEvent::where('event', 'offer.created')->sole();
        $this->assertSame($owner->id, $event->actor_user_id);
        $this->assertSame($offer->uuid, $event->subject_key);
        $this->assertSame(4500000, $event->data['amount']);
        $this->assertStringNotContainsString('Secret margin note', json_encode($event->data));
    }

    public function test_an_offer_can_never_be_attached_to_another_customers_website(): void
    {
        $this->asOwner();

        // Alice as customer, Bob's website.
        $this->newOffer(['website' => $this->bob['website']->uuid])->assertSessionHasErrors('website');
        // Unknown and malformed websites.
        $this->newOffer(['website' => (string) Str::uuid()])->assertSessionHasErrors('website');
        $this->newOffer(['website' => (string) $this->bob['website']->id])->assertSessionHasErrors('website');
        // An unknown customer.
        $this->newOffer(['customer' => (string) Str::uuid()])->assertNotFound();
        $this->assertDatabaseCount('recovery_offers', 0);

        // Editing cannot move a draft to Bob's website either.
        $offer = $this->draft();
        $this->put(route('owner.offers.update', $offer), $this->fields(['website' => $this->bob['website']->uuid]))->assertSessionHasErrors('website');
        $this->assertSame($this->alice['website']->id, $offer->refresh()->website_id);
    }

    /** @param  array<string, mixed>  $overrides */
    private function fields(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Malware cleanup and verification', 'scope' => 'Scope text', 'amount' => '45000', 'currency' => 'HUF',
            'valid_until' => now()->addDays(14)->format('Y-m-d\TH:i'),
        ];
    }

    public function test_price_currency_and_validity_are_validated_on_the_server(): void
    {
        $this->asOwner();

        foreach (['0', '-100', 'abc', '', '1.5', '10 000', '1e3', '9999999999'] as $amount) {
            $this->newOffer(['amount' => $amount])->assertSessionHasErrors('amount');
        }
        // Only currencies with real prices: today forint only. Nothing is converted or invented.
        foreach (['EUR', 'USD', 'GBP', 'XXX', 'huf '] as $currency) {
            $this->newOffer(['currency' => $currency])->assertSessionHasErrors('currency');
        }
        $this->newOffer(['valid_until' => now()->subDay()->format('Y-m-d\TH:i')])->assertSessionHasErrors('valid_until');
        $this->newOffer(['valid_until' => now()->addDays(400)->format('Y-m-d\TH:i')])->assertSessionHasErrors('valid_until');
        $this->newOffer(['title' => ''])->assertSessionHasErrors('title');
        $this->newOffer(['scope' => ''])->assertSessionHasErrors('scope');
        $this->assertDatabaseCount('recovery_offers', 0);

        $this->newOffer(['amount' => '45 000'])->assertSessionHasErrors('amount');
        $this->newOffer(['amount' => '1'])->assertSessionDoesntHaveErrors();
        $this->assertSame(100, RecoveryOffer::sole()->amount);
    }

    public function test_the_offer_form_lists_only_sellable_currencies_and_only_the_customers_websites(): void
    {
        $this->asOwner();

        $this->get(route('owner.offers.create'))->assertOk()->assertSee('alice@company.example');
        $this->get(route('owner.offers.create', ['customer' => $this->alice['customer']->uuid]))->assertOk()
            ->assertSee('alice.example')->assertDontSee('bob.example')
            ->assertSee('<option value="HUF"', false)->assertDontSee('<option value="EUR"', false)->assertDontSee('<option value="USD"', false);
    }

    // --- Owner: sending, editing, cancelling --------------------------------

    public function test_sending_makes_the_offer_visible_and_emails_the_customer_once_in_their_language(): void
    {
        $this->alice['customer']->user->forceFill(['locale' => 'hu'])->save();
        $offer = $this->draft();

        $this->post(route('owner.offers.send', $offer), [])->assertSessionHasErrors('confirm');
        $this->assertSame(OfferStatus::Draft, $offer->refresh()->status);

        $this->post(route('owner.offers.send', $offer), ['confirm' => '1'])->assertSessionHas('status');
        $offer->refresh();
        $this->assertSame(OfferStatus::Offered, $offer->status);
        $this->assertNotNull($offer->offered_at);
        $this->assertNotNull($offer->offer_notified_at);
        $this->assertSame(1, AdminAuditEvent::where('event', 'offer.sent')->count());

        Mail::assertQueued(RecoveryOfferSent::class, 1);
        Mail::assertQueued(RecoveryOfferSent::class, fn (RecoveryOfferSent $mail) => $mail->hasTo('alice@company.example') && $mail->locale === 'hu');
        $rendered = (new RecoveryOfferSent($offer))->render();
        $this->assertStringContainsString('Ajánlatot készítettünk', $rendered);
        $this->assertStringContainsString('45', $rendered);
        $this->assertStringNotContainsString('Secret margin note', $rendered);
        $this->assertStringNotContainsString('Investigation of suspicious files', $rendered, 'The scope is read in the portal, not mailed.');
        $this->assertStringNotContainsString('checkout.stripe.com', $rendered);

        // Sending again does nothing (a double click or a replay).
        $this->post(route('owner.offers.send', $offer), ['confirm' => '1'])->assertSessionHasErrors('offer');
        Mail::assertQueued(RecoveryOfferSent::class, 1);
    }

    public function test_the_email_follows_the_customers_saved_language_and_defaults_to_english(): void
    {
        $offer = $this->offered();
        $this->assertStringContainsString('An offer for your website', (new RecoveryOfferSent($offer))->envelope()->subject);
        $this->assertStringContainsString('Hello Alice', (new RecoveryOfferSent($offer))->render());

        $this->alice['customer']->user->forceFill(['locale' => 'hu'])->save();
        $this->assertSame('hu', $offer->refresh()->customer->mailLocale());
        $this->assertStringContainsString('Kedves Alice', (new RecoveryOfferSent($offer))->render());
    }

    public function test_an_unsent_or_offered_offer_can_be_edited_but_an_accepted_one_is_frozen(): void
    {
        $offer = $this->offered();
        $this->asOwner();

        $this->put(route('owner.offers.update', $offer), $this->fields(['amount' => '50000', 'title' => 'New title']))->assertSessionHas('status', 'Offer saved.');
        $offer->refresh();
        $this->assertSame(5000000, $offer->amount);
        $this->assertSame('New title', $offer->title);
        $audit = AdminAuditEvent::where('event', 'offer.updated')->sole();
        $this->assertContains('amount', $audit->data['fields']);
        $this->assertContains('title', $audit->data['fields']);
        $this->assertStringNotContainsString('New title', json_encode($audit->data), 'Field names only, not values.');

        $this->asCustomer()->post(route('account.offers.accept', $offer))->assertRedirect();
        $this->asOwner();
        $this->put(route('owner.offers.update', $offer), $this->fields(['amount' => '1']))->assertSessionHasErrors('offer');
        $this->assertSame(5000000, $offer->refresh()->amount, 'Silently changing an accepted offer is impossible.');
        $this->get(route('owner.offers.show', $offer))->assertOk()->assertSee('can no longer be changed');
    }

    public function test_cancelling_stops_the_payment_and_hides_nothing_the_customer_already_saw(): void
    {
        $offer = $this->accepted();
        $session = $offer->stripe_checkout_session_id;
        $this->asOwner();

        $this->post(route('owner.offers.cancel', $offer))->assertSessionHas('status', 'Offer cancelled.');

        $offer->refresh();
        $this->assertSame(OfferStatus::Cancelled, $offer->status);
        $this->assertSame([$session], $this->stripe->expired);
        $this->assertNull($offer->stripe_checkout_url);
        $this->assertSame(1, AdminAuditEvent::where('event', 'offer.cancelled')->count());

        $this->asCustomer()->post(route('account.offers.pay', $offer))->assertSessionHasErrors('offer');
        $this->get(route('account.offers.show', $offer))->assertOk()->assertSee('This offer was withdrawn.');

        // A draft that was never sent stays invisible even when cancelled.
        $draft = $this->draft();
        $this->post(route('owner.offers.cancel', $draft));
        $this->asCustomer()->get(route('account.offers.show', $draft))->assertForbidden();
    }

    public function test_the_owner_marks_a_paid_offer_completed_and_only_a_paid_one(): void
    {
        $offer = $this->accepted();
        $this->asOwner();
        $this->post(route('owner.offers.complete', $offer))->assertSessionHasErrors('offer');

        $this->paymentEvent($offer)->assertStatus(204);
        $this->asOwner();
        $this->post(route('owner.offers.complete', $offer))->assertSessionHas('status', 'Marked as completed.');
        $this->assertSame(OfferStatus::Completed, $offer->refresh()->status);
        $this->assertSame(1, AdminAuditEvent::where('event', 'offer.completed')->count());

        $this->post(route('owner.offers.cancel', $offer))->assertSessionHasErrors('offer');
    }

    // --- Customer: viewing, accepting, declining ----------------------------

    public function test_the_customer_sees_the_offer_in_english_and_hungarian_without_owner_notes(): void
    {
        $offer = $this->offered();

        $this->asCustomer();
        $this->get(route('account.dashboard'))->assertOk()->assertSee('You have 1 offer waiting for your answer.');
        $this->get(route('account.offers.index'))->assertOk()->assertSee('Malware cleanup and verification')->assertSee('alice.example')->assertSee('Waiting for your answer')->assertSee('45,000')->assertSee('Offers');
        $this->get(route('account.offers.show', $offer))->assertOk()
            ->assertSee('Investigation of suspicious files')->assertSee('45,000')->assertSee('one-time payment')->assertSee('Valid until')
            ->assertSee('Accept and pay')->assertSee('Decline')
            ->assertDontSee('Secret margin note')->assertDontSee('internal_notes');

        $this->withCookie('caregrid_locale', 'hu');
        $this->get(route('account.dashboard'))->assertOk()->assertSee('1 ajánlat vár az Ön válaszára.');
        $this->get(route('account.offers.index'))->assertOk()->assertSee('Válaszra vár')->assertSee('45')->assertSee('Ajánlatok');
        $this->get(route('account.offers.show', $offer))->assertOk()
            ->assertSee('Mit tartalmaz az ajánlat')->assertSee('egyszeri fizetés')->assertSee('Érvényes eddig')->assertSee('Elfogadás és fizetés')->assertSee('Elutasítás')
            ->assertDontSee('Secret margin note')->assertDontSee('Accept and pay');
    }

    public function test_the_menu_shows_offers_only_for_a_customer_who_has_been_sent_one(): void
    {
        $this->offered();

        $this->asCustomer($this->alice)->get(route('account.dashboard'))->assertSee(route('account.offers.index'), false);
        $this->asCustomer($this->bob)->get(route('account.dashboard'))->assertDontSee(route('account.offers.index'), false);
        $this->get(route('account.offers.index'))->assertOk()->assertSee('You have no offers.');
    }

    public function test_drafts_are_invisible_to_the_customer(): void
    {
        $draft = $this->draft();

        $this->asCustomer()->get(route('account.offers.index'))->assertOk()->assertDontSee('Malware cleanup');
        $this->get(route('account.offers.show', $draft))->assertForbidden();
        $this->post(route('account.offers.accept', $draft))->assertForbidden();
        $this->assertSame(OfferStatus::Draft, $draft->refresh()->status);
        $this->assertSame([], $this->stripe->created);
    }

    public function test_another_customer_cannot_see_accept_pay_or_decline_an_offer(): void
    {
        $offer = $this->offered();

        $this->asCustomer($this->bob);
        $this->get(route('account.offers.show', $offer))->assertForbidden();
        $this->post(route('account.offers.accept', $offer))->assertForbidden();
        $this->post(route('account.offers.pay', $offer))->assertForbidden();
        $this->post(route('account.offers.decline', $offer))->assertForbidden();
        $this->get(route('account.offers.index'))->assertOk()->assertDontSee('Malware cleanup');
        $this->get('/account/offers/'.$offer->id)->assertNotFound();

        $this->assertSame(OfferStatus::Offered, $offer->refresh()->status);
        $this->assertSame([], $this->stripe->created);

        // Not signed in, and signed in as an operator or an owner: no access either.
        auth('web')->logout();
        $this->get(route('account.offers.show', $offer))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->operator(Permission::cases())->create(), 'operator')->get(route('account.offers.show', $offer))->assertRedirect(route('login'));
        $this->actingAs($this->owner(), 'owner')->get(route('account.offers.show', $offer))->assertRedirect(route('login'));
    }

    public function test_accepting_charges_exactly_the_stored_price_whatever_the_browser_sends(): void
    {
        $offer = $this->offered();

        $response = $this->asCustomer()->post(route('account.offers.accept', $offer), [
            'amount' => 1, 'price' => 1, 'currency' => 'EUR', 'scope' => 'Nothing', 'title' => 'Free', 'website' => $this->bob['website']->uuid,
            'stripe_price_id' => 'price_evil', 'customer_id' => $this->bob['customer']->id, 'status' => 'paid',
        ]);

        $offer->refresh();
        $this->assertSame(OfferStatus::Accepted, $offer->status, 'Accepted, not paid: only the webhook pays.');
        $this->assertNull($offer->paid_at);
        $this->assertCount(1, $this->stripe->created);
        $this->assertSame(['offer' => $offer->uuid, 'amount' => 4500000, 'currency' => 'HUF', 'previous' => ''], $this->stripe->created[0]);
        $response->assertRedirect($offer->stripe_checkout_url);
        $this->assertSame('Malware cleanup and verification', $offer->title);
        $this->assertSame($this->alice['website']->id, $offer->website_id);
        $this->assertSame($this->alice['customer']->id, $offer->customer_id);
        $this->assertSame(1, AdminAuditEvent::where('event', 'offer.accepted')->count());
    }

    public function test_accepting_twice_or_resuming_reuses_the_open_session(): void
    {
        $offer = $this->offered();
        $this->asCustomer();

        $this->post(route('account.offers.accept', $offer))->assertRedirect();
        $first = $offer->refresh()->stripe_checkout_session_id;
        $this->post(route('account.offers.accept', $offer))->assertRedirect($offer->stripe_checkout_url);
        $this->post(route('account.offers.pay', $offer))->assertRedirect($offer->stripe_checkout_url);

        $this->assertCount(1, $this->stripe->created, 'One Checkout Session, however often the button is pressed.');
        $this->assertSame($first, $offer->refresh()->stripe_checkout_session_id);
        $this->assertSame(1, AdminAuditEvent::where('event', 'offer.accepted')->count());
        $this->assertSame(OfferStatus::Accepted, $offer->status);

        // An expired Checkout Session is replaced, and the key of the retry differs from the first.
        $offer->forceFill(['stripe_checkout_expires_at' => now()->subMinute()])->save();
        $this->post(route('account.offers.pay', $offer))->assertRedirect();
        $this->assertCount(2, $this->stripe->created);
        $this->assertSame($first, $this->stripe->created[1]['previous']);
        $this->assertNotSame($first, $offer->refresh()->stripe_checkout_session_id);
    }

    public function test_when_stripe_cannot_start_the_payment_the_acceptance_is_kept_and_a_retry_works(): void
    {
        $offer = $this->offered();
        $this->stripe->fail = true;

        $this->asCustomer()->post(route('account.offers.accept', $offer))->assertRedirect(route('account.offers.show', $offer))->assertSessionHas('offer_error');
        $this->assertSame(OfferStatus::Accepted, $offer->refresh()->status);
        $this->assertNull($offer->stripe_checkout_session_id);
        $this->get(route('account.offers.show', $offer))->assertOk()->assertSee('The payment page could not be opened.')->assertSee('Continue to payment');

        $this->stripe->fail = false;
        $this->post(route('account.offers.pay', $offer))->assertRedirect();
        $this->assertNotNull($offer->refresh()->stripe_checkout_session_id);
    }

    public function test_declining_is_final_and_recorded(): void
    {
        $offer = $this->offered();

        $this->asCustomer()->post(route('account.offers.decline', $offer))->assertRedirect(route('account.offers.show', $offer))->assertSessionHas('status', 'You declined this offer.');

        $this->assertSame(OfferStatus::Declined, $offer->refresh()->status);
        $this->post(route('account.offers.accept', $offer))->assertSessionHasErrors('offer');
        $this->post(route('account.offers.decline', $offer))->assertSessionHasErrors('offer');
        $this->get(route('account.offers.show', $offer))->assertOk()->assertSee('You declined this offer.');
        $this->assertSame([], $this->stripe->created);
        $this->assertSame(1, AdminAuditEvent::where('event', 'offer.declined')->count());
    }

    // --- Expiry --------------------------------------------------------------

    public function test_an_expired_offer_cannot_be_accepted_and_the_job_records_the_expiry(): void
    {
        $offer = $this->offered(['valid_until' => now()->addDay()->format('Y-m-d\TH:i')]);
        $accepted = $this->accepted(['title' => 'Accepted but unpaid', 'valid_until' => now()->addDay()->format('Y-m-d\TH:i')]);
        $this->stripe->expired = [];
        $this->travel(2)->days();

        $this->asCustomer();
        $this->get(route('account.offers.show', $offer))->assertOk()->assertSee('Expired')->assertSee('no longer valid')->assertDontSee('Accept and pay');
        $this->post(route('account.offers.accept', $offer))->assertSessionHasErrors('offer');
        $this->post(route('account.offers.pay', $accepted))->assertSessionHasErrors('offer');
        $this->assertSame(OfferStatus::Offered, $offer->refresh()->status, 'Not recorded until the job runs.');

        $this->artisan('offers:expire')->assertSuccessful();
        $this->assertSame(OfferStatus::Expired, $offer->refresh()->status);
        $this->assertSame(OfferStatus::Expired, $accepted->refresh()->status);
        $this->assertNotNull($offer->expired_at);
        $this->assertSame([$accepted->stripe_checkout_session_id ?? $this->stripe->expired[0]], $this->stripe->expired, 'The open Checkout Session of the accepted offer is stopped.');
        $this->assertSame(2, AdminAuditEvent::where('event', 'offer.expired')->count());

        $this->artisan('offers:expire')->assertSuccessful();
        $this->assertSame(2, AdminAuditEvent::where('event', 'offer.expired')->count(), 'Repeating changes nothing.');
    }

    // --- Payment: the webhook is authoritative ------------------------------

    public function test_the_signed_webhook_marks_the_offer_paid_once_and_emails_once(): void
    {
        $offer = $this->accepted();
        $this->alice['customer']->user->forceFill(['locale' => 'hu'])->save();
        $customers = Customer::count();
        $subscriptions = Subscription::count();

        $this->paymentEvent($offer, eventId: 'evt_offer_paid')->assertStatus(204);

        $offer->refresh();
        $this->assertSame(OfferStatus::Paid, $offer->status);
        $this->assertNotNull($offer->paid_at);
        $this->assertSame(4500000, $offer->paid_amount);
        $this->assertSame('pi_test_offer_1', $offer->stripe_payment_intent_id);
        $this->assertNull($offer->stripe_checkout_url);
        Mail::assertQueued(RecoveryOfferPaid::class, 1);
        Mail::assertQueued(RecoveryOfferPaid::class, fn (RecoveryOfferPaid $mail) => $mail->hasTo('alice@company.example') && $mail->locale === 'hu');
        $this->assertStringContainsString('Megkaptuk az Ön', (new RecoveryOfferPaid($offer))->render());
        $this->assertSame(1, AdminAuditEvent::where('event', 'offer.paid')->count());

        // Replays: the same event, and a different event for the same session.
        $this->paymentEvent($offer, eventId: 'evt_offer_paid')->assertStatus(204);
        $this->paymentEvent($offer, eventId: 'evt_offer_paid_again')->assertStatus(204);
        $this->paymentEvent($offer, ['payment_status' => 'paid'], eventId: 'evt_async', type: 'checkout.session.async_payment_succeeded')->assertStatus(204);

        $this->assertSame(OfferStatus::Paid, $offer->refresh()->status);
        $this->assertSame(1, RecoveryOffer::count(), 'No duplicate offer.');
        Mail::assertQueued(RecoveryOfferPaid::class, 1);
        $this->assertSame(1, AdminAuditEvent::where('event', 'offer.paid')->count());
        $this->assertSame($customers, Customer::count(), 'A payment for an offer provisions no customer.');
        $this->assertSame($subscriptions, Subscription::count(), 'And no subscription.');
        $this->assertSame(3, StripeEvent::count(), 'Every distinct event id is recorded once; the replayed id was skipped.');
    }

    public function test_reaching_the_success_page_does_not_pay_the_offer(): void
    {
        $offer = $this->accepted();

        $this->asCustomer()->get(route('account.offers.show', $offer).'?payment=returned&session_id='.$offer->stripe_checkout_session_id)
            ->assertOk()->assertSee('We are waiting for Stripe to confirm your payment');

        $this->assertSame(OfferStatus::Accepted, $offer->refresh()->status);
        $this->assertNull($offer->paid_at);
        Mail::assertNotQueued(RecoveryOfferPaid::class);
    }

    public function test_a_payment_that_does_not_match_the_stored_offer_is_never_recorded_as_paid(): void
    {
        $offer = $this->accepted();

        $this->paymentEvent($offer, ['amount_total' => 100])->assertStatus(204);                        // wrong amount
        $this->paymentEvent($offer, ['currency' => 'eur'])->assertStatus(204);                           // wrong currency
        $this->paymentEvent($offer, ['id' => 'cs_test_other_session'])->assertStatus(204);              // not the saved session
        $this->paymentEvent($offer, ['payment_status' => 'unpaid'])->assertStatus(204);                  // delayed method still pending
        $this->paymentEvent($offer, ['metadata' => ['recovery_offer' => (string) Str::uuid(), 'kind' => 'recovery_offer'], 'client_reference_id' => (string) Str::uuid()])->assertStatus(204); // unknown offer

        $this->assertSame(OfferStatus::Accepted, $offer->refresh()->status);
        $this->assertNull($offer->paid_at);
        Mail::assertNotQueued(RecoveryOfferPaid::class);

        // A signature from anyone but Stripe is refused outright.
        $this->paymentEvent($offer, [], 'evt_forged')->assertStatus(204);
        $this->stripeEvent('checkout.session.completed', ['id' => $offer->stripe_checkout_session_id, 'metadata' => ['recovery_offer' => $offer->uuid, 'kind' => 'recovery_offer']], secret: 'whsec_forged')->assertStatus(400);
    }

    public function test_money_that_arrives_after_a_cancellation_or_expiry_is_still_recorded(): void
    {
        $cancelled = $this->accepted();
        $this->asOwner();
        $this->post(route('owner.offers.cancel', $cancelled));
        $this->paymentEvent($cancelled->refresh())->assertStatus(204);
        $this->assertSame(OfferStatus::Paid, $cancelled->refresh()->status, 'Stripe took the money: it is never lost from view.');
        $this->assertSame('cancelled', AdminAuditEvent::where('event', 'offer.paid')->sole()->data['status_before']);
    }

    public function test_a_subscription_checkout_event_is_not_confused_with_an_offer(): void
    {
        $offer = $this->accepted();

        // A subscription session that happens to reference an offer UUID is not an offer payment.
        $this->stripeEvent('checkout.session.completed', [
            'id' => $offer->stripe_checkout_session_id, 'client_reference_id' => $offer->uuid, 'payment_status' => 'paid', 'mode' => 'subscription', 'subscription' => 'sub_x', 'customer' => 'cus_x',
        ])->assertStatus(204);

        $this->assertSame(OfferStatus::Accepted, $offer->refresh()->status);
        $this->assertSame(0, Subscription::where('stripe_subscription_id', 'sub_x')->count());
    }

    // --- Stripe request ------------------------------------------------------

    public function test_the_stripe_session_is_a_one_time_payment_built_from_the_stored_offer_only(): void
    {
        $offer = $this->offered();
        $offer->forceFill(['amount' => 4500000])->save();

        $parameters = (new StripeOfferPaymentGateway('sk_test_x'))->sessionParameters($offer->load('customer'));

        $this->assertSame('payment', $parameters['mode']);
        $this->assertSame(1, $parameters['line_items'][0]['quantity']);
        $this->assertSame(4500000, $parameters['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('huf', $parameters['line_items'][0]['price_data']['currency']);
        $this->assertArrayNotHasKey('price', $parameters['line_items'][0], 'No Stripe Price ID: none is invented and none can be chosen.');
        $this->assertSame('Malware cleanup and verification', $parameters['line_items'][0]['price_data']['product_data']['name']);
        $this->assertSame($offer->uuid, $parameters['client_reference_id']);
        $this->assertSame(['recovery_offer' => $offer->uuid, 'kind' => 'recovery_offer'], $parameters['metadata']);
        $this->assertSame('alice@company.example', $parameters['customer_email']);
        $this->assertArrayNotHasKey('subscription_data', $parameters);
        $this->assertArrayNotHasKey('discounts', $parameters, 'Coupons apply to subscriptions only.');
        $this->assertStringContainsString('/account/offers/'.$offer->uuid, $parameters['success_url']);
        $this->assertSame(route('account.offers.show', $offer), $parameters['cancel_url']);
        $this->assertGreaterThanOrEqual(now()->addMinutes(30)->timestamp, $parameters['expires_at']);
        $this->assertLessThanOrEqual(now()->addHours(24)->timestamp, $parameters['expires_at']);
        $this->assertStringNotContainsString('Secret margin note', json_encode($parameters));

        // An existing Stripe customer is reused.
        $this->alice['customer']->update(['stripe_customer_id' => 'cus_alice']);
        $withCustomer = (new StripeOfferPaymentGateway('sk_test_x'))->sessionParameters($offer->refresh()->load('customer'));
        $this->assertSame('cus_alice', $withCustomer['customer']);
        $this->assertArrayNotHasKey('customer_email', $withCustomer);
    }

    public function test_live_keys_and_missing_keys_are_refused_for_offer_payments(): void
    {
        $offer = $this->offered();
        foreach ([['sk_live_abc', false], [null, false]] as [$secret, $allow]) {
            try {
                (new StripeOfferPaymentGateway($secret, $allow))->createSession($offer);
                $this->fail('A session was created.');
            } catch (CheckoutUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // --- Owner area security --------------------------------------------------

    public function test_only_owners_reach_the_offers_and_their_notes(): void
    {
        $offer = $this->offered();

        $this->get(route('owner.offers.index'))->assertRedirect(route('owner.login'));
        foreach ([['operator', User::factory()->operator(Permission::cases())->create()], ['web', $this->alice['customer']->user]] as [$guard, $user]) {
            $this->actingAs($user, $guard);
            $this->get(route('owner.offers.show', $offer))->assertRedirect(route('owner.login'));
            $this->post(route('owner.offers.cancel', $offer))->assertRedirect(route('owner.login'));
            $this->post(route('owner.offers.send', $offer), ['confirm' => '1'])->assertRedirect(route('owner.login'));
            $this->put(route('owner.offers.update', $offer), $this->fields())->assertRedirect(route('owner.login'));
            $this->post(route('owner.offers.store'), [])->assertRedirect(route('owner.login'));
        }
        $this->assertSame(OfferStatus::Offered, $offer->refresh()->status);

        $this->asOwner();
        $this->get(route('owner.offers.index'))->assertOk()->assertSee('Malware cleanup and verification');
        $this->get(route('owner.offers.show', $offer))->assertOk()->assertSee('Secret margin note')->assertSee('Offered');
    }

    public function test_owner_offer_forms_are_csrf_protected_and_every_route_names_the_offers_ability(): void
    {
        $offer = $this->draft();
        $this->enforceCsrf();

        $this->post(route('owner.offers.send', $offer), ['confirm' => '1'])->assertStatus(419);
        $this->post(route('owner.offers.cancel', $offer))->assertStatus(419);
        $this->put(route('owner.offers.update', $offer), $this->fields())->assertStatus(419);
        $this->newOffer()->assertStatus(419);
        $this->assertSame(OfferStatus::Draft, $offer->refresh()->status);
        $this->assertSame(1, RecoveryOffer::count());

        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'owner.offers.'));
        $this->assertCount(8, $routes);
        foreach ($routes as $route) {
            $this->assertContains('can:owner.offers.manage', $route->gatherMiddleware(), $route->getName());
            if (array_intersect(['POST', 'PUT'], $route->methods())) {
                $this->assertNotEmpty(array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')), $route->getName());
            }
        }

        foreach (['account.offers.accept', 'account.offers.pay', 'account.offers.decline'] as $name) {
            $this->assertContains('throttle:account', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
            $this->assertContains('customer', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    public function test_customer_offer_forms_are_csrf_protected(): void
    {
        $offer = $this->offered();
        $this->enforceCsrf();

        $this->asCustomer()->post(route('account.offers.accept', $offer))->assertStatus(419);
        $this->post(route('account.offers.decline', $offer))->assertStatus(419);
        $this->assertSame(OfferStatus::Offered, $offer->refresh()->status);
    }

    public function test_the_service_refuses_an_offer_for_a_website_of_another_customer_even_when_called_directly(): void
    {
        $owner = $this->owner();

        $this->expectException(ValidationException::class);
        app(RecoveryOffers::class)->create($this->alice['customer'], [
            'title' => 'x', 'scope' => 'x', 'amount' => '1000', 'currency' => 'HUF', 'valid_until' => Carbon::now()->addDay(), 'website' => $this->bob['website']->uuid,
        ], $owner);
    }

    public function test_internal_notes_and_checkout_links_are_never_serialised(): void
    {
        $offer = $this->accepted();

        $array = $offer->toArray();
        $this->assertArrayNotHasKey('internal_notes', $array);
        $this->assertArrayNotHasKey('stripe_checkout_url', $array);
    }
}
