<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\LegacyUrls;
use App\Models\Account;
use App\Models\IntakeForm;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Retired URLs: every row of the redirect table, and the rules the redirect keeps. */
final class LegacyUrlsTest extends TestCase
{
    use RefreshDatabase;

    private const string CACHE = 'max-age=604800, public';

    /**
     * The redirect table: old URL => new URL.
     *
     * @return array<string, array{string, string}>
     */
    public static function table(): array
    {
        return [
            'P1 tomorrow' => ['/jutro', '/tomorrow'],
            'P2 week' => ['/tydzien', '/week'],
            'P2 Q2 next week' => ['/tydzien?nastepny=1', '/week?next=1'],
            'P3 all tasks' => ['/zadania', '/all-tasks'],
            'P3 Q3 Q4 Q5 list for tomorrow from a day, hidden shown' => ['/zadania?na=jutro&z=dzien&ukryte=1', '/all-tasks?for=tomorrow&from=day&hidden=1'],
            'P3 Q3 list for a date' => ['/zadania?na=2026-10-30', '/all-tasks?for=2026-10-30'],
            'P4 a day' => ['/dzien/2026-10-30', '/day/2026-10-30'],
            'P5 waitlist' => ['/czesc', '/waitlist'],
            'Q1 today' => ['/?widok=dzis', '/?view=today'],
            'Q1 summary' => ['/?widok=gotowe', '/?view=summary'],
            'Q1 timer focus' => ['/?widok=timer&timer=9', '/?view=timer&timer=9'],
            'P6 overview' => ['/clients/7/przeglad', '/clients/7/overview'],
            'P7 tasks' => ['/clients/7/zadania', '/clients/7/tasks'],
            'P7 Q9 tasks with one part in full' => ['/clients/7/zadania?wszystkie=doing', '/clients/7/tasks?expand=doing'],
            'P8 journal' => ['/clients/7/dziennik', '/clients/7/journal'],
            'P8 Q6 Q7 Q8 relationship with both cursors' => ['/clients/7/dziennik?widok=relacja&praca=abc&wpisy=1790000000-4', '/clients/7/journal?view=relationship&work=abc&notes=1790000000-4'],
            'P9 billing' => ['/clients/7/rozliczenia', '/clients/7/billing'],
            'P9 Q11 Q12 billing month and ledger page' => ['/clients/7/rozliczenia?miesiac=2026-09&miesiace=2&invoice_year=2026', '/clients/7/billing?month=2026-09&ledger=2&invoice_year=2026'],
            'P10 details' => ['/clients/7/dane', '/clients/7/details'],
            'Q9 tasks on the new path' => ['/clients/7/tasks?wszystkie=done', '/clients/7/tasks?expand=done'],
            'Q10 project board' => ['/clients/7/projects/3?wszystkie=doing', '/clients/7/projects/3?expand=doing'],
            'Q11 billing month on the new path' => ['/clients/7/billing?miesiac=2026-10', '/clients/7/billing?month=2026-10'],
            'Q13 helper on an old tab' => ['/clients/7/przeglad?pomocnik=retainer-terms', '/clients/7/overview?helper=retainer-terms'],
            'Q13 helper on a new tab' => ['/clients/7/billing?month=2026-10&pomocnik=client-report', '/clients/7/billing?month=2026-10&helper=client-report'],
            'Q14 new client' => ['/clients?nowy=1', '/clients?new=1'],
            'Q15 new contact' => ['/contacts?kontakt=new', '/contacts?contact=new'],
            'Q15 one contact' => ['/contacts?search=ewa&kontakt=12', '/contacts?search=ewa&contact=12'],
            'Q16 leads as a list' => ['/leads?tier=gold&widok=lista', '/leads?tier=gold&view=list'],
            'Q17 month close, yours' => ['/month-close?period=2026-10&pokaz=ty', '/month-close?period=2026-10&filter=you'],
            'Q17 month close, the agent' => ['/month-close?pokaz=agent', '/month-close?filter=agent'],
            'Q17 month close, hours' => ['/month-close?pokaz=godziny', '/month-close?filter=hours'],
            'Q17 month close, finished' => ['/month-close?pokaz=zakonczone', '/month-close?filter=finished'],
            'Q18 revenue gross' => ['/revenue?period=ytd&kwoty=brutto', '/revenue?period=ytd&amounts=gross'],
            'Q18 revenue net' => ['/revenue?kwoty=netto', '/revenue?amounts=net'],
            'Q19 task history' => ['/tasks/5?historia=1', '/tasks/5?history=1'],
            'Q20 change a task, no value' => ['/tasks/5?zmien', '/tasks/5?change'],
            'Q20 change a task' => ['/tasks/5?zmien=1', '/tasks/5?change=1'],
            'P11 account' => ['/settings/konto', '/settings/general'],
            'P12 appearance' => ['/settings/wyglad', '/profile'],
            'P13 tasks' => ['/settings/zadania', '/profile'],
            'P14 leads' => ['/settings/leady', '/settings/leads'],
            'P15 client fields' => ['/settings/pola-klienta', '/settings/general'],
            'P16 integrations' => ['/settings/integracje', '/settings/integrations'],
            'P17 forms' => ['/settings/formularze', '/settings/forms'],
            'P18 agents' => ['/settings/agenci', '/settings/integrations'],
            'P19 one form' => ['/settings/formularze/12', '/settings/forms/12'],
            'S1 account tab' => ['/settings/account', '/settings/general'],
            'S1 client fields tab' => ['/settings/client-fields', '/settings/general'],
            'S2 appearance tab' => ['/settings/appearance', '/profile'],
            'S2 tasks tab' => ['/settings/tasks', '/profile'],
            'S3 text messages tab' => ['/settings/sms', '/settings/messages'],
            'S3 email messages tab' => ['/settings/email', '/settings/messages'],
            'S4 agents tab' => ['/settings/agents', '/settings/integrations'],
            '1.2 users' => ['/users', '/settings/general'],
            '1.2 new user' => ['/users/create', '/settings/general'],
            '1.2 edit user' => ['/users/1/edit', '/settings/general'],
            '1.2 integrations' => ['/integrations', '/settings/integrations'],
            '1.2 one integration' => ['/integrations/trello', '/settings/integrations'],
        ];
    }

