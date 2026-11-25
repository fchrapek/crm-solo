<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Services\Clients\MonthCloseModule;
use App\Support\JsonSettings;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Settings are written key by key, so two writers of different keys both survive, whichever read the row first. */
final class JsonSettingsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{array<string, mixed>}> */
    public static function refused(): array
    {
        return [
            'a dotted key' => [['messages.ascii' => true]],
            'a quoted key' => [['a"b' => true]],
            'an upper case key' => [['Month' => true]],
            'a null value' => [['month_close' => null]],
            'an array value' => [['messages' => ['ascii' => true]]],
            'a float value' => [['rate' => 1.5]],
        ];
    }

    public function test_turning_the_month_close_on_keeps_a_key_another_writer_set_after_its_read(): void
    {
        $account = Account::create(['name' => 'Acme', 'settings' => ['settlement_from' => '2026-01']]);
        $client = $account->clients()->create(['name' => 'Klient', 'currency' => 'PLN']);
        $this->afterFirstAccountRead($account);

        app(MonthCloseModule::class)->enrol($client, fn () => $client->update(['month_close_type' => 'maintenance', 'include_in_month_close' => true]));

        $this->assertSame(['settlement_from' => '2026-01', 'report_baseline' => true, 'month_close' => true], $account->fresh()->settings);
    }

    public function test_the_modules_switch_keeps_a_key_another_writer_set_after_its_read(): void
    {
        $account = Account::create(['name' => 'Acme', 'settings' => ['settlement_from' => '2026-01']]);
        $owner = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $this->actingAs($owner);
        $this->afterFirstAccountRead($account);

        $this->patch('/settings/modules', ['month_close' => true])->assertSessionHasNoErrors();

        $this->assertSame(['settlement_from' => '2026-01', 'report_baseline' => true, 'month_close' => true], $account->fresh()->settings);
    }

    public function test_the_backfill_migration_keeps_a_key_written_during_the_deploy(): void
    {
        $account = Account::create(['name' => 'Acme', 'settings' => ['settlement_from' => '2026-01']]);
        $account->clients()->create(['name' => 'Klient', 'currency' => 'PLN', 'month_close_type' => 'maintenance']);
        $migration = require database_path('migrations/2026_11_21_210000_turn_on_month_close_where_a_client_uses_it.php');
        $this->afterFirstAccountRead($account);

        $migration->up();

        $this->assertSame(['settlement_from' => '2026-01', 'report_baseline' => true, 'month_close' => true], $account->fresh()->settings);
        $migration->down();
        $this->assertSame(['settlement_from' => '2026-01', 'report_baseline' => true], $account->fresh()->settings);
    }

    public function test_a_null_settings_column_takes_its_first_key(): void
    {
        $account = Account::create(['name' => 'Puste']);
        $this->assertNull($account->fresh()->settings);

        JsonSettings::write('accounts', $account->id, ['report_baseline' => true]);

        $this->assertSame(['report_baseline' => true], $account->fresh()->settings);
    }

    public function test_two_preferences_of_one_user_from_stale_models_both_survive(): void
    {
        $user = User::factory()->create(['account_id' => Account::create(['name' => 'Acme'])->id]);
        $first = $user->fresh();
        $second = $user->fresh();

        $first->setPreference('helper_phone', true);
        $second->setPreference('warn_full_day', false);

        $this->assertTrue($user->fresh()->prefers('helper_phone'));
        $this->assertFalse($user->fresh()->prefers('warn_full_day'));
        $this->assertFalse($second->prefers('warn_full_day'));
    }

    public function test_scalars_keep_their_type_and_forget_removes_one_key_only(): void
    {
        $account = Account::create(['name' => 'Acme', 'settings' => ['messages' => ['ascii' => true]]]);

        JsonSettings::write('accounts', $account->id, ['flag' => false, 'count' => 3, 'label' => 'Zakres "bazowy"']);
        JsonSettings::forget('accounts', $account->id, 'count');

        $this->assertSame(['messages' => ['ascii' => true], 'flag' => false, 'label' => 'Zakres "bazowy"'], $account->fresh()->settings);
    }

    /** @param  array<string, mixed>  $values */
    #[DataProvider('refused')]
    public function test_only_plain_keys_and_scalars_are_written(array $values): void
    {
        $account = Account::create(['name' => 'Acme', 'settings' => ['month_close' => true]]);

        try {
            JsonSettings::write('accounts', $account->id, $values);
            $this->fail('The write went through.');
        } catch (InvalidArgumentException) {
            $this->assertSame(['month_close' => true], $account->fresh()->settings);
        }
    }

    public function test_forget_refuses_a_dotted_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JsonSettings::forget('accounts', 1, 'messages.ascii');
    }

    public function test_settings_are_written_for_accounts_and_users_only(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JsonSettings::write('clients', 1, ['month_close' => true]);
    }

    public function test_nothing_to_write_touches_nothing(): void
    {
        $account = Account::create(['name' => 'Acme', 'settings' => ['month_close' => true]]);

        JsonSettings::write('accounts', $account->id, []);

        $this->assertSame(['month_close' => true], $account->fresh()->settings);
    }

    /** Another writer sets report_baseline right after the code under test first reads the account row. */
    private function afterFirstAccountRead(Account $account): void
    {
        $done = false;
        DB::listen(function (QueryExecuted $query) use (&$done, $account): void {
            if ($done || ! str_starts_with(mb_strtolower($query->sql), 'select') || ! str_contains($query->sql, '"accounts"')) {
                return;
            }
            $done = true;
            $settings = (array) json_decode((string) DB::table('accounts')->where('id', $account->id)->value('settings'), true);
            DB::table('accounts')->where('id', $account->id)->update(['settings' => json_encode([...$settings, 'report_baseline' => true])]);
        });
    }
}
