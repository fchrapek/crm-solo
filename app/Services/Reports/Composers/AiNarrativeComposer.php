<?php

declare(strict_types=1);

namespace App\Services\Reports\Composers;

use App\Services\AI\AIProviderInterface;
use App\Services\Reports\NarrativePromptResolver;
use App\Services\Reports\ReportComposerInterface;
use App\Services\Reports\ReportContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI-driven prose composer. Sends the structured context to the configured
 * provider (defaults to OpenAI) and asks for the fixed report shape. Falls
 * back to StructuredListComposer's deterministic output on provider failure
 * so the user always gets a draft to edit.
 *
 * The system prompt is resolved rather than compiled in, so an instance can
 * carry its own wording without a code change. The resolve happens outside
 * the try/catch on purpose: a provider that is down is a temporary condition
 * worth degrading through, while a prompt that cannot be read is an operator
 * error that must surface instead of silently changing the report's shape.
 */
final class AiNarrativeComposer implements ReportComposerInterface
{
    public function __construct(
        private readonly AIProviderInterface $provider,
        private readonly StructuredListComposer $fallback,
        private readonly NarrativePromptResolver $prompts,
    ) {}

    public function key(): string
    {
        return 'ai_narrative';
    }

    public function label(): string
    {
        return __('AI narrative (prose)');
    }

    public function compose(ReportContext $context): string
    {
        $payload = $this->buildPayload($context);
        $systemPrompt = $this->prompts->resolve($context->client->account_id);

        try {
            $response = $this->provider->chat(
                $systemPrompt,
                [['role' => 'user', 'content' => $payload]],
                [
                    'model' => config('services.openai.report_model', config('services.openai.model', 'gpt-4o')),
                    'max_tokens' => 1500,
                    'temperature' => 0.4,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('Report AI composer failed, falling back to structured list', [
                'client_id' => $context->client->id,
                'period_start' => $context->periodStart->toDateString(),
                'error' => $e->getMessage(),
            ]);

            return $this->fallback->compose($context);
        }

        $body = mb_trim($response);

        if ($body === '') {
            return $this->fallback->compose($context);
        }

        return $body."\n";
    }

    private function buildPayload(ReportContext $context): string
    {
        $contracted = $context->contractedHours();

        $taskRows = $context->tasks->map(function (array $task): array {
            return array_filter([
                'name' => $task['name'],
                'description' => $task['description'] !== null && mb_trim((string) $task['description']) !== ''
                    ? mb_substr((string) $task['description'], 0, 500)
                    : null,
                'project' => $task['project'],
                'minutes' => $task['minutes'] > 0 ? $task['minutes'] : null,
                'completed' => $task['completed_at'] !== null,
                'type' => $task['type'],
            ], fn ($v): bool => $v !== null);
        });

        $timeRows = $context->timeEntries
            ->filter(fn (array $entry): bool => mb_trim((string) ($entry['description'] ?? '')) !== '')
            ->map(fn (array $entry): array => [
                'description' => mb_substr((string) $entry['description'], 0, 300),
                'minutes' => $entry['minutes'],
                'project' => $entry['project'],
            ])
            ->take(50)
            ->values();

        return json_encode([
            'locale' => $context->locale,
            'period_type' => $context->periodType,
            'period_start' => $context->periodStart->toDateString(),
            'period_end' => $context->periodEnd->toDateString(),
            'actual_hours' => $context->actualHours,
            'contracted_hours' => $contracted,
            // The budget is the pool plus what rolled in, capped. Sent so the
            // model never has to derive it, and never reads a pool overrun as
            // an overage the client has to pay for.
            'opening_balance_hours' => $context->openingBalanceHours,
            'available_hours' => $context->availableHours(),
            'closing_balance_hours' => $context->closingBalanceHours(),
            'rollover_cap_hours' => $context->rolloverCapHours,
            'overage_hours' => $context->overageHours(),
            'monthly_fee' => $context->monthlyFee(),
            'overage_hourly_rate' => $context->overageHourlyRate(),
            'currency' => $context->currency(),
            'client_name' => $context->client->name,
            'report_baseline_markdown' => $context->reportBaselineMarkdown(),
            'tasks' => $taskRows->values(),
            'time_entry_notes' => $timeRows,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
