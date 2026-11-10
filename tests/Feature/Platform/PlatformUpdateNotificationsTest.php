<?php

namespace Tests\Feature\Platform;

use App\Jobs\RunPlatformUpdateCheck;
use App\Mail\PlatformUpdateNotice;
use App\Models\AdminAuditEvent;
use App\Models\Customer;
use App\Models\PlatformAdvisory;
use App\Models\PlatformComponent;
use App\Models\PlatformUpdateCheck;
use App\Models\PlatformUpdateNotification;
use App\Models\User;
use App\Platform\Updates\PlatformUpdateNotifier;
use App\Platform\Updates\PlatformUpdateScanner;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Phase D: Owner notifications, de-duplication, daily summary, staging isolation. */
class PlatformUpdateNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform_updates.enabled' => true, 'app.deployment_environment' => 'testing']);
        $this->owner = User::factory()->owner()->create(['email' => 'owner@wpcaregrid.example', 'email_verified_at' => now()]);
        Mail::fake();
    }

    private function advisory(array $extra = [], array $component = []): PlatformAdvisory
    {
        $key = $component['component_key'] ?? 'composer:acme/vulnerable';
        $row = PlatformComponent::firstOrCreate(['component_key' => $key], $component + [
            'kind' => 'composer', 'name' => substr($key, strpos($key, ':') + 1), 'ecosystem' => 'Packagist', 'installed_version' => '1.2.0',
            'version_state' => 'VERIFIED', 'advisory_state' => 'CHECKED', 'evidence_source' => 'composer.lock', 'present' => true,
        ]);

        return PlatformAdvisory::create($extra + [
            'platform_component_id' => $row->id, 'advisory_id' => 'GHSA-aaaa-bbbb-cccc', 'aliases' => ['CVE-2026-0001'], 'severity' => 'critical',
            'summary' => 'x', 'affected_version' => '1.2.0', 'fixed_version' => '1.2.5', 'source' => 'osv',
            'source_url' => 'https://osv.dev/vulnerability/GHSA-aaaa-bbbb-cccc', 'state' => 'open',
        ]);
    }

    private function check(string $status): PlatformUpdateCheck
    {
        return PlatformUpdateCheck::create(['uuid' => (string) Str::uuid(), 'trigger' => 'scheduled', 'status' => $status, 'started_at' => now()->subMinute(), 'finished_at' => now()]);
    }

    private function notifier(): PlatformUpdateNotifier
    {
        return app(PlatformUpdateNotifier::class);
    }

    public function test_a_critical_advisory_sends_one_prominent_notice_and_never_a_second(): void
    {
        $advisory = $this->advisory();
        $check = $this->check('succeeded');

        $this->notifier()->afterCheck($check);
        $this->notifier()->afterCheck($check);
        $this->notifier()->afterCheck($this->check('succeeded'));

        Mail::assertSent(PlatformUpdateNotice::class, 1);
        Mail::assertSent(PlatformUpdateNotice::class, fn ($mail) => $mail->hasTo('owner@wpcaregrid.example') && $mail->kind === 'critical' && $mail->items[0]['advisory'] === 'GHSA-aaaa-bbbb-cccc' && $mail->items[0]['fixed'] === '1.2.5');
        $this->assertNotNull($advisory->fresh()->notified_at);
        $this->assertSame([['critical', 'sent', 1]], PlatformUpdateNotification::query()->get(['kind', 'status', 'items'])->map(fn ($n) => [$n->kind, $n->status, $n->items])->all());
    }

    public function test_a_non_critical_advisory_is_a_regular_security_notice(): void
    {
        $this->advisory(['severity' => 'medium']);

        $this->notifier()->afterCheck($this->check('succeeded'));

        Mail::assertSent(PlatformUpdateNotice::class, fn ($mail) => $mail->kind === 'security');
    }

    public function test_critical_and_other_advisories_are_announced_separately(): void
    {
        $this->advisory();
        $this->advisory(['advisory_id' => 'GHSA-low1-low1-low1', 'aliases' => [], 'severity' => 'low']);

        $this->notifier()->afterCheck($this->check('succeeded'));

        Mail::assertSent(PlatformUpdateNotice::class, 2);
    }

    public function test_the_same_vulnerability_under_another_id_is_not_announced_again(): void
    {
        $this->advisory();
        $this->notifier()->afterCheck($this->check('succeeded'));
        Mail::assertSent(PlatformUpdateNotice::class, 1);

        $duplicate = $this->advisory(['advisory_id' => 'CVE-2026-0001', 'aliases' => ['GHSA-aaaa-bbbb-cccc']]);
        $this->notifier()->afterCheck($this->check('succeeded'));

        Mail::assertSent(PlatformUpdateNotice::class, 1);
        $this->assertNotNull($duplicate->fresh()->notified_at, 'marked announced without a mail');
    }

    public function test_a_duplicate_inside_one_batch_is_announced_once(): void
    {
        $this->advisory(['severity' => 'high']);
        $this->advisory(['advisory_id' => 'CVE-2026-0001', 'aliases' => ['GHSA-aaaa-bbbb-cccc'], 'severity' => 'high']);

        $this->notifier()->afterCheck($this->check('succeeded'));

        Mail::assertSent(PlatformUpdateNotice::class, fn ($mail) => count($mail->items) === 1);
    }

    public function test_development_only_resolved_and_unverifiable_data_never_notify(): void
    {
        $this->advisory([], ['component_key' => 'composer:acme/dev', 'development_only' => true]);
        $this->advisory(['advisory_id' => 'GHSA-old1-old1-old1', 'state' => 'resolved']);
        PlatformComponent::create(['component_key' => 'runtime:php', 'kind' => 'runtime', 'name' => 'PHP', 'installed_version' => null, 'version_state' => 'UNKNOWN', 'advisory_state' => 'UNVERIFIED', 'evidence_source' => 'php_runtime', 'present' => true]);

        $this->notifier()->afterCheck($this->check('succeeded'));

        Mail::assertNothingSent();
    }

    public function test_a_failed_check_sends_one_system_warning_per_day(): void
    {
        $this->notifier()->afterCheck($this->check('failed'));
        $this->notifier()->afterCheck($this->check('failed'));
        Mail::assertSent(PlatformUpdateNotice::class, 1);
        Mail::assertSent(PlatformUpdateNotice::class, fn ($mail) => $mail->kind === 'failure');

        $this->travel(1)->day();
        $this->notifier()->afterCheck($this->check('failed'));

        Mail::assertSent(PlatformUpdateNotice::class, 2);
    }

    public function test_refused_and_running_checks_send_nothing(): void
    {
        $this->advisory();
        foreach (['refused', 'running'] as $status) {
            $this->notifier()->afterCheck($this->check($status));
        }

        Mail::assertNothingSent();
    }

    public function test_the_daily_summary_is_sent_once_a_day_and_only_when_there_is_something_to_report(): void
    {
        $this->check('succeeded');
        $this->assertFalse($this->notifier()->digest(), 'clean and fresh: nothing to report');
        Mail::assertNothingSent();

        $this->advisory(['severity' => 'medium', 'notified_at' => now()]);
        $this->assertTrue($this->notifier()->digest());
        $this->assertFalse($this->notifier()->digest(), 'once a day');
        Mail::assertSent(PlatformUpdateNotice::class, 1);
        Mail::assertSent(PlatformUpdateNotice::class, fn ($mail) => $mail->kind === 'digest' && $mail->summary['open'] === 1 && count($mail->items) === 1);

        $this->travel(1)->day();
        $this->assertTrue($this->notifier()->digest());
    }

    public function test_the_summary_reports_a_stale_or_failed_state_even_without_advisories(): void
    {
        $this->assertTrue($this->notifier()->digest(), 'never checked is worth a line');
        $this->travel(1)->day();
        PlatformUpdateCheck::query()->delete();
        $this->check('failed');

        $this->assertTrue($this->notifier()->digest());
    }

    public function test_a_mail_failure_does_not_mark_anything_as_announced_and_is_retried(): void
    {
        $advisory = $this->advisory();
        $manager = \Mockery::mock(MailManager::class);
        $manager->shouldReceive('getDefaultDriver')->andReturn('array');
        $manager->shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
        Mail::swap($manager);   // a mail transport that fails

        $this->notifier()->afterCheck($this->check('succeeded'));

        $this->assertNull($advisory->fresh()->notified_at);
        $failed = PlatformUpdateNotification::sole();
        $this->assertSame(['failed', 'mail_transport_failed', null], [$failed->status, $failed->failure_code, $failed->dedupe_key]);

        Mail::fake();
        $this->notifier()->afterCheck($this->check('succeeded'));

        Mail::assertSent(PlatformUpdateNotice::class, 1);
        $this->assertNotNull($advisory->fresh()->notified_at);
    }

    public function test_only_verified_owners_without_a_customer_account_receive_notices(): void
    {
        User::factory()->owner()->create(['email' => 'unverified@wpcaregrid.example', 'email_verified_at' => null]);
        User::factory()->operator()->create(['email' => 'operator@wpcaregrid.example', 'email_verified_at' => now()]);
        Customer::factory()->withUser()->create();
        $this->advisory();

        $this->notifier()->afterCheck($this->check('succeeded'));

        Mail::assertSent(PlatformUpdateNotice::class, 1);
        Mail::assertSent(PlatformUpdateNotice::class, fn ($mail) => $mail->hasTo('owner@wpcaregrid.example') && ! $mail->hasTo('operator@wpcaregrid.example') && ! $mail->hasTo('unverified@wpcaregrid.example'));
    }

    public function test_nothing_is_sent_when_the_feature_is_disabled_or_there_is_no_recipient(): void
    {
        $this->advisory();
        config(['platform_updates.enabled' => false]);
        $this->notifier()->afterCheck($this->check('succeeded'));
        $this->assertFalse($this->notifier()->digest());
        Mail::assertNothingSent();

        config(['platform_updates.enabled' => true]);
        User::query()->update(['email_verified_at' => null]);
        $this->notifier()->afterCheck($this->check('succeeded'));
        Mail::assertNothingSent();
        $this->assertSame(0, PlatformUpdateNotification::count());
    }

    public function test_staging_next_sends_nothing_unless_the_existing_mail_guard_confines_it(): void
    {
        $this->advisory();
        config(['app.deployment_environment' => 'staging_next', 'mail.default' => 'smtp', 'marketing_release.staging_mail_allowlist' => []]);
        $this->notifier()->afterCheck($this->check('succeeded'));
        $this->assertFalse($this->notifier()->digest());
        Mail::assertNothingSent();

        config(['marketing_release.staging_mail_allowlist' => ['owner@wpcaregrid.example']]);
        $this->notifier()->afterCheck($this->check('succeeded'));
        Mail::assertSent(PlatformUpdateNotice::class, 1);
    }

    public function test_the_notice_has_facts_a_link_and_no_action_that_changes_anything(): void
    {
        $this->advisory(['summary' => '<script>x</script>']);
        $mail = new PlatformUpdateNotice('critical', [[
            'component' => 'acme/<b>vulnerable</b>', 'installed' => '1.2.0', 'advisory' => 'GHSA-aaaa-bbbb-cccc', 'aliases' => 'CVE-2026-0001',
            'severity' => 'critical', 'fixed' => '1.2.5', 'source' => 'https://osv.dev/vulnerability/GHSA-aaaa-bbbb-cccc',
        ]]);

        $html = $mail->render();

        $this->assertStringContainsString('GHSA-aaaa-bbbb-cccc', $html);
        $this->assertStringContainsString('fixed in 1.2.5', $html);
        $this->assertStringContainsString(route('owner.platform-updates.index'), $html);
        $this->assertStringContainsString('Nothing is updated automatically', $html);
        $this->assertStringNotContainsString('<b>vulnerable</b>', $html, 'escaped');
        foreach (['composer update', 'apt upgrade', 'sudo'] as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }
    }

    public function test_a_fix_proven_for_another_version_is_not_mailed_and_the_real_installed_version_is(): void
    {
        // The inventory moved to 1.2.1 while the source was unreachable: the row still holds the fix proven for 1.2.0.
        $this->advisory(['affected_version' => '1.2.0', 'fixed_version' => '1.2.5'], ['installed_version' => '1.2.1']);
        $this->check('failed');

        $this->assertTrue($this->notifier()->digest());

        $html = null;
        Mail::assertSent(PlatformUpdateNotice::class, function (PlatformUpdateNotice $mail) use (&$html) {
            $html = $mail->render();

            return true;
        });
        $this->assertStringContainsString('acme/vulnerable 1.2.1', (string) $html);
        $this->assertStringNotContainsString('1.2.5', (string) $html);
        $this->assertStringNotContainsString('fixed in', (string) $html);
    }

    public function test_a_confirmed_fix_is_mailed_with_the_installed_version(): void
    {
        $this->advisory(['affected_version' => '1.2.1', 'fixed_version' => '1.2.5'], ['installed_version' => '1.2.1']);

        $this->notifier()->afterCheck($this->check('succeeded'));

        $html = null;
        Mail::assertSent(PlatformUpdateNotice::class, function (PlatformUpdateNotice $mail) use (&$html) {
            $html = $mail->render();

            return true;
        });
        $this->assertStringContainsString('acme/vulnerable 1.2.1', (string) $html);
        $this->assertStringContainsString('fixed in 1.2.5', (string) $html);
    }

    public function test_a_reopened_advisory_is_announced_again(): void
    {
        $advisory = $this->advisory();
        $this->notifier()->afterCheck($this->check('succeeded'));
        $advisory->forceFill(['state' => 'resolved'])->save();
        $advisory->forceFill(['state' => 'open', 'notified_at' => null])->save();

        $this->notifier()->afterCheck($this->check('succeeded'));

        Mail::assertSent(PlatformUpdateNotice::class, 2);
    }

    public function test_the_failure_notice_states_the_real_unverified_count(): void
    {
        PlatformComponent::create(['component_key' => 'runtime:php', 'kind' => 'runtime', 'name' => 'php', 'installed_version' => '8.4.1', 'version_state' => 'VERIFIED',
            'advisory_state' => 'UNVERIFIED', 'evidence_source' => 'runtime', 'present' => true]);

        $this->notifier()->afterCheck($this->check('failed'));

        Mail::assertSent(PlatformUpdateNotice::class, fn ($mail) => $mail->kind === 'failure' && $mail->summary['unverified'] === 1);
    }

    public function test_an_advisory_with_unread_severity_is_held_back_until_it_is_read(): void
    {
        $advisory = $this->advisory(['severity' => 'unknown', 'details_checked_at' => null]);

        $this->notifier()->afterCheck($this->check('succeeded'));
        Mail::assertNothingSent();

        $advisory->forceFill(['severity' => 'critical', 'details_checked_at' => now()])->save();
        $this->notifier()->afterCheck($this->check('succeeded'));
        Mail::assertSent(PlatformUpdateNotice::class, fn ($mail) => $mail->kind === 'critical');
    }

    /** A mail layer whose send() outcome is decided per recipient: an exception, null (message stopped) or a SentMessage. */
    private function mailerWith(callable $outcome): void
    {
        $manager = \Mockery::mock(MailManager::class);
        $manager->shouldReceive('getDefaultDriver')->andReturn('array');
        $manager->shouldReceive('to')->andReturnUsing(function ($email) use ($outcome) {
            $pending = \Mockery::mock();
            $pending->shouldReceive('send')->andReturnUsing(fn () => $outcome((string) $email));

            return $pending;
        });
        Mail::swap($manager);
    }

    private function sentMessage(): SentMessage
    {
        return \Mockery::mock(SentMessage::class);
    }

    private function platformAudit(): Collection
    {
        return AdminAuditEvent::query()->where('event', 'like', 'platform_update.%')->orderBy('id')->get();
    }

    public function test_a_successful_notification_writes_no_failure_log_and_no_audit_entry(): void
    {
        Log::spy();
        $this->advisory();

        $this->notifier()->afterCheck($this->check('succeeded'));

        Mail::assertSent(PlatformUpdateNotice::class, 1);
        $this->assertSame('sent', PlatformUpdateNotification::sole()->status);
        $this->assertCount(0, $this->platformAudit());
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_a_message_stopped_without_an_exception_is_a_failure_and_never_reported_as_sent(): void
    {
        Log::spy();
        $advisory = $this->advisory();
        $this->mailerWith(fn () => null);   // the staging mail guard (a MessageSending listener) stops a message: send() returns null

        $this->notifier()->afterCheck($this->check('succeeded'));

        $claim = PlatformUpdateNotification::sole();
        $this->assertSame(['failed', 'mail_not_sent', null], [$claim->status, $claim->failure_code, $claim->dedupe_key]);
        $this->assertNull($advisory->fresh()->notified_at);
        $audit = $this->platformAudit();
        $this->assertSame(['platform_update.notification_failed'], $audit->pluck('event')->all());
        $this->assertSame('mail_not_sent', $audit->first()->data['code']);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_delivery_exception_is_logged_and_audited_with_correlation_and_without_secrets(): void
    {
        Log::spy();
        $this->advisory();
        $check = $this->check('succeeded');
        $this->mailerWith(function () {
            throw new \RuntimeException('smtp://mailer:s3cret-pass@smtp.example:587 refused for owner@wpcaregrid.example, body: Remote code execution');
        });

        $this->notifier()->afterCheck($check);

        $audit = $this->platformAudit();
        $this->assertCount(1, $audit);
        $data = $audit->first()->data;
        $this->assertSame('platform_update.notification_failed', $audit->first()->event);
        $this->assertSame(
            ['mail_transport_failed', 'RuntimeException', $check->uuid, substr(sha1('owner@wpcaregrid.example'), 0, 8), PlatformUpdateNotification::sole()->id],
            [$data['code'], $data['exception'], $data['check'], $data['recipient'], $data['notification_id']]
        );
        $this->assertNotEmpty($data['correlation']);
        $this->assertSame('system', $audit->first()->actor_type);

        $logged = null;
        Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context) use (&$logged) {
            $logged = $context;

            return true;
        });
        $this->assertSame($data['correlation'], $logged['correlation']);
        $everything = json_encode([$data, $logged, $audit->first()->subject_key, $audit->first()->subject_type]);
        foreach (['s3cret-pass', 'smtp.example', 'owner@wpcaregrid.example', 'Remote code execution'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $everything, "{$secret} must not reach the log or the audit trail");
        }
    }

    public function test_repeating_after_a_failure_sends_once_and_never_duplicates_or_re_audits(): void
    {
        $advisory = $this->advisory();
        $this->mailerWith(function () {
            throw new \RuntimeException('smtp down');
        });
        $this->notifier()->afterCheck($this->check('succeeded'));
        $this->assertCount(1, $this->platformAudit());

        Mail::fake();   // the transport recovered
        $this->notifier()->afterCheck($this->check('succeeded'));
        $this->notifier()->afterCheck($this->check('succeeded'));
        $this->notifier()->afterCheck($this->check('succeeded'));

        Mail::assertSent(PlatformUpdateNotice::class, 1);
        $this->assertNotNull($advisory->fresh()->notified_at);
        $this->assertCount(1, $this->platformAudit(), 'no further failure entries once it was delivered');
        $this->assertSame(['failed', 'sent'], PlatformUpdateNotification::query()->orderBy('id')->pluck('status')->all());
    }

    public function test_recovery_after_a_partial_failure_reaches_only_the_owner_who_missed_it(): void
    {
        User::factory()->owner()->create(['email' => 'second@wpcaregrid.example', 'email_verified_at' => now()]);
        $advisory = $this->advisory();
        $this->mailerWith(function (string $email) {
            if ($email === 'owner@wpcaregrid.example') {
                throw new \RuntimeException('smtp timeout');
            }

            return $this->sentMessage();
        });

        $this->notifier()->afterCheck($this->check('succeeded'));

        $this->assertNull($advisory->fresh()->notified_at, 'the round is not complete');
        $this->assertSame(['failed', 'sent'], PlatformUpdateNotification::query()->orderBy('id')->pluck('status')->all());
        $this->assertCount(1, $this->platformAudit());

        Mail::fake();
        $this->notifier()->afterCheck($this->check('succeeded'));

        Mail::assertSent(PlatformUpdateNotice::class, 1);
        Mail::assertSent(PlatformUpdateNotice::class, fn ($mail) => $mail->hasTo('owner@wpcaregrid.example'));
        Mail::assertNotSent(PlatformUpdateNotice::class, fn ($mail) => $mail->hasTo('second@wpcaregrid.example'));
        $this->assertNotNull($advisory->fresh()->notified_at);
    }

    public function test_a_message_that_went_out_but_could_not_be_recorded_counts_as_delivered_and_is_audited(): void
    {
        Log::spy();
        $advisory = $this->advisory();
        $listener = 'eloquent.updating: '.PlatformUpdateNotification::class;
        Event::listen($listener, function ($model) {
            if ($model->status === 'sent') {
                throw new \RuntimeException('database write failed');
            }
        });
        try {
            $this->notifier()->afterCheck($this->check('succeeded'));
        } finally {
            Event::forget($listener);
        }

        Mail::assertSent(PlatformUpdateNotice::class, 1);
        $this->assertNotNull($advisory->fresh()->notified_at, 'it was delivered: a retry would be a duplicate');
        $this->assertSame(['platform_update.notification_unrecorded'], $this->platformAudit()->pluck('event')->all());
        $this->assertSame('claim_not_marked_sent', $this->platformAudit()->first()->data['code']);
        Log::shouldHaveReceived('error')->once();

        $this->notifier()->afterCheck($this->check('succeeded'));
        Mail::assertSent(PlatformUpdateNotice::class, 1);
    }

    public function test_an_abandoned_pending_claim_does_not_block_the_notice(): void
    {
        $this->advisory();
        $this->notifier()->afterCheck($this->check('succeeded'));
        PlatformUpdateNotification::query()->update(['status' => 'pending', 'created_at' => now()->subHour()]);
        PlatformAdvisory::query()->update(['notified_at' => null]);

        $this->notifier()->afterCheck($this->check('succeeded'));

        Mail::assertSent(PlatformUpdateNotice::class, 2);
    }

    public function test_one_owner_failing_does_not_cause_a_second_mail_to_the_others(): void
    {
        User::factory()->owner()->create(['email' => 'second@wpcaregrid.example', 'email_verified_at' => now()]);
        $this->advisory();

        $this->notifier()->afterCheck($this->check('succeeded'));
        $this->assertNotNull(PlatformAdvisory::first()->notified_at);
        Mail::assertSent(PlatformUpdateNotice::class, 2);

        $this->notifier()->afterCheck($this->check('succeeded'));
        Mail::assertSent(PlatformUpdateNotice::class, 2);
    }

    public function test_a_notice_lists_at_most_the_item_cap_and_counts_the_rest(): void
    {
        for ($i = 0; $i < PlatformUpdateNotifier::MAX_ITEMS + 5; $i++) {
            $this->advisory(['advisory_id' => 'GHSA-n'.str_pad((string) $i, 3, '0', STR_PAD_LEFT).'-aaaa-bbbb', 'aliases' => []]);
        }

        $this->notifier()->afterCheck($this->check('succeeded'));

        Mail::assertSent(PlatformUpdateNotice::class, fn ($mail) => count($mail->items) === PlatformUpdateNotifier::MAX_ITEMS && $mail->more === 5);
    }

    private function fakeOsvCritical(): void
    {
        Http::swap(new Factory);
        Http::fake([
            'api.osv.dev/v1/querybatch' => fn ($request) => Http::response(['results' => array_map(
                fn ($q) => $q['package']['name'] === 'laravel/framework' ? ['vulns' => [['id' => 'GHSA-zzzz-zzzz-zzzz']]] : (object) [], $request['queries'],
            )], 200),
            'api.osv.dev/v1/vulns/*' => Http::response(['id' => 'GHSA-zzzz-zzzz-zzzz', 'summary' => 's', 'database_specific' => ['severity' => 'CRITICAL'], 'aliases' => []], 200),
        ]);
    }

    private function fakeOsvSeverity(string $severity): void
    {
        Http::swap(new Factory);
        Http::fake([
            'api.osv.dev/v1/querybatch' => fn ($request) => Http::response(['results' => array_map(
                fn ($q) => $q['package']['name'] === 'laravel/framework' ? ['vulns' => [['id' => 'GHSA-zzzz-zzzz-zzzz']]] : (object) [], $request['queries'],
            )], 200),
            'api.osv.dev/v1/vulns/*' => Http::response(['id' => 'GHSA-zzzz-zzzz-zzzz', 'summary' => 's', 'database_specific' => ['severity' => $severity], 'aliases' => []], 200),
        ]);
    }

    public function test_an_advisory_announced_as_non_critical_is_escalated_with_a_critical_notice_when_it_becomes_critical(): void
    {
        config(['platform_updates.min_interval_seconds' => 0]);
        $this->fakeOsvSeverity('LOW');
        $this->artisan('platform:updates:check')->assertSuccessful();
        Mail::assertSent(PlatformUpdateNotice::class, 1);
        Mail::assertSent(PlatformUpdateNotice::class, fn ($mail) => $mail->kind === 'security');

        $this->travel(25)->hours();   // the source re-read after a day: the publisher raised it to critical
        $this->fakeOsvSeverity('CRITICAL');
        $this->artisan('platform:updates:check')->assertSuccessful();

        Mail::assertSent(PlatformUpdateNotice::class, 2);
        Mail::assertSent(PlatformUpdateNotice::class, fn ($mail) => $mail->kind === 'critical' && $mail->items[0]['advisory'] === 'GHSA-zzzz-zzzz-zzzz' && $mail->items[0]['severity'] === 'critical');

        $this->travel(25)->hours();   // and it is announced exactly once as critical
        $this->artisan('platform:updates:check')->assertSuccessful();
        Mail::assertSent(PlatformUpdateNotice::class, 2);
    }

    public function test_the_check_command_notifies_after_a_check(): void
    {
        config(['platform_updates.min_interval_seconds' => 0]);
        $this->fakeOsvCritical();

        $this->artisan('platform:updates:check')->assertSuccessful();

        Mail::assertSent(PlatformUpdateNotice::class, fn ($mail) => $mail->kind === 'critical' && $mail->items[0]['advisory'] === 'GHSA-zzzz-zzzz-zzzz');
    }

    public function test_the_manual_job_notifies_after_a_check(): void
    {
        config(['platform_updates.min_interval_seconds' => 0]);
        $this->fakeOsvCritical();

        (new RunPlatformUpdateCheck)->handle(app(PlatformUpdateScanner::class), app(PlatformUpdateNotifier::class));

        Mail::assertSent(PlatformUpdateNotice::class, fn ($mail) => $mail->kind === 'critical');
    }

    public function test_the_digest_command_reports_a_fixed_line(): void
    {
        $this->advisory(['severity' => 'medium']);

        $this->artisan('platform:updates:digest')->expectsOutput('PLATFORM_UPDATE_DIGEST=SENT')->assertSuccessful();
        $this->artisan('platform:updates:digest')->expectsOutput('PLATFORM_UPDATE_DIGEST=SKIPPED')->assertSuccessful();
    }
}
