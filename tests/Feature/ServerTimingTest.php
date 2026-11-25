<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ServerTiming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class ServerTimingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_web_page_reports_its_server_time_in_milliseconds_with_one_decimal(): void
    {
        $response = $this->get('/login')->assertOk();

        $this->assertMatchesRegularExpression('/^app;dur=\d+\.\d$/', (string) $response->headers->get('Server-Timing'));
    }

    public function test_it_is_the_first_middleware_of_the_web_group(): void
    {
        $this->assertSame(ServerTiming::class, app('router')->getMiddlewareGroups()['web'][0]);
    }

    public function test_each_request_is_timed_on_its_own(): void
    {
        Route::middleware('web')->get('/_server-timing-slow', function (): string {
            usleep(80_000);

            return 'slow';
        });
        Route::middleware('web')->get('/_server-timing-fast', fn (): string => 'fast');

        $slow = $this->duration($this->get('/_server-timing-slow')->assertOk());
        $fast = $this->duration($this->get('/_server-timing-fast')->assertOk());

        $this->assertGreaterThanOrEqual(80.0, $slow);
        $this->assertLessThan(80.0, $fast);
    }

    private function duration(TestResponse $response): float
    {
        $header = (string) $response->headers->get('Server-Timing');
        $this->assertMatchesRegularExpression('/^app;dur=\d+\.\d$/', $header);

        return (float) mb_substr($header, mb_strlen('app;dur='));
    }
}
