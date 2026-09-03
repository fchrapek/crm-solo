<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Every page renders through app.blade.php, which calls @vite. Without
        // this, the whole feature suite depends on public/build/manifest.json
        // existing, and that file is a gitignored build artifact: it happens to
        // be present on a machine that has run `bun run build`, and is absent in
        // a fresh checkout or on CI. No test asserts on an asset URL, so the
        // dependency was never wanted.
        $this->withoutVite();
    }
}
