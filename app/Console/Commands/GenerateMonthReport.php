<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientReport;
use App\Services\Reports\ReportComposerRegistry;
use App\Services\Reports\ReportContext;
use App\Services\Reports\ReportDataAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;

/**
 * Headless twin of ClientReportsController::store, for closing a month from the
 * CLI. The --hours override exists because billing rounding lives on the report
 * snapshot, never on the time entries: pass the rounded figure and the body and
 * the stored actual_hours agree.
 */
final class GenerateMonthReport extends Command
{
    protected $signature = 'reports:generate
        {client : Client id}
        {--period= : YYYY-MM}
        {--hours= : Override actual_hours (rounded billing figure)}
        {--composer= : Composer key}
        {--locale=pl}';

    protected $description = 'Generate a draft client report for a period.';

    public function handle(ReportDataAggregator $aggregator, ReportComposerRegistry $composers): int
    {
        $client = Client::findOrFail((int) $this->argument('client'));
        $start = Carbon::parse($this->option('period').'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        App::setLocale($this->option('locale'));

        $composer = $this->option('composer')
            ? $composers->get($this->option('composer'))
            : $composers->default();

        $ctx = $aggregator->aggregate($client, $start, $end, 'month', App::getLocale());

        if ($this->option('hours') !== null) {
            $ctx = new ReportContext(
                client: $ctx->client,
                periodStart: $ctx->periodStart,
                periodEnd: $ctx->periodEnd,
                periodType: $ctx->periodType,
                retainer: $ctx->retainer,
                actualHours: (float) $this->option('hours'),
                tasks: $ctx->tasks,
                timeEntries: $ctx->timeEntries,
                locale: $ctx->locale,
                openingBalanceHours: $ctx->openingBalanceHours,
                rolloverCapHours: $ctx->rolloverCapHours,
            );
        }

        $report = $client->reports()->create([
            'account_id' => $client->account_id,
            'period_type' => 'month',
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'contracted_hours' => $ctx->contractedHours(),
            'actual_hours' => $ctx->actualHours,
            'opening_balance_hours' => $ctx->openingBalanceHours,
            'rollover_cap_hours' => $ctx->rolloverCapHours,
            'currency' => $ctx->currency(),
            'composer_key' => $composer->key(),
            'body_markdown' => $composer->compose($ctx),
            'status' => ClientReport::STATUS_DRAFT,
            'generated_at' => now(),
        ]);

        $this->line(sprintf(
            '#%d %s | opening %.2f + contracted %.2f - actual %.2f = closing %.2f | %s',
            $report->id, $client->name,
            $ctx->openingBalanceHours, (float) $ctx->contractedHours(),
            $ctx->actualHours, (float) $ctx->closingBalanceHours(), $composer->key()
        ));

        return self::SUCCESS;
    }
}
