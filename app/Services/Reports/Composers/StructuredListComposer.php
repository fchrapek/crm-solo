<?php

declare(strict_types=1);

namespace App\Services\Reports\Composers;

use App\Services\Reports\ReportComposerInterface;
use App\Services\Reports\ReportContext;

/**
 * Deterministic composer, no LLM in the loop. Emits the same fixed report
 * shape as the AI composer so a client cannot tell which one produced their
 * draft: development work as a flat bullet list, the retainer baseline
 * verbatim, and the hours balance stated exactly once in a closing billing
 * summary.
 *
 * It is also the fallback when the AI provider is unavailable or
 * unconfigured, which on an instance with no API key means it is the only
 * composer that ever runs — so its output has to be shippable, not a
 * degraded placeholder.
 *
 * What it cannot do is write prose: it lists the task name and, when the
 * description opens with a clean plain-text sentence, that sentence. The
 * owner edits the draft from there.
 *
 * Note on wording: the AI prompt's Polish billing lines decline the month
 * name ("Bilans na start marca"). A model inflects for free; PHP cannot, and
 * Carbon's 'F' yields the nominative only. The labels here are therefore
 * declension-free and grammatical in every month.
 */
final class StructuredListComposer implements ReportComposerInterface
{
    /**
     * Longest description opener still treated as a usable bullet tail.
     */
    private const MAX_SENTENCE_LENGTH = 180;

    public function key(): string
    {
        return 'structured_list';
    }

    public function label(): string
    {
        return __('Structured list (no AI)');
    }

    public function compose(ReportContext $context): string
    {
        $lines = [];

        $lines[] = '# '.$this->title($context);
        $lines[] = '';

        // Development work: one bullet per reportable task, never merged,
        // never dropped, no per-item hours. Omitted entirely when the cycle
        // had no reportable work — the baseline below still states what the
        // retainer covers.
        if ($context->tasks->isNotEmpty()) {
            $lines[] = '## '.__('Development work');
            $lines[] = '';

            foreach ($context->tasks as $task) {
                $lines[] = '- '.$this->bullet($task);
            }

            $lines[] = '';
        }

        // The standing maintenance scope, verbatim. It carries its own h3
        // sections, so it is not wrapped in another heading.
        $baseline = $context->reportBaselineMarkdown();
        if ($baseline !== null) {
            $lines[] = mb_rtrim($baseline);
            $lines[] = '';
        }

        // The only place a balance or an hours figure appears. No overage
        // cost anywhere: overruns are absorbed by the hours bank and never
        // invoiced, so quoting a price promises a charge that never arrives.
        if ($context->contractedHours() !== null) {
            foreach ($this->billingSummary($context) as $line) {
                $lines[] = $line;
            }
        }

        if ($context->tasks->isEmpty() && $baseline === null && $context->contractedHours() === null) {
            $lines[] = '_'.__('No completed tasks or logged time in this period.').'_';
        }

        return mb_rtrim(implode("\n", $lines))."\n";
    }

    /**
     * Task name, plus the description's opening sentence when it is clean
     * plain text. Markdown-flavoured descriptions (lists, headings, fences,
     * emphasis) are skipped rather than half-rendered into a bullet.
     *
     * @param  array{name: string, description: ?string}  $task
     */
    private function bullet(array $task): string
    {
        $name = mb_rtrim(mb_trim($task['name']), '.');
        $tail = $this->openingSentence($task['description'] ?? null);

        return $tail === null ? $name : $name.'. '.$tail;
    }

    private function openingSentence(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $first = mb_trim((string) preg_replace('/\s+/u', ' ', mb_trim(explode("\n", $description)[0])));

        if ($first === '' || mb_strlen($first) > self::MAX_SENTENCE_LENGTH) {
            return null;
        }

        // Must open like a sentence. A leading list marker, heading hash or
        // quote caret means the description is structured markdown, and a
        // blacklist of inline characters would not catch it.
        if (preg_match('/^[\p{L}\p{N}]/u', $first) !== 1) {
            return null;
        }

        // Inline markdown or a URL is left for the human editor rather than
        // half-rendered into a bullet.
        if (preg_match('/[`*_#\[\]|]|https?:\/\//u', $first) === 1) {
            return null;
        }

        return mb_rtrim($first, '.').'.';
    }

    /**
     * Four plain lines: what rolled in, this period's pool, what was used,
     * what rolls out. Plus one line when the agreed carry-over cap clipped
     * the balance, since those hours are gone and the client should see why.
     *
     * @return list<string>
     */
    private function billingSummary(ReportContext $context): array
    {
        $contracted = (float) $context->contractedHours();
        $available = (float) $context->availableHours();
        $uncapped = $context->openingBalanceHours + $contracted;
        $forfeited = round($uncapped - $available, 2);

        $lines = [
            '## '.__('Billing summary'),
            '',
            __('Opening balance').': '.$this->signedHours($context->openingBalanceHours),
            '',
            __('Pool for the period').': '.$this->signedHours($contracted),
            '',
            __('Used in the period').': '.$this->signedHours(-$context->actualHours),
            '',
            __('Closing balance').': '.$this->signedHours((float) $context->closingBalanceHours()),
        ];

        if ($forfeited > 0) {
            $lines[] = '';
            $lines[] = __('Hours above the agreed carry-over cap, not carried forward: :hours', [
                'hours' => $this->formatHours($forfeited),
            ]);
        }

        return $lines;
    }

    private function title(ReportContext $context): string
    {
        if ($context->periodType === 'month') {
            return $context->periodStart->translatedFormat('F Y');
        }

        return __('Week of :date', ['date' => $context->periodStart->toDateString()]);
    }

    /**
     * Signed so the direction reads at a glance. Zero carries no sign, and
     * the minus is a real minus sign, not a hyphen.
     */
    private function signedHours(float $hours): string
    {
        $rounded = round($hours, 2);

        if ($rounded > 0) {
            return '+'.$this->formatHours($rounded);
        }

        if ($rounded < 0) {
            return '−'.$this->formatHours(abs($rounded));
        }

        return $this->formatHours(0.0);
    }

    private function formatHours(float $hours): string
    {
        $rounded = round($hours, 2);

        return $rounded === floor($rounded)
            ? sprintf('%dh', (int) $rounded)
            : sprintf('%.2fh', $rounded);
    }
}
