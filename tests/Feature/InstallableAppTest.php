<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InstallableAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_links_the_manifest_and_the_home_screen_icon(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('<link rel="manifest" href="/pwa/manifest.webmanifest">', false)
            ->assertSee('<link rel="apple-touch-icon" href="/pwa/icon-180.png">', false);

        $this->assertFileExists(public_path('pwa/icon-180.png'));
    }

    public function test_the_manifest_opens_standalone_with_icons_of_the_sizes_it_names(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('pwa/manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['start_url']);

        $sizes = [];
        foreach ($manifest['icons'] as $icon) {
            // Cloudflare Access lets only /pwa/ through before login, so an icon elsewhere fails to install.
            $this->assertStringStartsWith('/pwa/', $icon['src']);
            [$width, $height] = getimagesize(public_path(mb_ltrim($icon['src'], '/')));
            $this->assertSame($icon['sizes'], "{$width}x{$height}");
            $sizes[] = $icon['sizes'];
        }

        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);
    }
}
