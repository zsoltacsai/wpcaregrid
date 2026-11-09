<?php

namespace Tests\Feature;

use App\Release\StagingMailGuard;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Staging-next must never mail a real customer: only a sink mailer or an allowlisted
 * recipient may send, for every recipient field, in the web process and in workers.
 */
class StagingMailGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.deployment_environment' => 'staging_next',
            'marketing_release.requested_profile' => 'staging_active',
            'marketing_release.staging_mail_allowlist' => [],
            // A real (non-sink) transport that can never connect: if the guard lets a message through, sending throws.
            'mail.default' => 'unit_smtp',
            'mail.mailers.unit_smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1],
            'mail.from' => ['address' => 'staging@example.test', 'name' => 'Staging'],
        ]);
    }

    public function test_nothing_is_sent_without_an_allowlist(): void
    {
        Log::spy();

        $this->assertFalse($this->guard($this->email(['customer@example.com'])));
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Staging mail blocked.'
                && $context === ['reason' => 'no_allowlist', 'recipients' => 1]
        );
    }

    public function test_exact_addresses_and_domains_on_the_allowlist_may_receive_mail(): void
    {
        config(['marketing_release.staging_mail_allowlist' => ['owner@example.test', '@sink.example']]);

        $this->assertNull($this->guard($this->email(['owner@example.test'])));
        $this->assertNull($this->guard($this->email(['OWNER@Example.Test'])));
        $this->assertNull($this->guard($this->email(['anyone@sink.example'])));
        $this->assertNull($this->guard($this->email(['owner@example.test', 'qa@sink.example'])));
    }

    public function test_one_foreign_recipient_blocks_the_whole_message(): void
    {
        config(['marketing_release.staging_mail_allowlist' => ['owner@example.test', '@sink.example']]);

        $this->assertFalse($this->guard($this->email(['owner@example.test', 'customer@example.com'])));
        $this->assertFalse($this->guard($this->email(['owner@example.test'], cc: ['customer@example.com'])));
        $this->assertFalse($this->guard($this->email(['owner@example.test'], bcc: ['customer@example.com'])));
        // Look-alikes of an allowlisted entry are not on the list.
        $this->assertFalse($this->guard($this->email(['xowner@example.test'])));
        $this->assertFalse($this->guard($this->email(['owner@example.test.evil.example'])));
        $this->assertFalse($this->guard($this->email(['a@notsink.example'])));
        $this->assertFalse($this->guard($this->email(['a@sub.sink.example'])));
    }

    public function test_a_message_without_recipients_is_not_sent(): void
    {
        config(['marketing_release.staging_mail_allowlist' => ['owner@example.test']]);

        $this->assertFalse($this->guard($this->email([])));
    }

    public function test_log_and_array_mailers_are_sinks(): void
    {
        foreach (['log', 'array'] as $mailer) {
            config(['mail.default' => $mailer]);
            $this->assertNull($this->guard($this->email(['customer@example.com'])), $mailer);
        }
    }

    public function test_the_log_never_contains_recipients_subject_or_body(): void
    {
        Log::spy();
        $this->guard($this->email(['secret.customer@example.com'], subject: 'Invoice 4711', body: 'token=abcdef'));

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => ! str_contains(json_encode([$message, $context]), 'secret.customer')
                && ! str_contains(json_encode([$message, $context]), '4711')
                && ! str_contains(json_encode([$message, $context]), 'abcdef')
        );
    }

    public function test_the_guard_also_applies_when_the_profile_was_refused(): void
    {
        // Wrong deployment identity but the staging_active profile was requested: still no customer mail.
        config(['app.deployment_environment' => 'production', 'marketing_release.requested_profile' => 'staging_active']);

        $this->assertFalse($this->guard($this->email(['customer@example.com'])));
    }

    public function test_other_deployments_are_not_affected(): void
    {
        config(['app.deployment_environment' => 'production', 'marketing_release.requested_profile' => 'marketing_only', 'marketing_release.profile' => 'marketing_only']);

        $this->assertNull($this->guard($this->email(['customer@example.com'])));
    }

    public function test_the_real_mailer_honours_the_guard(): void
    {
        config(['marketing_release.staging_mail_allowlist' => ['owner@example.test']]);
        Mail::purge('unit_smtp');

        // Blocked: the transport is never reached, so there is no connection error.
        Mail::mailer('unit_smtp')->raw('body', fn ($message) => $message->to('customer@example.com')->subject('s'));
        $this->addToAssertionCount(1);

        // Allowed: the guard lets it through, so the (unreachable) transport is attempted.
        $this->expectException(TransportExceptionInterface::class);
        Mail::mailer('unit_smtp')->raw('body', fn ($message) => $message->to('owner@example.test')->subject('s'));
    }

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     */
    private function email(array $to, array $cc = [], array $bcc = [], string $subject = 'Subject', string $body = 'Body'): Email
    {
        $email = (new Email)->from('staging@example.test')->subject($subject)->text($body);
        if ($to !== []) {
            $email->to(...$to);
        }
        if ($cc !== []) {
            $email->cc(...$cc);
        }
        if ($bcc !== []) {
            $email->bcc(...$bcc);
        }

        return $email;
    }

    private function guard(Email $email): ?bool
    {
        return app(StagingMailGuard::class)->handle(new MessageSending($email, []));
    }
}
