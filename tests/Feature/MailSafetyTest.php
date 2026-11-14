<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Messaging\MailDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Tests\TestCase;

/**
 * No client address can reach the transport while a redirect is set, and a
 * client email counts as really sent only when live sending is switched on
 * with a delivering mailer and no redirect.
 */
final class MailSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_with_a_redirect_the_envelope_holds_only_the_redirect_address(): void
    {
        $this->redirectTo('safe@internal.test');

        Mail::send($this->clientMail());

        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $this->assertSame(['safe@internal.test'], $this->envelopeRecipients($sent[0]));
        $email = $sent[0]->getOriginalMessage();
        $this->assertSame(['safe@internal.test'], array_map(fn (Address $a): string => $a->getAddress(), $email->getTo()));
        $this->assertSame([], $email->getCc());
        $this->assertSame([], $email->getBcc());
    }

    public function test_with_a_redirect_a_mail_sent_from_a_queue_worker_reaches_only_the_redirect_address(): void
    {
        config(['queue.default' => 'database']);
        $this->redirectTo('safe@internal.test');

        Mail::queue($this->clientMail());
        $this->assertSame([], $this->sent(), 'nothing is sent before the worker runs');

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true])->assertExitCode(0);

        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $this->assertSame(['safe@internal.test'], $this->envelopeRecipients($sent[0]));
    }

    public function test_without_a_redirect_the_client_addresses_reach_the_transport(): void
    {
        Mail::send($this->clientMail());

        $this->assertEqualsCanonicalizing(['client@example.test', 'copy@example.test', 'hidden@example.test'], $this->envelopeRecipients($this->sent()[0]));
    }

    public function test_only_live_sending_on_a_delivering_mailer_without_a_redirect_is_live(): void
    {
        $delivery = new MailDelivery;
        $mode = function (string $mailer, bool $live, ?string $redirect) use ($delivery): string {
            config(['mail.default' => $mailer, 'mail.live_send' => $live, 'mail.to.address' => $redirect]);

            return $delivery->mode();
        };

        $this->assertSame(MailDelivery::TEST, $mode('log', true, null));
        $this->assertSame(MailDelivery::TEST, $mode('array', true, null));
        $this->assertSame(MailDelivery::TEST, $mode('smtp', true, 'safe@internal.test'));
        $this->assertSame(MailDelivery::TEST, $mode('smtp', false, 'safe@internal.test'));
        $this->assertSame(MailDelivery::REFUSED, $mode('smtp', false, null));
        $this->assertSame(MailDelivery::LIVE, $mode('smtp', true, null));
        $this->assertSame(MailDelivery::REFUSED, $mode('failover', true, null), 'a failover may end in the log after a real send');
        $this->assertSame(MailDelivery::REFUSED, $mode('failover', false, null), 'a test label never authorizes its real first transport');
        $this->assertSame(MailDelivery::REFUSED, $mode('roundrobin', true, null), 'a round robin retries another provider by itself');
        $this->assertSame(MailDelivery::TEST, $mode('failover', false, 'safe@internal.test'), 'the redirect still makes any mailer safe');
    }

    public function test_the_demo_never_hands_a_client_email_to_a_delivering_mailer(): void
    {
        config(['app.demo' => true]);
        $delivery = new MailDelivery;
        $mode = function (string $mailer, bool $live, ?string $redirect) use ($delivery): string {
            config(['mail.default' => $mailer, 'mail.live_send' => $live, 'mail.to.address' => $redirect]);

            return $delivery->mode();
        };

        $this->assertSame(MailDelivery::REFUSED, $mode('smtp', true, null));
        $this->assertSame(MailDelivery::REFUSED, $mode('smtp', true, 'safe@internal.test'), 'the redirect address is still a real mailbox');
        $this->assertSame(MailDelivery::TEST, $mode('log', true, null));
        $this->assertSame('The demo sends no email. Nothing was sent.', $delivery->refusal());
    }

    public function test_the_env_variables_map_to_the_redirect_and_the_live_switch(): void
    {
        $this->assertNull(config('mail.to.address'));
        $this->assertFalse(config('mail.live_send'));

        $_ENV['MAIL_ALWAYS_TO'] = $_SERVER['MAIL_ALWAYS_TO'] = 'safe@internal.test';
        $_ENV['MAIL_LIVE_SEND'] = $_SERVER['MAIL_LIVE_SEND'] = 'true';
        try {
            $config = require config_path('mail.php');
        } finally {
            $_ENV['MAIL_ALWAYS_TO'] = $_SERVER['MAIL_ALWAYS_TO'] = '';
            $_ENV['MAIL_LIVE_SEND'] = $_SERVER['MAIL_LIVE_SEND'] = 'false';
        }

        $this->assertSame('safe@internal.test', $config['to']['address']);
        $this->assertTrue($config['live_send']);
    }

    public function test_the_local_example_redirects_all_mail_by_default(): void
    {
        $this->assertMatchesRegularExpression('/^MAIL_ALWAYS_TO=\S+@/m', (string) file_get_contents(base_path('.env.example')));
    }

    private function redirectTo(string $address): void
    {
        config(['mail.to' => ['address' => $address, 'name' => null]]);
        Mail::purge();
    }

    private function clientMail(): Mailable
    {
        return (new Mailable)
            ->to('client@example.test')
            ->cc('copy@example.test')
            ->bcc('hidden@example.test')
            ->subject('Podsumowanie')
            ->html('<p>Dzień dobry</p>');
    }

    /** @return list<SentMessage> */
    private function sent(): array
    {
        return array_values(Mail::mailer()->getSymfonyTransport()->messages()->all());
    }

    /** @return list<string> */
    private function envelopeRecipients(SentMessage $message): array
    {
        return array_map(fn (Address $a): string => $a->getAddress(), $message->getEnvelope()->getRecipients());
    }
}
