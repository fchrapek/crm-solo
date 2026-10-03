<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class LocalOwnerSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeded_owner_gets_a_random_password_printed_once(): void
    {
        config(['app.seed_owner_password' => null]);

        Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
        $output = Artisan::output();

        $owner = User::query()->where('email', 'webmaster@crm-solo.test')->sole();
        $this->assertTrue((bool) $owner->owner);
        $this->assertNotNull($owner->account_id);
        $this->assertFalse(Hash::check('test1234', $owner->password), 'The published password must not open a fresh install.');

        $this->assertSame(1, preg_match('/Password: (\S+)/', $output, $match), 'The generated password is printed.');
        $this->assertTrue(Hash::check($match[1], $owner->password));
        $this->assertGreaterThanOrEqual(20, mb_strlen($match[1]));
    }

    public function test_the_seeded_owner_takes_the_password_from_the_environment_when_set(): void
    {
        config(['app.seed_owner_password' => 'chosen-by-the-developer']);

        Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        $owner = User::query()->where('email', 'webmaster@crm-solo.test')->sole();
        $this->assertTrue(Hash::check('chosen-by-the-developer', $owner->password));
        $this->assertStringNotContainsString('chosen-by-the-developer', Artisan::output());
    }

    public function test_reseeding_keeps_an_existing_owner_password(): void
    {
        config(['app.seed_owner_password' => 'first']);
        Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        config(['app.seed_owner_password' => 'second']);
        Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        $owner = User::query()->where('email', 'webmaster@crm-solo.test')->sole();
        $this->assertTrue(Hash::check('first', $owner->password));
    }

    public function test_demo_mode_seeds_no_local_owner(): void
    {
        config(['app.demo' => true]);

        Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        $this->assertSame(0, User::query()->count());
    }
}
