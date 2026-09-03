<?php

declare(strict_types=1);

namespace Tests\Unit;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Fixtures once carried live Infakt client and invoice ids and the owner's own
 * FluentForm submission references, which are pointers into third-party
 * accounts rather than anything the test needs.
 */
final class NoRealIntegrationIdsTest extends TestCase
{
    /**
     * Infakt ids are 8 digits. Synthetic ones start 1 or 2 followed by five
     * zeroes; anything else in that shape came from a real account.
     */
    public function test_no_fixture_carries_a_real_looking_infakt_id(): void
    {
        foreach ($this->sources() as $file => $body) {
            preg_match_all('/\b\d{8}\b/', $body, $m);

            foreach (array_unique($m[0]) as $id) {
                $this->assertMatchesRegularExpression(
                    '/^[12]0000\d{3}$/',
                    $id,
                    "{$file} carries {$id}, which is shaped like a live Infakt id.",
                );
            }
        }
    }

    /**
     * Trello board and Clockify entry ids are 24 hex characters. No fixture
     * needs a real one.
     */
    public function test_no_fixture_carries_a_trello_or_clockify_id(): void
    {
        foreach ($this->sources() as $file => $body) {
            $this->assertSame(
                [],
                array_values(array_unique(preg_match_all('/\b[0-9a-f]{24}\b/', $body, $m) ? $m[0] : [])),
                "{$file} carries a 24-character hex id, the shape Trello and Clockify use.",
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function sources(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];

        foreach (['tests', 'database', 'config', 'routes'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir));

            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $out[str_replace($root.'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $out;
    }
}