    /**
     * Retired write paths, which answer 404 and are never redirected.
     *
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function oldWrites(): array
    {
        return [
            'join the waitlist' => ['post', '/czesc', ['email' => 'ada@example.com']],
            'create a form' => ['post', '/settings/formularze', ['name' => 'Nowy formularz', 'allowed_origins' => ['https://www.example.test']]],
            'edit a form' => ['put', '/settings/formularze/{form}', ['name' => 'Zmieniony']],
            'set the Turnstile secret' => ['put', '/settings/formularze/{form}/turnstile', ['turnstile_secret' => 'new-secret']],
            'clear the Turnstile secret' => ['delete', '/settings/formularze/{form}/turnstile', []],
            'rotate the webhook secret' => ['post', '/settings/formularze/{form}/webhook', []],
            'clear the webhook secret' => ['delete', '/settings/formularze/{form}/webhook', []],
            'retry failed notices' => ['post', '/settings/formularze/{form}/ponow', []],
            'save the sender' => ['put', '/settings/nadawca', ['host' => 'smtp.example.test', 'port' => 587]],
            'test the sender' => ['post', '/settings/nadawca/test', []],
            'task settings' => ['patch', '/settings/zadania', ['warn_full_day' => false]],
            'appearance settings' => ['patch', '/settings/wyglad', ['helper_desktop' => false]],
            'account modules' => ['patch', '/settings/moduly', ['month_close' => false]],
            'task settings under Settings' => ['patch', '/settings/tasks', ['warn_full_day' => false]],
            'appearance settings under Settings' => ['patch', '/settings/appearance', ['helper_desktop' => false]],
        ];
    }

    /**
     * Paths a browser would read as another host if they came back as the Location, as the raw request line sends them.
     *
     * @return array<string, array{string}>
     */
    public static function hostLikePaths(): array
    {
        return [
            'two slashes' => ['//evil.example?pomocnik=1'],
            'a slash and a backslash' => ['/\\evil.example?pomocnik=1'],
            'three slashes and a trailing one' => ['///evil.example/?widok=dzis'],
            'two slashes on a renamed path' => ['//evil.example/przeglad?pomocnik=1'],
            'an encoded backslash' => ['/%5Cevil.example?pomocnik=1'],
        ];
    }

