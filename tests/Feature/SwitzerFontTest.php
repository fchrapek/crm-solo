<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\SwitzerFont;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class SwitzerFontTest extends TestCase
{
    public function test_pages_preload_the_self_hosted_switzer_and_declare_it_inline_with_no_cdn_round_trip(): void
    {
        $html = $this->pageWithPublicDir(withFont: true);

        $this->assertStringContainsString('<link rel="preload" href="'.asset(SwitzerFont::FILE).'" as="font" type="font/woff2" crossorigin>', $html);
        $this->assertMatchesRegularExpression("/@font-face \\{\\s*font-family: 'Switzer';[^}]*font-weight: 100 900;[^}]*font-display: swap;[^}]*src: url\\('".preg_quote(asset(SwitzerFont::FILE), '/')."'\\) format\\('woff2'\\);/", $html);
        $this->assertStringNotContainsString('fontshare.com', $html);
    }

    public function test_without_the_files_the_page_loads_switzer_from_fontshare(): void
    {
        $html = $this->pageWithPublicDir(withFont: false);

        $this->assertStringContainsString('<link rel="stylesheet" href="https://api.fontshare.com/v2/css?f[]=switzer@400,500,600,700&display=swap">', $html);
        $this->assertStringNotContainsString('rel="preload"', $html);
    }

    public function test_the_text_stack_takes_arrows_from_inter_then_falls_back_to_metric_matched_arial(): void
    {
        $css = File::get(resource_path('css/solo.css'));

        $this->assertStringContainsString("--solo-font-text: 'Solo Arrows', 'Switzer', 'Switzer Fallback',", $css);
        $this->assertMatchesRegularExpression("/font-family: 'Solo Arrows';[^}]*unicode-range: U\\+2190-2199, U\\+21B5, U\\+2318;/", $css);
        $this->assertSame(2, preg_match_all("/font-family: 'Switzer Fallback';[^}]*size-adjust: [\\d.]+%;[^}]*ascent-override: [\\d.]+%;[^}]*descent-override: [\\d.]+%;[^}]*line-gap-override: [\\d.]+%;/", $css));
    }

    public function test_the_font_files_never_reach_the_public_snapshot(): void
    {
        $this->assertMatchesRegularExpression('#^public/fonts/switzer/\s+export-ignore$#m', File::get(base_path('.gitattributes')));
    }

    /** A public directory of its own, so the test holds in the snapshot too, where the font is not shipped. */
    private function pageWithPublicDir(bool $withFont): string
    {
        $public = sys_get_temp_dir().'/switzer-public-'.getmypid().'-'.($withFont ? 'font' : 'none');
        File::ensureDirectoryExists(dirname($public.'/'.SwitzerFont::FILE));
        if ($withFont) {
            File::put($public.'/'.SwitzerFont::FILE, 'woff2');
        }
        $this->app->usePublicPath($public);

        try {
            return $this->get('/login')->assertOk()->getContent();
        } finally {
            File::deleteDirectory($public);
        }
    }
}
