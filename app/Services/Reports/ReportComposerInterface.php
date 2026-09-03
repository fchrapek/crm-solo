<?php

declare(strict_types=1);

namespace App\Services\Reports;

interface ReportComposerInterface
{
    /**
     * Stable identifier persisted on `client_reports.composer_key`. Lowercase
     * snake_case. Used to re-resolve the composer on re-generate.
     *
     * It records the composer that was SELECTED, not necessarily the one that
     * wrote the body: AiNarrativeComposer degrades to the structured list when
     * the provider is down, and that row still reads `ai_narrative` so a
     * re-generate retries the AI. The degradation is logged, not stored.
     */
    public function key(): string;

    /**
     * Human-readable label shown in the Generate dialog.
     */
    public function label(): string;

    /**
     * Return the markdown body for the given period. Implementations should
     * be deterministic given the same context, or document their non-
     * determinism (e.g. AI-driven composers).
     */
    public function compose(ReportContext $context): string;
}
