<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class TestEnvironmentIsolationTest extends TestCase
{
    public function test_maintenance_mode_in_a_test_stays_inside_that_process(): void
    {
        $this->app->maintenanceMode()->activate([]);
        try {
            $this->assertTrue($this->app->isDownForMaintenance());
            $this->assertFileDoesNotExist(storage_path('framework/down'));
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }

        $this->get('/login')->assertOk();
    }
}