    #[DataProvider('table')]
    public function test_an_old_url_answers_a_permanent_redirect_to_its_new_url(string $old, string $new): void
    {
        $this->get($old)
            ->assertStatus(301)
            ->assertHeader('Location', $new)
            ->assertHeader('Cache-Control', self::CACHE);
    }

    /** One hop: the new URL is never itself an old one. */
    #[DataProvider('table')]
    public function test_a_new_url_is_left_alone_and_reaches_a_route(string $old, string $new): void
    {
        $url = parse_url($new);

        $this->assertNull(app(LegacyUrls::class)->translate($url['path'], $url['query'] ?? ''));
        $this->assertNotNull(Route::getRoutes()->match(Request::create($new)));
    }

    public function test_every_rule_and_every_mapped_value_of_the_redirect_table_has_a_row_here(): void
    {
        $rows = array_map(fn (array $row): array => [
            'old' => (string) parse_url($row[0], PHP_URL_PATH),
            'new' => (string) parse_url($row[1], PHP_URL_PATH),
            'pairs' => explode('&', (string) parse_url($row[0], PHP_URL_QUERY)),
        ], array_values(self::table()));

        foreach (array_keys(LegacyUrls::PATHS) as $pattern) {
            $this->assertNotEmpty(array_filter($rows, fn (array $row): bool => preg_match($pattern, $row['old']) === 1), "no row for the path {$pattern}");
        }
        foreach (LegacyUrls::QUERIES as $pattern => $keys) {
            foreach ($keys as $key => [, $values]) {
                foreach ([null, ...array_keys($values)] as $value) {
                    $covered = array_filter($rows, fn (array $row): bool => preg_match($pattern, $row['new']) === 1
                        && array_filter($row['pairs'], fn (string $pair): bool => $value === null ? explode('=', $pair, 2)[0] === $key : $pair === "{$key}={$value}") !== []);
                    $this->assertNotEmpty($covered, "no row for {$key}".($value === null ? '' : "={$value}")." on {$pattern}");
                }
            }
        }
    }

    #[DataProvider('hostLikePaths')]
    public function test_a_path_that_reads_as_another_host_never_becomes_a_location(string $uri): void
    {
        [$path, $query] = explode('?', $uri, 2);
        $target = app(LegacyUrls::class)->translate($path, $query);
        $this->assertTrue($target === null || $this->singleSlash($target), "translate gave {$target}");

        $response = $this->app->make(Kernel::class)->handle(new Request(server: [
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'QUERY_STRING' => $query, 'HTTP_HOST' => 'localhost',
            'SERVER_NAME' => 'localhost', 'SERVER_PORT' => 80, 'SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => public_path('index.php'),
        ]));
        $location = (string) $response->headers->get('Location');
        $this->assertTrue(! $response->isRedirection() || $this->singleSlash($location), "{$uri} answered {$response->getStatusCode()} to {$location}");
    }

    public function test_a_path_that_reads_as_another_host_is_left_to_the_router(): void
    {
        $urls = app(LegacyUrls::class);

        $this->assertNull($urls->translate('//evil.example', 'pomocnik=1'));
        $this->assertNull($urls->translate('/\\evil.example', 'pomocnik=1'));
        $this->assertNull($urls->translate('///evil.example/', 'widok=dzis'));
        $this->assertNull($urls->translate('//evil.example/przeglad', ''));
        $this->assertSame('/%5Cevil.example?helper=1', $urls->translate('/%5Cevil.example', 'pomocnik=1'));
    }

