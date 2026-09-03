<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Humanizer;
use PHPUnit\Framework\TestCase;

/**
 * The deterministic content gate: punctuation-level LLM tells only.
 * Vocabulary rules live at the generation layer (humanizer skill + prompts).
 */
final class HumanizerTest extends TestCase
{
    public function test_em_and_en_dashes_become_plain_hyphens(): void
    {
        $this->assertSame('update - new photos', Humanizer::clean("update \u{2014} new photos"));
        $this->assertSame('update - new photos', Humanizer::clean("update \u{2013} new photos"));
        $this->assertSame('update - glued', Humanizer::clean("update\u{2014}glued"));
    }

    public function test_digit_ranges_keep_a_tight_hyphen(): void
    {
        $this->assertSame('open 9-17', Humanizer::clean("open 9\u{2013}17"));
    }

    public function test_curly_quotes_and_ellipsis_normalize(): void
    {
        $this->assertSame('he said "tak" and \'nie\'...', Humanizer::clean("he said \u{201E}tak\u{201D} and \u{2018}nie\u{2019}\u{2026}"));
    }

    public function test_invisible_artifacts_are_stripped(): void
    {
        $this->assertSame('ab c', Humanizer::clean("a\u{200B}b\u{00A0}c"));
    }

    public function test_plain_human_text_passes_untouched(): void
    {
        $text = "Poprawki na stronie - sekcja hero, zdjęcia 2x.\nNastępny krok: wdrożenie.";
        $this->assertSame($text, Humanizer::clean($text));
    }

    public function test_null_and_empty_pass_through(): void
    {
        $this->assertNull(Humanizer::clean(null));
        $this->assertSame('', Humanizer::clean(''));
    }

    public function test_lang_files_carry_no_long_dashes(): void
    {
        foreach (['en', 'pl'] as $locale) {
            $raw = (string) file_get_contents(__DIR__."/../../lang/{$locale}.json");
            $this->assertStringNotContainsString("\u{2014}", $raw, "em dash in lang/{$locale}.json");
            $this->assertStringNotContainsString("\u{2013}", $raw, "en dash in lang/{$locale}.json");
        }
    }
}
