<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Runs scripts/hooks/commit-msg.sh, the house-style check shared by the
 * commit-msg hook and the PR title check in CI.
 */
final class CommitMessageHookTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function acceptedSubjects(): array
    {
        return [
            'known area' => ['CI: branch model on the free plan'],
            'area with a space' => ['Month close: tick a site'],
            'Polish text within 72 characters' => ['Docs: zażółć gęślą jaźń, polskie znaki liczą się jako jeden'],
            'merge commit' => ['Merge branch develop into main'],
            'revert commit' => ['Revert "CI: something"'],
            'fixup commit' => ['fixup! Docs: typo'],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function rejectedSubjects(): array
    {
        return [
            'conventional commit' => ['feat(dev): add a thing', 'unknown area'],
            'unknown area' => ['Stuff: add a thing', 'unknown area'],
            'no area' => ['add a thing', 'Area: what changed'],
            'empty description' => ['Docs: ', 'Area: what changed'],
            'em dash' => ["Docs: one \u{2014} two", 'em or en dash'],
            'en dash' => ["Docs: 1\u{2013}2", 'em or en dash'],
            'too long' => ['Docs: '.str_repeat('x', 67), 'longer than 72'],
        ];
    }

    #[DataProvider('acceptedSubjects')]
    public function test_it_accepts_subjects_in_house_style(string $subject): void
    {
        $process = $this->check(['--subject', $subject]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    #[DataProvider('rejectedSubjects')]
    public function test_it_rejects_subjects_outside_house_style(string $subject, string $reason): void
    {
        $process = $this->check(['--subject', $subject]);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString($reason, $process->getErrorOutput());
    }

    public function test_it_reads_a_message_file_and_ignores_git_comment_lines(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'msg');
        file_put_contents($file, "Docs: explain the hooks\n\nWhy it matters.\n# Lines starting with '#' \u{2014} ignored.\n");

        try {
            $process = $this->check([$file]);
        } finally {
            unlink($file);
        }

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function test_it_rejects_a_dash_in_the_body(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'msg');
        file_put_contents($file, "Docs: explain the hooks\n\nBecause \u{2014} reasons.\n");

        try {
            $process = $this->check([$file]);
        } finally {
            unlink($file);
        }

        $this->assertSame(1, $process->getExitCode());
    }

    public function test_it_counts_characters_not_bytes_in_a_c_locale(): void
    {
        $subject = 'Docs: zażółć gęślą jaźń, polskie znaki liczą się jako jeden ok ok';

        $process = $this->check(['--subject', $subject], ['LC_ALL' => 'C', 'LANG' => 'C']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string>  $env
     */
    private function check(array $args, array $env = []): Process
    {
        $process = new Process([base_path('scripts/hooks/commit-msg.sh'), ...$args], base_path(), $env);
        $process->run();

        return $process;
    }
}
