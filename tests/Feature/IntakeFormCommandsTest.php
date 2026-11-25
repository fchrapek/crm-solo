<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\IntakeForm;
use App\Support\SecretInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A form is created on the server for a pipeline and source the account
 * knows, from bare origins only, and its Turnstile secret comes on stdin.
 */
final class IntakeFormCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'pipe-only-s3cret';

    private ?string $piped = self::SECRET;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.demo' => false]);
        $this->account = Account::factory()->create();
        $this->app->bind(SecretInput::class, fn (): SecretInput => new class($this->piped) implements SecretInput
        {
            public function __construct(private ?string $line) {}

            public function line(): ?string
            {
                return $this->line;
            }
        });
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badOptions(): array
    {
        return [
            'an unknown pipeline' => [['--pipeline' => 'nope'], 'Unknown pipeline'],
            'an unknown source' => [['--source' => 'nope'], 'Unknown source'],
            'an origin with a path' => [['--origin' => ['https://www.example.test/kontakt']], 'bare origin'],
            'plain http outside localhost' => [['--origin' => ['http://www.example.test']], 'bare origin'],
            'a trailing slash' => [['--origin' => ['https://www.example.test/']], 'bare origin'],
            'an uppercase host a browser never sends' => [['--origin' => ['https://WWW.example.test']], 'bare origin'],
            'no origin' => [['--origin' => []], 'at least one allowed site'],
            'a bad notify address' => [['--notify-to' => 'nobody'], 'notify-to address'],
            'no name' => [['--name' => ''], 'name'],
            'an unknown account' => [['--account' => '999'], '--account'],
        ];
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function badSetOptions(): array
    {
        return [
            'nothing to change' => [[]],
            'an active value that is not yes or no' => [['--active' => 'maybe']],
            'a bad origin' => [['--origin' => ['http://www.example.test']]],
            'a bad notify address' => [['--notify-to' => 'nobody']],
            'an empty name' => [['--name' => ' ']],
            'a bad origin beside a good switch' => [['--active' => 'no', '--origin' => ['https://www.example.test/path']]],
        ];
    }

    public function test_a_form_is_created_with_an_unguessable_key_for_the_named_account(): void
    {
        $this->create()->assertSuccessful()->expectsOutputToContain('/intake/');

        $form = IntakeForm::query()->sole();
        $this->assertSame(
            [$this->account->id, 'Contact drawer', 'filipchrapek', 'www-form', ['https://www.example.test', 'http://localhost:4321'], 'hi@example.test', true, null],
            [$form->account_id, $form->name, $form->pipeline, $form->source, $form->allowed_origins, $form->notify_to, $form->is_active, $form->turnstile_secret],
        );
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9]{32}\z/', $form->public_key);
    }

    /** @param  array<string, mixed>  $options */
    #[DataProvider('badOptions')]
    public function test_a_bad_option_creates_nothing(array $options, string $message): void
    {
        $this->create($options)->assertFailed()->expectsOutputToContain($message);

        $this->assertSame(0, IntakeForm::query()->count());
    }

    public function test_a_custom_source_of_the_account_is_accepted(): void
    {
        DB::table('settings')->insert(['account_id' => $this->account->id, 'scope' => 'leadgen', 'data' => json_encode(['custom_sources' => ['philgood-drawer']]), 'created_at' => now(), 'updated_at' => now()]);

        $this->create(['--source' => 'philgood-drawer'])->assertSuccessful();
    }

    public function test_the_turnstile_secret_comes_from_stdin_and_is_stored_encrypted_and_hidden(): void
    {
        $form = $this->form();

        $this->assertSame(0, Artisan::call('intake-form:turnstile', ['--account' => $this->account->id, '--form' => $form->id]));
        $this->assertStringNotContainsString(self::SECRET, Artisan::output());

        $form->refresh();
        $this->assertSame(self::SECRET, $form->turnstile_secret);
        $this->assertStringNotContainsString(self::SECRET, (string) DB::table('intake_forms')->value('turnstile_secret'));
        $this->assertStringNotContainsString(self::SECRET, $form->toJson());
        $this->assertArrayNotHasKey('webhook_secret_hash', $form->toArray());
    }

    public function test_the_turnstile_secret_is_never_an_argument_or_a_valued_option(): void
    {
        $definition = Artisan::all()['intake-form:turnstile']->getDefinition();

        $this->assertSame([], array_keys($definition->getArguments()));
        $this->assertFalse($definition->getOption('clear')->acceptValue());
        $this->assertSame(['account', 'form', 'clear'], array_values(array_filter(array_keys($definition->getOptions()), fn (string $name): bool => in_array($name, ['account', 'form', 'clear', 'secret', 'turnstile-secret'], true))));
    }

    public function test_nothing_piped_changes_nothing_and_clear_removes_the_secret(): void
    {
        $form = $this->form();
        $form->forceFill(['turnstile_secret' => 'old'])->save();
        $this->piped = null;

        $this->artisan('intake-form:turnstile', ['--account' => $this->account->id, '--form' => $form->id])->assertFailed();
        $this->assertSame('old', $form->refresh()->turnstile_secret);

        $this->artisan('intake-form:turnstile', ['--account' => $this->account->id, '--form' => $form->id, '--clear' => true])->assertSuccessful();
        $this->assertNull($form->refresh()->turnstile_secret);
    }

    public function test_another_accounts_form_is_not_found(): void
    {
        $form = $this->form();
        $other = Account::factory()->create();

        $this->artisan('intake-form:turnstile', ['--account' => $other->id, '--form' => $form->id])->assertFailed();

        $this->assertNull($form->refresh()->turnstile_secret);
    }

    public function test_both_commands_refuse_in_the_demo(): void
    {
        $form = $this->form();
        config(['app.demo' => true]);

        $this->create()->assertFailed()->expectsOutputToContain('The demo has no intake forms.');
        $this->artisan('intake-form:turnstile', ['--account' => $this->account->id, '--form' => $form->id])->assertFailed()->expectsOutputToContain('The demo has no intake forms.');

        $this->assertSame(1, IntakeForm::query()->count());
        $this->assertNull($form->refresh()->turnstile_secret);
    }

    public function test_set_changes_only_the_options_given(): void
    {
        $form = $this->form();
        $form->update(['notify_to' => 'old@example.test']);

        $this->artisan('intake-form:set', ['--account' => $this->account->id, '--form' => $form->id, '--active' => 'no', '--origin' => ['https://new.example.test']])->assertSuccessful();

        $form->refresh();
        $this->assertSame([false, ['https://new.example.test'], 'old@example.test', 'Contact drawer'], [$form->is_active, $form->allowed_origins, $form->notify_to, $form->name]);

        $this->artisan('intake-form:set', ['--account' => $this->account->id, '--form' => $form->id, '--active' => 'yes', '--notify-to' => 'new@example.test', '--name' => 'Drawer'])->assertSuccessful();
        $form->refresh();
        $this->assertSame([true, 'new@example.test', 'Drawer'], [$form->is_active, $form->notify_to, $form->name]);
    }

    /** @param  array<string, mixed>  $options */
    #[DataProvider('badSetOptions')]
    public function test_set_refuses_a_bad_option_and_changes_nothing(array $options): void
    {
        $form = $this->form();

        $this->artisan('intake-form:set', ['--account' => $this->account->id, '--form' => $form->id, ...$options])->assertFailed();

        $form->refresh();
        $this->assertSame([true, ['https://www.example.test'], null, 'Contact drawer'], [$form->is_active, $form->allowed_origins, $form->notify_to, $form->name]);
    }

    public function test_set_refuses_another_accounts_form_and_the_demo(): void
    {
        $form = $this->form();
        $this->artisan('intake-form:set', ['--account' => Account::factory()->create()->id, '--form' => $form->id, '--active' => 'no'])->assertFailed();

        config(['app.demo' => true]);
        $this->artisan('intake-form:set', ['--account' => $this->account->id, '--form' => $form->id, '--active' => 'no'])->assertFailed();

        $this->assertTrue($form->refresh()->is_active);
    }

    /** @param  array<string, mixed>  $options */
    private function create(array $options = []): PendingCommand
    {
        return $this->artisan('intake-form:create', [
            '--account' => (string) $this->account->id,
            '--name' => 'Contact drawer',
            '--pipeline' => 'filipchrapek',
            '--source' => 'www-form',
            '--origin' => ['https://www.example.test', 'http://localhost:4321'],
            '--notify-to' => 'hi@example.test',
            ...$options,
        ]);
    }

    private function form(): IntakeForm
    {
        return IntakeForm::create([
            'account_id' => $this->account->id,
            'name' => 'Contact drawer',
            'public_key' => IntakeForm::newPublicKey(),
            'allowed_origins' => ['https://www.example.test'],
            'pipeline' => 'filipchrapek',
            'source' => 'www-form',
            'is_active' => true,
        ]);
    }
}
