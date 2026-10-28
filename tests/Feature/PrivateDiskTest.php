<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PrivateDiskTest extends TestCase
{
    public function test_the_private_disk_has_no_http_route_of_its_own(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertFalse(Route::has('storage.local'));
        $this->assertFalse(Route::has('storage.local.upload'));
    }

    public function test_the_private_disk_keeps_its_root_and_the_framework_disks_stay_defined(): void
    {
        $this->assertSame(storage_path('app/private'), config('filesystems.disks.local.root'));
        $this->assertSame('local', config('filesystems.default'));
        $this->assertArrayHasKey('public', config('filesystems.disks'));
    }
}