    public function test_a_backslash_or_a_control_character_never_reaches_the_location(): void
    {
        $urls = app(LegacyUrls::class);

        $this->assertNull($urls->translate('/clients/a\\b/przeglad', ''));
        $this->assertNull($urls->translate("/clients/a\tb/przeglad", ''));
        $this->assertNull($urls->translate('/clients/7/przeglad', "pomocnik=1\r\nSet-Cookie:x=1"));
        $this->assertNull($urls->translate("/clients/7/przeglad\x7f", ''));
    }

    public function test_other_query_parameters_survive_in_their_order(): void
    {
        $this->get('/users?z=1&a=two%20words&flag')
            ->assertStatus(301)
            ->assertHeader('Location', '/settings/general?z=1&a=two%20words&flag');
    }

    public function test_a_value_outside_the_value_map_passes_unchanged(): void
    {
        $this->get('/zadania?na=someday')->assertStatus(301)->assertHeader('Location', '/all-tasks?for=someday');
    }

    public function test_an_old_key_beside_its_new_key_gives_way_to_the_new_one(): void
    {
        $this->get('/?widok=gotowe&view=today&timer=3')->assertStatus(301)->assertHeader('Location', '/?view=today&timer=3');
        $this->get('/zadania?for=2026-10-30&na=jutro')->assertStatus(301)->assertHeader('Location', '/all-tasks?for=2026-10-30');
    }

    public function test_an_old_key_is_renamed_only_on_its_own_path(): void
    {
        $this->assertNull(app(LegacyUrls::class)->translate('/clients', 'nastepny=1&na=jutro'));
    }

    public function test_a_day_that_is_not_a_date_is_left_to_the_router(): void
    {
        $this->assertNull(app(LegacyUrls::class)->translate('/dzien/today', ''));
    }

    public function test_a_trailing_slash_redirects_like_the_bare_path(): void
    {
        $this->get('/integrations/')->assertStatus(301)->assertHeader('Location', '/settings/integrations');
    }

    public function test_head_redirects_like_get(): void
    {
        $this->call('HEAD', '/users/create')->assertStatus(301)->assertHeader('Location', '/settings/general');
    }

    public function test_a_url_with_nothing_old_in_it_is_not_translated(): void
    {
        $urls = app(LegacyUrls::class);

        $this->assertNull($urls->translate('/', ''));
        $this->assertNull($urls->translate('/clients', 'q=a'));
        $this->assertNull($urls->translate('/users/1/restore', ''));
    }

    /** @param  array<string, mixed>  $payload */
    #[DataProvider('oldWrites')]
    public function test_a_write_on_a_retired_path_is_not_found_and_writes_nothing(string $method, string $path, array $payload): void
    {
        $account = Account::factory()->create();
        $owner = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $form = IntakeForm::create([
            'account_id' => $account->id, 'name' => 'Kontakt', 'public_key' => IntakeForm::newPublicKey(),
            'allowed_origins' => ['https://www.example.test'], 'pipeline' => 'kiwwwi', 'source' => 'www-form', 'is_active' => true,
        ]);
        $before = $this->rows();

        $response = $this->actingAs($owner)->json($method, str_replace('{form}', (string) $form->id, $path), $payload);

        $response->assertNotFound();
        $this->assertSame($before, $this->rows());
    }

    public function test_a_write_on_an_old_path_is_never_redirected(): void
    {
        $owner = User::factory()->create(['account_id' => Account::factory()->create()->id, 'owner' => true]);

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            foreach (['/users/create', '/users/1/edit', '/integrations'] as $path) {
                $status = $this->actingAs($owner)->{$method}($path)->getStatusCode();
                $this->assertFalse($status >= 300 && $status < 400, mb_strtoupper($method)." {$path} answered {$status}");
            }
        }
    }

    private function singleSlash(string $location): bool
    {
        return preg_match('#^/(?![/\\\\])#', $location) === 1 && ! str_contains($location, '\\');
    }

    /** @return array<string, string> every table's rows */
    private function rows(): array
    {
        return collect(Schema::getTableListing())
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->get()->toJson()])
            ->all();
    }
}
