<?php

declare(strict_types=1);

namespace App\Services\Reports\Composers;

use App\Services\Reports\BillingSummary;
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
 * Note on wording: the billing summary uses fixed month labels ("Bilans na
 * start miesiąca"), grammatical in every month, and is shared with the AI
 * composer through BillingSummary so both read identically.
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
     * @return list<string>
     */
    private function billingSummary(ReportContext $context): array
    {
        return BillingSummary::lines(
            $context->openingBalanceHours,
            (float) $context->contractedHours(),
            $context->actualHours,
            $context->rolloverCapHours,
            null,
            $context->periodType,
        );
    }

    private function title(ReportContext $context): string
    {
        if ($context->periodType === 'month') {
            return $context->periodStart->translatedFormat('F Y');
        }

        return __('Week of :date', ['date' => $context->periodStart->toDateString()]);
    }
}
