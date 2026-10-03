<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Redaction;
use PHPUnit\Framework\TestCase;

final class RedactionTest extends TestCase
{
    public function test_a_secret_is_matched_as_stored_trimmed_and_line_by_line(): void
    {
        $variants = Redaction::variants(["  top-line\nsecond-line  "]);

        $this->assertContains("  top-line\nsecond-line  ", $variants);
        $this->assertContains("top-line\nsecond-line", $variants);
        $this->assertContains('top-line', $variants);
        $this->assertContains('second-line', $variants);
    }

    public function test_longer_forms_come_first_and_tiny_fragments_are_left_alone(): void
    {
        $variants = Redaction::variants(["abcdefgh\nab"]);

        $this->assertSame("abcdefgh\nab", $variants[0]);
        $this->assertNotContains('ab', $variants);
    }
}
