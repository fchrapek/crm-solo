<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\Reports\NarrativePromptResolver;
use App\Services\Reports\NarrativePromptUnavailable;
use App\Services\Reports\SettingsNarrativePromptResolver;
use Illuminate\Console\Command;

/**
 * Says which report prompt an account actually resolves, and shows its head.
 * The first thing to run when a generated report comes out the wrong shape.
 */
final class ReportPromptShow extends Command
{
    protected $signature = 'reports:prompt-show
        {--account= : Account id (defaults to the only account)}
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
        $given = $this->option('account');

        if ($given !== null) {
            return (int) $given;
        }

        $ids = Account::query()->orderBy('id')->pluck('id');

        if ($ids->count() === 1) {
            return (int) $ids->first();
        }

        if ($ids->isEmpty()) {
            $this->error('No accounts exist. Seed one first.');

            return null;
        }

        $this->error('Several accounts exist; pass --account='.$ids->implode('|'));

        return null;
    }
}
