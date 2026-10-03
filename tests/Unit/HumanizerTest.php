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
    /**
     * Rows labelled "control" already passed before line structure was
     * protected; they guard behaviour that must not regress.
     *
     * @return array<string, array{string, string}>
     */
    public static function markdownStructure(): array
    {
        $em = "\u{2014}";
        $en = "\u{2013}";

        return [
            'dash bullets keep their lines' => ["a\n{$en} b\n{$en} c", "a\n- b\n- c"],
            'control, held before too: em dash bullet without a space' => ["{$em}item", '- item'],
            'nested list keeps indentation' => ["- top\n  - nested\n    - deep", "- top\n  - nested\n    - deep"],
            'nested dash bullets keep indentation' => ["- top\n  {$en} nested\n    {$em} deep", "- top\n  - nested\n    - deep"],
            'a tab-indented top-level line is indented code' => ["\t{$en} item", "\t{$en} item"],
            'tab-indented bullet inside a list' => ["- top\n\t{$en} item", "- top\n\t- item"],
            'control, held before too: blockquote bullet' => ["> {$en} quoted", '> - quoted'],
            'ascii subtraction is left alone' => ['5 - 3 = 2', '5 - 3 = 2'],
            'control, held before too: spaced digit range tightens' => ["2020 {$en} 2021", '2020-2021'],
            'hard break survives' => ["line  \nnext", "line  \nnext"],
            'hard break after a closing dash' => ["koniec {$em}  \nnext", "koniec -  \nnext"],
            'dash at line end does not join lines' => ["first {$em}\nsecond", "first -\nsecond"],
            'dash at line start does not join lines' => ["first\n{$em} second", "first\n- second"],
            'control, held before too: blank lines are kept' => ["a {$em} b\n\n\nc", "a - b\n\n\nc"],
            'windows line endings are kept' => ["a {$em} b\r\n{$en} c\r\n", "a - b\r\n- c\r\n"],
            'control, held before too: heading' => ["## Prace {$en} rozwojowe", '## Prace - rozwojowe'],
            'control, held before too: table' => ["| a | b |\n|---|---|\n| x {$em} y | 9{$en}17 |", "| a | b |\n|---|---|\n| x - y | 9-17 |"],
            'thematic break of dashes' => ["a\n\n{$em}{$em}{$em}\n\nb", "a\n\n---\n\nb"],
            'inline code is verbatim' => ["use `a {$em} b` here {$em} ok", "use `a {$em} b` here - ok"],
            'double backtick code is verbatim' => ["``x \u{201C}y\u{201D}`` and \u{201C}z\u{201D}", "``x \u{201C}y\u{201D}`` and \"z\""],
            'fenced code is verbatim' => [
                "Intro {$em} text\n```php\n\$a = 'x' {$em} 'y';\n  {$en} indented\n```\nAfter {$en} fence",
                "Intro - text\n```php\n\$a = 'x' {$em} 'y';\n  {$en} indented\n```\nAfter - fence",
            ],
            'tilde fence is verbatim' => ["~~~\n{$em} raw\n~~~\n{$em} prose", "~~~\n{$em} raw\n~~~\n- prose"],
            'unclosed fence runs to the end' => ["```\n{$em} raw", "```\n{$em} raw"],
            'control, held before too: list item text keeps two spaces after the marker' => ["1.  first {$em} item", '1.  first - item'],
        ];
    }

    /**
     * Text that must come back byte for byte.
     *
     * @return array<string, array{string}>
     */
    public static function verbatim(): array
    {
        $en = "\u{2013}";
        $em = "\u{2014}";

        return [
            'fence inside a blockquote' => ["> ```\n> a {$em} b\n> ```"],
            'fence inside a list item' => ["- step:\n\n    ```sh\n    run {$en}x\n    ```"],
            'code span across two lines' => ["see `a {$em}\nb {$en} c` here"],
            'fence opened right after a list marker' => ["- ```\n  a{$em}b\n  ```\n\nafter"],
            'indented code block' => ["text\n\n    run a{$em}b\n    more {$en} x\n\nend"],
            'link destination holding a code span' => ["[link](foo`code`{$em}bar)"],
            'url' => ["https://example.test/a{$en}b"],
            'link destination' => ["[x](https://example.test/a{$en}b)"],
            'link destination without a slash' => ["[x](#part{$en}two)"],
            'angle link destination' => ["[x](<my file{$en}1.pdf>)"],
            'reference definition' => ["[ref]: https://example.test/a{$en}b"],
            'absolute path' => ["/tmp/a{$en}b"],
            'relative path' => ["app/Services/a{$em}b.php"],
            'home path' => ["~/notes/plan{$en}v2.md"],
        ];
    }

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

    #[\PHPUnit\Framework\Attributes\DataProvider('markdownStructure')]
    public function test_markdown_structure_survives(string $input, string $expected): void
    {
        $this->assertSame($expected, Humanizer::clean($input));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('markdownStructure')]
    public function test_cleaning_twice_equals_cleaning_once(string $input, string $expected): void
    {
        $this->assertSame($expected, Humanizer::clean(Humanizer::clean($input)));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('verbatim')]
    public function test_code_links_and_paths_pass_through_byte_for_byte(string $text): void
    {
        $this->assertSame($text, Humanizer::clean($text));
    }

    public function test_prose_around_protected_text_is_still_cleaned(): void
    {
        $en = "\u{2013}";

        $this->assertSame(
            'See [the doc](https://example.test/a'.$en.'b) - and /tmp/x'.$en.'y - "done"',
            Humanizer::clean("See [the doc](https://example.test/a{$en}b) {$en} and /tmp/x{$en}y {$en} \u{201E}done\u{201D}"),
        );
    }

    public function test_emoji_joiners_stay_and_stray_joiners_go(): void
    {
        $technologist = "\u{1F469}\u{200D}\u{1F4BB}";
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
        $toned = "\u{1F469}\u{1F3FD}\u{200D}\u{1F692}";

        $this->assertSame("{$technologist} {$family} {$toned}", Humanizer::clean("{$technologist} {$family} {$toned}"));
        $this->assertSame("ab \u{1F469}d", Humanizer::clean("a\u{200D}b \u{1F469}\u{200D}d"));
    }

    public function test_prose_after_a_fence_opened_on_a_list_marker_is_cleaned(): void
    {
        $em = "\u{2014}";

        $this->assertSame(
            "- ```\n  a{$em}b\n  ```\n\nprose - x",
            Humanizer::clean("- ```\n  a{$em}b\n  ```\n\nprose {$em} x"),
        );
    }

    public function test_a_code_span_before_a_link_keeps_the_link_inside_it(): void
    {
        $em = "\u{2014}";

        $this->assertSame("`[a](b {$em} c` d - e", Humanizer::clean("`[a](b {$em} c` d {$em} e"));
    }

    public function test_one_megabyte_of_short_code_spans_stays_well_under_128_megabytes(): void
    {
        $script = 'require "vendor/autoload.php";'
            .'$text = substr(str_repeat("`a` word ", 120000), 0, 1048572);'
            .'$out = App\Services\Humanizer::clean($text);'
            .'echo strlen($out) === strlen($text) ? "same" : "changed", " ", memory_get_peak_usage(true);';
        $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $script], dirname(__DIR__, 2));
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        [$result, $peak] = explode(' ', mb_trim($process->getOutput()));
        $this->assertSame('same', $result);
        $this->assertLessThan(96 * 1024 * 1024, (int) $peak);
    }

    public function test_a_very_long_code_span_survives_whole(): void
    {
        $text = '`'.str_repeat('a', 1_100_000)."` \u{2013} next";

        $this->assertSame('`'.str_repeat('a', 1_100_000).'` - next', Humanizer::clean($text));
    }

    public function test_code_spans_after_multibyte_text_are_found(): void
    {
        $em = "\u{2014}";

        $this->assertSame("Zażółć - `a {$em} b` i `c {$em} d` - koniec", Humanizer::clean("Zażółć {$em} `a {$em} b` i `c {$em} d` {$em} koniec"));
    }

    public function test_a_failed_match_keeps_the_text_instead_of_emptying_it(): void
    {
        $jit = ini_get('pcre.jit');
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');

        try {
            $this->assertSame("a \u{2013} b\nc", Humanizer::clean("a \u{2013} b\nc"));
        } finally {
            ini_set('pcre.jit', (string) $jit);
            ini_set('pcre.backtrack_limit', (string) $limit);
        }
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
