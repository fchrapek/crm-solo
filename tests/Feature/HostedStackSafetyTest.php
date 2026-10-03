<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Tests\TestCase;

final class HostedStackSafetyTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::prohibitDestructiveCommands(false);

        parent::tearDown();
    }

    public function test_a_production_install_with_real_data_refuses_to_wipe_its_database(): void
    {
        $this->app['env'] = 'production';
        config(['app.demo' => false, 'app.demo_reset_allowed' => false]);

        $this->assertTrue(AppServiceProvider::wipesForbidden());

        $this->app->getProvider(AppServiceProvider::class)->boot();

        $this->artisan('db:wipe', ['--force' => true])->assertFailed();
        $this->artisan('migrate:fresh', ['--force' => true])->assertFailed();
    }

    public function test_the_demo_and_a_fictional_staging_stack_keep_their_reset_path(): void
    {
        $this->app['env'] = 'production';

        config(['app.demo' => true, 'app.demo_reset_allowed' => false]);
        $this->assertFalse(AppServiceProvider::wipesForbidden());

        config(['app.demo' => false, 'app.demo_reset_allowed' => true]);
        $this->assertFalse(AppServiceProvider::wipesForbidden());
    }

    public function test_allowing_a_staging_reset_does_not_schedule_the_nightly_wipe(): void
    {
        config(['app.demo' => false, 'app.demo_reset_allowed' => true]);

        $this->assertFalse($this->scheduled('demo:reset'));

        config(['app.demo' => true]);

        $this->assertTrue($this->scheduled('demo:reset'));
    }

    private function scheduled(string $command): bool
    {
        ScheduleFacade::swap($schedule = new Schedule);
        require base_path('routes/console.php');

        return collect($schedule->events())->contains(fn (Event $event): bool => str_contains((string) $event->command, $command));
    }
}
