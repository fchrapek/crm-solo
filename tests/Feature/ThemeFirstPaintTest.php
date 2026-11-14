<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class ThemeFirstPaintTest extends TestCase
{
    public function test_a_missing_retired_or_forged_theme_paints_paper(): void
    {
        $this->get('/login')->assertSee('data-theme="paper"', false);

        foreach (['boring-saas', 'ink', '"><script>', ''] as $value) {
            $this->withUnencryptedCookie('theme', $value)
                ->get('/login')
                ->assertSee('data-theme="paper"', false)
                ->assertDontSee('<script>alert', false);
        }
    }
}
