<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Models\Account;
use App\Services\Reports\NarrativePromptResolver;
use App\Services\Reports\NarrativePromptUnavailable;
use App\Services\Reports\SettingsNarrativePromptResolver;
use Illuminate\Console\Command;

/**
 * Says which report prompt an account actually resolves, and shows its head.
 * The first thing to run when a generated report comes out the wrong shape.
 */
#[AccountScope(AccountScope::ACTING)]
final class ReportPromptShow extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'reports:prompt-show
        {--account= : Account id; must be the acting account (the default)}
        {--full : Print the whole prompt instead of the first lines}';

    protected $description = 'Show which report narrative prompt resolves, and from where.';

    public function handle(NarrativePromptResolver $resolver): int
    {
        $accountId = $this->resolveAccountId();

        if ($accountId === null) {
            return self::FAILURE;
        }

        try {
            $prompt = $resolver->resolve($accountId);
        } catch (NarrativePromptUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $source = $resolver->source($accountId);

        $this->line(sprintf(
            'account=%d source=%s length=%d%s',
            $accountId,
            $source ?? 'none',
            mb_strlen($prompt),
            $source === 'file' && $resolver instanceof SettingsNarrativePromptResolver
                ? ' path='.$resolver->path()
                : '',
        ));

        $lines = explode("\n", $prompt);
        $shown = $this->option('full') ? $lines : array_slice($lines, 0, 12);

        $this->newLine();
        foreach ($shown as $line) {
            $this->line('  '.$line);
        }

        if (! $this->option('full') && count($lines) > count($shown)) {
            $this->line(sprintf('  ... (%d more lines, --full to see them)', count($lines) - count($shown)));
        }

        return self::SUCCESS;
    }

    private function resolveAccountId(): ?int
    {
        return $this->actingAccountId();
    }
}
