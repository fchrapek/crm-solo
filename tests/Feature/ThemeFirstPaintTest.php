<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class ThemeFirstPaintTest extends TestCase
{
    public function test_the_page_carries_the_saved_theme_before_any_script_runs(): void
    {
        $this->withUnencryptedCookie('theme', 'boring-saas')
            ->get('/login')
            ->assertOk()
            ->assertSee('data-theme="boring-saas"', false);
    }

    public function test_a_missing_retired_or_forged_theme_paints_paper(): void
    {
        $this->get('/login')->assertSee('data-theme="paper"', false);

        foreach (['ink', '"><script>', ''] as $value) {
            $this->withUnencryptedCookie('theme', $value)
                ->get('/login')
                ->assertSee('data-theme="paper"', false)
                ->assertDontSee('<script>alert', false);
        }
    }
}
