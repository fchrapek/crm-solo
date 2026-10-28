<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\HoursText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HoursTextTest extends TestCase
{
    /** @return array<string, array{float, string, string, bool, string}> */
    public static function sharedFixtures(): array
    {
        $fixtures = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/hours-text.json'), true);

        return collect($fixtures)->mapWithKeys(fn (array $f): array => [
            "{$f['kind']} {$f['locale']} {$f['value']}".($f['signed'] ? ' signed' : '') => [(float) $f['value'], $f['locale'], $f['kind'], $f['signed'], $f['expected']],
        ])->all();
    }

    #[DataProvider('sharedFixtures')]
    public function test_the_server_prints_hours_exactly_as_the_screens_do(float $value, string $locale, string $kind, bool $signed, string $expected): void
    {
        $this->assertSame($expected, $kind === 'terms' ? HoursText::terms($value, $locale) : HoursText::number($value, $signed, $locale));
    }

    public function test_sentences_carry_the_unit(): void
    {
        $this->assertSame('−16,0 h', HoursText::signed(-16, 'pl'));
        $this->assertSame('30.0 h', HoursText::plain(30, 'en'));
    }
}
