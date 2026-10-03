<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Tasks\CardDescription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Description cleanup removes markup noise only: the words a client wrote
 * come out exactly as they went in.
 */
final class CardDescriptionTest extends TestCase
{
    /**
     * @return array<string, array{string|null, string}>
     */
    public static function normalisations(): array
    {
        return [
            'null is empty' => [null, ''],
            'plain text unchanged' => ['Zmień tekst w stopce.', 'Zmień tekst w stopce.'],
            'windows line endings' => ["one\r\ntwo\rthree", "one\ntwo\nthree"],
            'trailing spaces and tabs per line' => ["one  \ntwo\t\nthree ", "one\ntwo\nthree"],
            'trailing blank lines' => ["text\n\n\n", 'text'],
            'leading indentation kept' => ["- a\n  - b", "- a\n  - b"],
            'empty link title dropped' => ['[strona](https://example.com/a "")', '[strona](https://example.com/a)'],
            'zero-width link title dropped' => ["[strona](https://example.com/a \"\u{200C}\")", '[strona](https://example.com/a)'],
            'real link title kept' => ['[strona](https://example.com/a "Home")', '[strona](https://example.com/a "Home")'],
            'self link collapsed' => ['see [https://example.com/x](https://example.com/x) now', 'see https://example.com/x now'],
            'self link with empty title collapsed' => ['[https://example.com/x](https://example.com/x "")', 'https://example.com/x'],
            'self link with escaped underscore collapsed' => ['[https://example.com/a\_b](https://example.com/a_b)', 'https://example.com/a_b'],
            'labelled link kept' => ['[tutaj](https://example.com/x)', '[tutaj](https://example.com/x)'],
            'image kept even when alt is the url' => ['![https://example.com/i.png](https://example.com/i.png)', '![https://example.com/i.png](https://example.com/i.png)'],
            'url with parentheses untouched' => ['[x](https://en.wikipedia.org/wiki/A_(b))', '[x](https://en.wikipedia.org/wiki/A_(b))'],
            'dashes and quotes untouched' => ['Tekst — „cytat" i “inny”', 'Tekst — „cytat" i “inny”'],
            'self link in a code span untouched' => ['Wpisz `[https://a.pl](https://a.pl)` dosłownie', 'Wpisz `[https://a.pl](https://a.pl)` dosłownie'],
            'self link in a fenced block untouched' => ["```\n[https://a.pl](https://a.pl \"\")  \n```", "```\n[https://a.pl](https://a.pl \"\")  \n```"],
            'self link in an indented block untouched' => ["Kod:\n\n    [https://a.pl](https://a.pl)", "Kod:\n\n    [https://a.pl](https://a.pl)"],
            'escaped bracket is not a link' => ['\\[https://a.pl](https://a.pl)', '\\[https://a.pl](https://a.pl)'],
            'self link in angle brackets collapsed' => ['[https://a.pl/x](<https://a.pl/x>)', 'https://a.pl/x'],
            'link with a title keeps everything' => ['[strona](https://a.pl \'Strona\')', '[strona](https://a.pl \'Strona\')'],
            'unclosed bracket untouched' => ['[nie link (https://a.pl)', '[nie link (https://a.pl)'],
        ];
    }

    #[DataProvider('normalisations')]
    public function test_normalises_markup_without_changing_words(?string $input, string $expected): void
    {
        $this->assertSame($expected, CardDescription::normalize($input));
    }

    #[DataProvider('normalisations')]
    public function test_normalising_twice_changes_nothing(?string $input, string $expected): void
    {
        $once = CardDescription::normalize($input);

        $this->assertSame($once, CardDescription::normalize($once));
    }

    public function test_links_are_extracted_in_order_once_each(): void
    {
        $text = CardDescription::normalize(
            "Popraw [stopkę](https://example.com/stopka \"\") i baner.\n"
            ."Zrzut: ![zrzut](https://trello.com/1/cards/abc/attachments/def/download/a.png)\n"
            ."Strona: https://example.com/oferta. Też (https://example.com/kontakt)\n"
            .'Jeszcze raz https://example.com/oferta i <https://example.com/x_(y)>'
        );

        $this->assertSame([
            ['url' => 'https://example.com/stopka', 'text' => 'stopkę'],
            ['url' => 'https://trello.com/1/cards/abc/attachments/def/download/a.png', 'text' => 'zrzut'],
            ['url' => 'https://example.com/oferta', 'text' => null],
            ['url' => 'https://example.com/kontakt', 'text' => null],
            ['url' => 'https://example.com/x_(y)', 'text' => null],
        ], CardDescription::links($text));
    }

    public function test_links_keep_balanced_parentheses_titles_and_source_order(): void
    {
        $text = CardDescription::normalize(
            'Najpierw https://a.pl/pierwszy, potem [wiki](https://en.wikipedia.org/wiki/A_(b)) '
            .'i [strona](https://a.pl/s "Tytuł"), a w kodzie `https://a.pl/kod` nie.'
        );

        $this->assertSame([
            ['url' => 'https://a.pl/pierwszy', 'text' => null],
            ['url' => 'https://en.wikipedia.org/wiki/A_(b)', 'text' => 'wiki'],
            ['url' => 'https://a.pl/s', 'text' => 'strona'],
        ], CardDescription::links($text));
    }

    public function test_a_very_long_link_label_keeps_the_description(): void
    {
        $long = '['.str_repeat('a', 10000).'](https://example.com)';
        $nested = str_repeat('[', 20000).'x'.str_repeat(']', 20000);
        $ticks = str_repeat('` ', 20000).'x';

        foreach ([$long, $nested, $ticks, $long."\n".$nested] as $input) {
            $this->assertSame($input, CardDescription::normalize($input));
        }
        $this->assertSame([['url' => 'https://example.com', 'text' => str_repeat('a', 10000)]], CardDescription::links($long));
        $this->assertTrue(CardDescription::isSubstantive($long));
    }

    public function test_a_description_of_only_links_says_nothing(): void
    {
        $this->assertFalse(CardDescription::isSubstantive('https://example.com/a-very-long-path-with-many-words'));
        $this->assertFalse(CardDescription::isSubstantive('[x](https://example.com/a-very-long-path-with-many-words)'));
        $this->assertFalse(CardDescription::isSubstantive('Popraw to'));
        $this->assertTrue(CardDescription::isSubstantive('Zmień numer telefonu w stopce na nowy. https://example.com'));
    }
}
