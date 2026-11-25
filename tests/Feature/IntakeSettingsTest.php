<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\IntakeForm;
use App\Models\Integration;
use App\Models\LeadIntake;
use App\Models\User;
use App\Services\Leads\IntakeForms;
use App\Services\Messaging\Sender\CheckedHost;
use App\Services\Messaging\Sender\HostResolver;
use App\Services\Messaging\Sender\SenderConfig;
use App\Services\Messaging\Sender\SenderTests;
use App\Services\Messaging\Sender\SenderTransportFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Tests\TestCase;

/**
 * Settings, Forms: the owner's forms and sender over the session, through the services the commands use;
 * a secret is only set, replaced or cleared, and no answer, page prop or flash carries a stored one back.
 */
final class IntakeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const string TURNSTILE = 'ts-s3cret-never-shown';

    private const string PASSWORD = 'smtp-s3cret-never-shown';

    private User $owner;

    private User $member;

    private IntakeForm $form;

    private ArrayTransport $transport;

    /** @var array<string, list<string>> */
    private array $dns = ['smtp.example.test' => ['104.16.0.1']];

    protected function setUp(): void
    {
        parent::setUp();

        // Production, so the SMTP host guard applies as on the server; the CSRF check is then live and has its own test.
        $this->app['env'] = 'production';
        config(['app.demo' => false, 'mail.to.address' => null]);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $account = Account::factory()->create();
        $this->owner = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $this->member = User::factory()->create(['account_id' => $account->id, 'owner' => false]);
        $this->form = IntakeForm::create([
            'account_id' => $account->id,
            'name' => 'Kontakt ze strony',
            'public_key' => IntakeForm::newPublicKey(),
            'allowed_origins' => ['https://www.example.test'],
            'pipeline' => 'kiwwwi',
            'source' => 'www-form',
            'notify_to' => 'biuro@example.test',
            'is_active' => true,
        ]);
        $this->form->forceFill(['turnstile_secret' => self::TURNSTILE, 'webhook_secret_hash' => hash('sha256', 'crmhook_old')])->save();
        Integration::create([
            'account_id' => $account->id,
            'provider' => Integration::SMTP,
            'api_key' => self::PASSWORD,
            'is_enabled' => true,
            'settings' => ['host' => 'smtp.example.test', 'port' => 587, 'username' => 'biuro@example.test', 'from_address' => 'biuro@example.test', 'from_name' => 'Biuro'],
        ]);

        $this->app->instance(HostResolver::class, new class($this->dns) implements HostResolver
        {
            /** @param  array<string, list<string>>  $dns */
            public function __construct(private array &$dns) {}

            public function addresses(string $host): array
            {
                return $this->dns[$host] ?? [];
            }
        });
        $this->transport = new ArrayTransport;
        $this->app->bind(SenderTransportFactory::class, fn (): SenderTransportFactory => new class($this->transport) implements SenderTransportFactory
        {
            public function __construct(private TransportInterface $transport) {}

            public function make(SenderConfig $sender, CheckedHost $host): TransportInterface
            {
                return $this->transport;
            }
        });
    }

    /** @return array<string, array{string, string|null}> */
    public static function origins(): array
    {
        return [
            'a space in the host' => ['https://exa mple.test', null],
            'a bracket that is no address' => ['https://[garbage]', null],
            'a colon without a port' => ['https://example.test:', null],
            'port zero' => ['https://example.test:0', null],
            'a port past 65535' => ['https://example.test:70000', null],
            'plain http outside localhost' => ['http://www.example.test', null],
            'a dotted number that is no address' => ['https://256.1.1.1', null],
            'a path' => ['https://www.example.test/kontakt', null],
            'an international name in its ASCII form' => ['https://żółw.example.test', 'https://xn--w-uga1v8h.example.test'],
            "the scheme's default port dropped" => ['https://www.example.test:443', 'https://www.example.test'],
            'a local preview' => ['http://localhost:4321', 'http://localhost:4321'],
            'an IPv6 literal' => ['https://[2001:db8::1]:8443', 'https://[2001:db8::1]:8443'],
            'an IPv6 literal in its long form, kept as a browser writes it' => ['https://[2001:0db8:0:0:0:0:0:1]', 'https://[2001:db8::1]'],
            'a local IPv6 preview' => ['http://[0:0:0:0:0:0:0:1]:4321', 'http://[::1]:4321'],
            'an IPv4 literal' => ['https://192.0.2.10:8443', 'https://192.0.2.10:8443'],
            'an IPv4 literal with leading zeros' => ['https://192.000.002.010', null],
            'an all-numeric top label' => ['https://example.123', null],
            'a trailing dot' => ['https://www.example.test.', null],
            'an empty label' => ['https://www..example.test', null],
            'a label over 63 characters' => ['https://'.str_repeat('a', 64).'.example.test', null],
            'a name over 253 characters' => ['https://'.implode('.', array_fill(0, 5, str_repeat('a', 60))).'.test', null],
            'a label starting with a hyphen' => ['https://-www.example.test', null],
            'an IPv4-mapped IPv6 literal' => ['https://[::ffff:192.0.2.1]', null],
            'an IPv4-mapped IPv6 literal in hex' => ['https://[::ffff:c000:201]', null],
            'an IPv4-compatible IPv6 literal' => ['https://[::192.0.2.1]', null],
            'a top label in hex' => ['https://example.0xff', null],
            'a top label of a bare 0x' => ['https://example.0x', null],
            'a top label of octal-looking digits' => ['https://example.0777', null],
            'a top label with digits and letters' => ['https://example.a1', 'https://example.a1'],
        ];
    }

    public function test_the_owner_sees_the_forms_with_their_counts_and_reasons_and_the_sender_without_a_secret(): void
    {
        $this->intake('failed', 'auth');
        $this->intake('failed', 'no_recipient');
        $this->intake('sent');

        $response = $this->actingAs($this->owner)->get('/settings/forms')->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'forms')
            ->where('intake.forms.0.name', 'Kontakt ze strony')
            ->where('intake.forms.0.turnstile_secret_set', true)
            ->where('intake.forms.0.webhook_secret_set', true)
            ->where('intake.forms.0.notices.failed', 2)
            ->where('intake.forms.0.notices.sent', 1)
            ->where("intake.failures.{$this->form->id}", ['auth' => 1, 'no_recipient' => 1])
            ->where('intake.sender.password_set', true)
            ->where('intake.sender.host', 'smtp.example.test'));
        $this->assertNoSecret($response);

        $this->assertNoSecret($this->actingAs($this->owner)->get("/settings/forms/{$this->form->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('intake.form_id', $this->form->id)));
        $this->assertNoSecret($this->actingAs($this->owner)->get('/settings')->assertOk()->assertInertia(fn (Assert $page) => $page->where('summary.forms', ['count' => 1, 'sender' => true])));
    }

    public function test_a_member_is_refused_the_tab_the_form_page_and_every_write(): void
    {
        $id = $this->form->id;
        $this->actingAs($this->member)->get('/settings/forms')->assertNotFound();
        $this->actingAs($this->member)->get("/settings/forms/{$id}")->assertForbidden();
        foreach ($this->writes() as [$method, $url, $body]) {
            $this->actingAs($this->member)->json($method, $url, $body)->assertForbidden();
        }
        $this->actingAs($this->member)->get('/settings')->assertNotFound();

        $this->assertSame(self::TURNSTILE, $this->form->refresh()->turnstile_secret);
    }

    public function test_a_write_without_the_session_token_is_refused(): void
    {
        $this->withMiddleware(ValidateCsrfToken::class);

        $this->actingAs($this->owner)->put("/settings/forms/{$this->form->id}/turnstile", ['turnstile_secret' => 'bez-tokenu'])->assertRedirect();

        $this->assertSame(self::TURNSTILE, $this->form->refresh()->turnstile_secret);
    }

    public function test_the_demo_has_no_forms_tab_and_no_writes(): void
    {
        config(['app.demo' => true]);

        $this->actingAs($this->owner)->get('/settings/forms')->assertNotFound();
        foreach ($this->writes() as [$method, $url, $body]) {
            $this->actingAs($this->owner)->json($method, $url, $body)->assertNotFound();
        }
    }

    public function test_no_answer_of_any_write_carries_a_stored_secret(): void
    {
        foreach ($this->writes() as [$method, $url, $body]) {
            if (str_ends_with($url, '/webhook') && $method === 'POST') {
                continue;
            }
            $this->assertNoSecret($this->actingAs($this->owner)->from("/settings/forms/{$this->form->id}")->json($method, $url, $body));
            $this->form->refresh()->forceFill(['turnstile_secret' => self::TURNSTILE])->save();
        }
        $this->assertNoSecret($this->actingAs($this->owner)->get("/settings/forms/{$this->form->id}"));
    }

    public function test_a_form_is_created_from_the_settings_and_a_bad_origin_is_refused_on_its_field(): void
    {
        $this->actingAs($this->owner)->post('/settings/forms', [
            'name' => 'Zapytanie o wycenę',
            'pipeline' => 'kiwwwi',
            'source' => 'www-form',
            'origins' => ['https://sklep.example.test'],
            'notify_to' => 'sklep@example.test',
        ])->assertRedirect();
        $created = IntakeForm::query()->where('name', 'Zapytanie o wycenę')->sole();
        $this->assertSame(['https://sklep.example.test'], $created->allowed_origins);
        $this->assertSame(32, mb_strlen($created->public_key));

        $this->actingAs($this->owner)->from('/settings/forms')->post('/settings/forms', [
            'name' => 'Zły',
            'pipeline' => 'kiwwwi',
            'source' => 'www-form',
            'origins' => ['https://sklep.example.test/path'],
        ])->assertSessionHasErrors(['origins']);
        $this->assertFalse(IntakeForm::query()->where('name', 'Zły')->exists());
    }

    public function test_a_blank_site_is_refused_by_the_fields_name_not_its_key(): void
    {
        foreach (['pl' => 'Dozwolona strona', 'en' => 'Allowed site'] as $locale => $label) {
            $this->actingAs($this->owner)->withUnencryptedCookie('locale', $locale)->withHeader('Accept-Language', $locale)->from('/settings/forms')->post('/settings/forms', [
                'name' => 'Pusty',
                'pipeline' => 'kiwwwi',
                'source' => 'www-form',
                'origins' => [''],
            ]);
            $message = session('errors')->first('origins.0');

            $this->assertStringContainsStringIgnoringCase($label, $message);
            $this->assertStringNotContainsString('origins', $message);
        }
    }

    public function test_an_edit_changes_the_settings_given_and_another_accounts_form_is_not_touched(): void
    {
        $this->actingAs($this->owner)->put("/settings/forms/{$this->form->id}", [
            'name' => 'Kontakt',
            'origins' => ['https://www.example.test', 'http://localhost:4321'],
            'active' => false,
            'notify_to' => 'nowy@example.test',
        ])->assertSessionHasNoErrors();
        $this->form->refresh();
        $this->assertSame('Kontakt', $this->form->name);
        $this->assertFalse($this->form->is_active);
        $this->assertSame(['https://www.example.test', 'http://localhost:4321'], $this->form->allowed_origins);

        $other = User::factory()->create(['account_id' => Account::factory()->create()->id, 'owner' => true]);
        $this->actingAs($other)->put("/settings/forms/{$this->form->id}", ['name' => 'Cudzy'])->assertSessionHasErrors();
        $this->assertSame('Kontakt', $this->form->refresh()->name);
    }

    public function test_the_turnstile_secret_is_set_replaced_and_cleared_and_stored_encrypted(): void
    {
        $this->actingAs($this->owner)->put("/settings/forms/{$this->form->id}/turnstile", ['turnstile_secret' => 'nowy-sekret'])->assertSessionHasNoErrors();
        $this->assertSame('nowy-sekret', $this->form->refresh()->turnstile_secret);
        $this->assertStringNotContainsString('nowy-sekret', (string) DB::table('intake_forms')->value('turnstile_secret'));

        $this->actingAs($this->owner)->put("/settings/forms/{$this->form->id}/turnstile", ['turnstile_secret' => "dwie\nlinie"])->assertSessionHasErrors(['turnstile_secret']);
        $this->assertSame('nowy-sekret', $this->form->refresh()->turnstile_secret);

        $this->actingAs($this->owner)->delete("/settings/forms/{$this->form->id}/turnstile")->assertSessionHasNoErrors();
        $this->assertNull($this->form->refresh()->turnstile_secret);
    }

    public function test_a_new_webhook_secret_is_shown_once_never_cached_and_only_its_hash_is_kept(): void
    {
        $response = $this->actingAs($this->owner)->postJson("/settings/forms/{$this->form->id}/webhook")->assertCreated();
        $secret = $response->json('secret');

        $this->assertStringStartsWith('crmhook_', $secret);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertTrue($this->form->refresh()->webhookSecretMatches($secret));
        $this->assertFalse($this->form->webhookSecretMatches('crmhook_old'));
        $page = $this->actingAs($this->owner)->get("/settings/forms/{$this->form->id}");
        $this->assertStringNotContainsString($secret, (string) $page->getContent());
        $this->assertNoSecret($page);

        $this->actingAs($this->owner)->delete("/settings/forms/{$this->form->id}/webhook");
        $this->assertNull($this->form->refresh()->webhook_secret_hash);
    }

    public function test_a_refused_secret_never_lands_in_the_sessions_old_input_for_a_page_or_an_inertia_request(): void
    {
        $other = User::factory()->create(['account_id' => Account::factory()->create()->id, 'owner' => true]);
        $inertia = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'];

        foreach ([[], $inertia] as $headers) {
            $this->actingAs($this->owner)->withHeaders($headers)->from("/settings/forms/{$this->form->id}")
                ->put("/settings/forms/{$this->form->id}/turnstile", ['turnstile_secret' => "zly\nsekret-123"])
                ->assertSessionHasErrors(['turnstile_secret']);
            $this->assertStringNotContainsString('sekret-123', (string) json_encode(session()->all()));

            $this->actingAs($other)->withHeaders($headers)->from('/settings/forms')
                ->put("/settings/forms/{$this->form->id}/turnstile", ['turnstile_secret' => 'cudzy-sekret-456'])
                ->assertSessionHasErrors();
            $this->assertStringNotContainsString('cudzy-sekret-456', (string) json_encode(session()->all()));

            $this->actingAs($this->owner)->withHeaders($headers)->from('/settings/forms')
                ->put('/settings/sender', ['host' => 'smtp.example.test', 'port' => 25, 'username' => 'u', 'from_address' => 'biuro@example.test', 'password' => 'haslo-789'])
                ->assertSessionHasErrors(['sender']);
            $this->assertStringNotContainsString('haslo-789', (string) json_encode(session()->all()));
            $this->flushHeaders();
        }

        $this->assertSame(self::TURNSTILE, $this->form->refresh()->turnstile_secret);
    }

    public function test_a_failed_webhook_secret_write_carries_neither_the_secret_nor_its_hash_into_the_error_or_the_log(): void
    {
        $seen = [];
        IntakeForm::saving(function (IntakeForm $form) use (&$seen): void {
            $seen[] = (string) $form->webhook_secret_hash;
            throw new QueryException('mariadb', 'update intake_forms set webhook_secret_hash = ?', [$form->webhook_secret_hash], new PDOException('Lost connection'));
        });
        $logged = [];
        Log::listen(function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message->message.json_encode($message->context);
        });

        $response = $this->actingAs($this->owner)->postJson("/settings/forms/{$this->form->id}/webhook");

        $response->assertServerError();
        $this->assertNotSame([], $seen);
        foreach ([...$seen, 'crmhook_'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $response->getContent());
            $this->assertStringNotContainsString($secret, implode("\n", $logged));
        }
        $this->assertStringContainsString('QueryException', implode("\n", $logged));
    }

    public function test_clearing_the_allowed_sites_or_the_recipient_is_refused_and_keeps_both(): void
    {
        $this->actingAs($this->owner)->from("/settings/forms/{$this->form->id}")->put("/settings/forms/{$this->form->id}", ['origins' => []])->assertSessionHasErrors(['origins']);
        $this->actingAs($this->owner)->from("/settings/forms/{$this->form->id}")->put("/settings/forms/{$this->form->id}", ['notify_to' => ''])->assertSessionHasErrors(['notify_to']);

        $this->form->refresh();
        $this->assertSame(['https://www.example.test'], $this->form->allowed_origins);
        $this->assertSame('biuro@example.test', $this->form->notify_to);
    }

    #[DataProvider('origins')]
    public function test_an_origin_is_kept_only_in_the_form_a_browser_sends(string $origin, ?string $canonical): void
    {
        $this->assertSame($canonical, IntakeForms::canonicalOrigin($origin));
    }

    public function test_an_international_origin_is_stored_in_its_ascii_form(): void
    {
        $this->actingAs($this->owner)->put("/settings/forms/{$this->form->id}", ['origins' => ['https://żółw.example.test']])->assertSessionHasNoErrors();

        $this->assertSame(['https://xn--w-uga1v8h.example.test'], $this->form->refresh()->allowed_origins);
    }

    public function test_retry_moves_only_this_forms_failed_notices(): void
    {
        $mine = $this->intake('failed', 'auth');
        $otherForm = IntakeForm::create([...$this->form->only(['account_id', 'allowed_origins', 'pipeline', 'source']), 'name' => 'Drugi', 'public_key' => IntakeForm::newPublicKey()]);
        $theirs = LeadIntake::create(['account_id' => $this->form->account_id, 'intake_form_id' => $otherForm->id, 'idempotency_key' => 'k-other', 'fingerprint' => str_repeat('b', 64), 'notice_state' => 'failed', 'failure_reason' => 'auth']);

        $this->actingAs($this->owner)->post("/settings/forms/{$this->form->id}/retry")->assertSessionHas('success');

        $this->assertSame('waiting', $mine->refresh()->notice_state);
        $this->assertSame('failed', $theirs->refresh()->notice_state);
    }

    public function test_the_sender_is_saved_with_the_password_write_only_and_an_empty_one_keeps_the_stored(): void
    {
        $fields = ['host' => 'smtp.example.test', 'port' => 465, 'username' => 'biuro@example.test', 'from_address' => 'biuro@example.test', 'from_name' => 'Biuro Przykładowe'];

        $this->actingAs($this->owner)->put('/settings/sender', [...$fields, 'password' => ''])->assertSessionHasNoErrors();
        $row = Integration::query()->where('provider', Integration::SMTP)->sole();
        $this->assertSame(self::PASSWORD, $row->api_key);
        $this->assertSame(465, $row->settings['port']);

        $this->actingAs($this->owner)->put('/settings/sender', [...$fields, 'password' => 'nowe-haslo'])->assertSessionHasNoErrors();
        $this->assertSame('nowe-haslo', $row->refresh()->api_key);

        $this->dns['smtp.example.test'] = ['10.0.0.5'];
        $this->actingAs($this->owner)->put('/settings/sender', [...$fields, 'from_name' => 'Wewnętrzny'])->assertSessionHasErrors(['sender']);
        $this->actingAs($this->owner)->put('/settings/sender', [...$fields, 'port' => 25])->assertSessionHasErrors(['sender']);
        $this->assertSame('Biuro Przykładowe', $row->refresh()->settings['from_name']);
    }

    public function test_a_test_send_goes_out_through_the_sender_and_is_throttled_as_the_command(): void
    {
        for ($i = 0; $i < SenderTests::PER_TEN_MINUTES; $i++) {
            $this->actingAs($this->owner)->post('/settings/sender/test')->assertSessionHas('success');
        }
        $this->actingAs($this->owner)->post('/settings/sender/test')->assertSessionHas('error');

        $this->assertCount(SenderTests::PER_TEN_MINUTES, $this->transport->messages());
        $this->assertSame(SenderTests::PER_TEN_MINUTES, RateLimiter::attempts(SenderTests::throttleKey($this->owner->account_id)));
    }

    /** @return list<array{string, string, array<string, mixed>}> */
    private function writes(): array
    {
        $id = $this->form->id;

        return [
            ['POST', '/settings/forms', ['name' => 'X', 'pipeline' => 'kiwwwi', 'source' => 'www-form', 'origins' => ['https://x.example.test']]],
            ['PUT', "/settings/forms/{$id}", ['name' => 'Y']],
            ['PUT', "/settings/forms/{$id}/turnstile", ['turnstile_secret' => 'zamiana']],
            ['DELETE', "/settings/forms/{$id}/turnstile", []],
            ['POST', "/settings/forms/{$id}/webhook", []],
            ['DELETE', "/settings/forms/{$id}/webhook", []],
            ['POST', "/settings/forms/{$id}/retry", []],
            ['PUT', '/settings/sender', ['host' => 'smtp.example.test', 'port' => 587, 'username' => 'u', 'from_address' => 'biuro@example.test', 'password' => '']],
            ['POST', '/settings/sender/test', []],
        ];
    }

    private function intake(string $state, ?string $reason = null): LeadIntake
    {
        static $n = 0;
        $n++;

        return LeadIntake::create([
            'account_id' => $this->form->account_id,
            'intake_form_id' => $this->form->id,
            'idempotency_key' => "k-{$n}",
            'fingerprint' => str_repeat('a', 64),
            'notice_state' => $state,
            'failure_reason' => $reason,
        ]);
    }

    private function assertNoSecret(TestResponse $response): void
    {
        $body = (string) $response->getContent();
        $flash = json_encode(session()->all());
        foreach ([self::TURNSTILE, self::PASSWORD, hash('sha256', 'crmhook_old')] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
            $this->assertStringNotContainsString($secret, (string) $flash);
        }
        $this->assertStringNotContainsString('webhook_secret_hash', $body);
        $this->assertStringNotContainsString('api_key', $body);
    }
}
