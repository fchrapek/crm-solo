<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Models\ClientReport;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use App\Services\Reports\ReportWriter;
use App\Services\Reports\ReportWriteResult;
use App\Support\LocalCalendar;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use InvalidArgumentException;

/**
 * The CLI way into ReportWriter::generate, the same write the web's Generate
 * makes. The --hours override exists because billing rounding lives on the report
 * snapshot, never on the time entries: pass the rounded figure and the body and
 * the stored actual_hours agree.
 */
#[AccountScope(AccountScope::ACTING)]
final class GenerateMonthReport extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'reports:generate
        {client : Client id}
        {--period= : Billed month YYYY-MM (default: previous month)}
        {--hours= : Override actual_hours (rounded billing figure)}
        {--composer= : Composer key}
        {--locale=pl}';

    protected $description = 'Generate a draft client report for a period.';

    public function handle(ReportWriter $writer): int
    {
        $accountId = $this->actingAccountId();
        if ($accountId === null) {
            return self::FAILURE;
        }

        try {
            $client = app(CrmEntityResolver::class)->client((string) $this->argument('client'), $accountId);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        }

        // The same default as month-close:tick and infakt:draft-invoice: a close bills the month just ended.
        try {
            $start = Carbon::instance(LocalCalendar::monthFrom((string) ($this->option('period') ?? LocalCalendar::previousMonth()), (string) config('app.timezone')));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $period = $start->format('Y-m');
        $end = $start->copy()->endOfMonth();

        if ($this->refuseExisting($client->id, $start, $end, $period)) {
            return self::FAILURE;
        }

        App::setLocale($this->option('locale'));

        $result = $writer->generate(
            $client,
            $start,
            $end,
            App::getLocale(),
            $this->option('composer') ?: null,
            $this->option('hours') !== null ? (float) $this->option('hours') : null,
        );

        if ($result->outcome === ReportWriteResult::EXISTS) {
            $this->refuseExisting($client->id, $start, $end, $period);

            return self::FAILURE;
        }
        if ($result->isRefused()) {
            $this->error($result->message);

            return self::FAILURE;
        }

        $report = $result->report;
        $this->line(sprintf(
            '#%d %s [%s] | opening %.2f + contracted %.2f - actual %.2f = closing %.2f | %s',
            $report->id, $client->name, $period,
            (float) $report->opening_balance_hours, (float) $report->contracted_hours,
            (float) $report->actual_hours, $report->closingBalanceHours(), $report->composer_key
        ));

        return self::SUCCESS;
    }

    /** A second report for the month would split its hours bank; the existing one is regenerated in the app instead. */
    private function refuseExisting(int $clientId, Carbon $start, Carbon $end, string $period): bool
    {
        $existing = ClientReport::coveringPeriod($clientId, $start->toDateString(), $end->toDateString());
        if ($existing === null) {
            return false;
        }

        $this->error(sprintf(
            'Report #%d for [%s] already exists (%s); nothing was generated. Regenerate it in the app if its figures are out of date.',
            $existing->id, $period, $existing->status,
        ));

        return true;
    }
}
