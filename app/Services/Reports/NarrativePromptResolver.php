<?php

declare(strict_types=1);

namespace App\Services\Reports;

interface NarrativePromptResolver
{
    /**
     * The system prompt that drives AiNarrativeComposer.
     *
     * Implementations resolve the account's own prompt when it has one and
     * fall back to the prompt shipped with the application, so a clone works
     * out of the box and an instance can carry its own wording without a
     * code change.
     *
     * @throws NarrativePromptUnavailable when no prompt can be resolved
     */
    public function resolve(int $accountId): string;

    /**
     * Where the resolved prompt came from ('settings' or 'file'), for
     * `reports:prompt-show` and for diagnosing a surprising draft. Returns
     * null when nothing resolves.
     */
    public function source(int $accountId): ?string;
}
