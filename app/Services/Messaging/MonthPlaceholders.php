<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\Client;
use App\Services\Clients\HoursBank;
use App\Services\Reports\BillingSummary;
use App\Services\Reports\ReportDataAggregator;
use App\Support\LocalCalendar;
use Illuminate\Support\Carbon;

/** The email placeholders about one client month, worded as the month's report words them. Polish only. */
final class MonthPlaceholders
{
    public const array TOKENS = ['period', 'work_list', 'hours_summary'];

    /** Several lines each, so never in a subject. */
    public const array MULTILINE = ['work_list', 'hours_summary'];

    /** Fills with nothing rather than stopping the draft: a client without a retainer has no hours to sum up. */
    public const array MAY_BE_EMPTY = ['hours_summary'];

    private const string LOCALE = 'pl';

    public function __construct(
        private readonly ReportDataAggregator $aggregator,
        private readonly HoursBank $hoursBank,
    ) {}

    public function value(string $token, Client $client, string $period): ?string
    {
        return match ($token) {
            'period' => $this->period($period),
            'work_list' => $this->workList($client, $period),
            'hours_summary' => $this->hoursSummary($client, $period),
            default => null,
        };
    }

    public function period(string $period): string
    {
        return LocalCalendar::monthFrom($period)->locale(self::LOCALE)->translatedFormat('F Y');
    }

    /** The month's reportable tasks the report lists, the finished ones only, one item each. */
    public function workList(Client $client, string $period): ?string
    {
        $start = Carbon::instance(LocalCalendar::monthFrom($period, (string) config('app.timezone')));
        $tasks = $this->aggregator->aggregate($client, $start, $start->copy()->endOfMonth(), 'month', self::LOCALE)->tasks
            ->filter(fn (array $task): bool => $task['completed_at'] !== null)
            ->map(fn (array $task): string => '- '.mb_rtrim(mb_trim((string) $task['name']), '.'));

        return $tasks->isEmpty() ? null : $tasks->implode("\n");
    }

    /** The billing summary's balance lines without its heading; empty without a retainer. */
    public function hoursSummary(Client $client, string $period): string
    {
        $month = $this->hoursBank->month($client, $period);
        if ($month->contractedHours === null) {
            return '';
        }

        $lines = array_slice(BillingSummary::lines($month->openingBalanceHours, $month->contractedHours, $month->usedHours, $month->rolloverCapHours, self::LOCALE), 1);

        return mb_trim(implode("\n", $lines));
    }
}
