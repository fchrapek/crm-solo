<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** The public image (Dockerfile.prod); the private hosting image has its own checks in HostingScriptsTest. */
final class DemoImageTest extends TestCase
{
    public function test_the_image_takes_composer_from_a_pinned_minor_tag(): void
    {
        preg_match_all('#(?:FROM |from=)composer:([^\s,]+)#', File::get(base_path('Dockerfile.prod')), $tags);

        $this->assertCount(3, $tags[1], 'the binary for the frontend stage, the dependency stage and the platform check');
        foreach ($tags[1] as $tag) {
            $this->assertMatchesRegularExpression('#^2\.\d+$#', $tag);
        }
    }
}
