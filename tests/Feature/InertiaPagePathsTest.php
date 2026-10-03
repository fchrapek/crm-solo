<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class InertiaPagePathsTest extends TestCase
{
    public function test_every_inertia_page_path_matches_a_directory_with_the_exact_same_case(): void
    {
        foreach (config('inertia.testing.page_paths') as $path) {
            $this->assertContains(basename($path), scandir(dirname($path)), "{$path} differs in case from the directory on disk; Linux CI cannot find the pages.");
        }
    }
}
