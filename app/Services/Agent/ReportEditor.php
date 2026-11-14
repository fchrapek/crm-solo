<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Account;
use App\Models\ClientReport;
use App\Models\User;
use App\Services\Reports\ReportComposerRegistry;
use App\Services\Reports\ReportStatusTransition;
use App\Services\Reports\ReportTransitionResult;
use App\Services\Reports\ReportWriter;
use App\Services\Reports\ReportWriteResult;
use App\Support\LocalCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Report writes for the agent verbs and MCP tools, on the services the report page uses. Agents change the text and
 * figures of a draft only; a finalized or sent report has to be reopened first, which is its own step.
 */
final readonly class ReportEditor
{
    public const array STATUS_ACTIONS = ['finalize', 'sent', 'reopen'];

    private const array LOCALES = ['pl', 'en'];

    public function __construct(
        private ReportWriter $writer,
        private ReportStatusTransition $transitions,
        private MonthCloseReader $reader,
        private CrmEntityResolver $resolver,
        private ReportComposerRegistry $composers,
    ) {}

    /** @return array<string, mixed> */
    public function create(Account $account, string $client, ?string $period, ?string $composer, ?float $hours, string $locale): array
    {
        $client = $this->resolver->client($client, $account->id);
        $this->assertComposer($composer);
        $this->reader->assertBilling($account);
        try {
            $start = Carbon::instance(LocalCalendar::monthFrom($period ?? LocalCalendar::previousMonth(), (string) config('app.timezone')));
        } catch (InvalidArgumentException) {
            throw AgentRefusal::invalid('The period must be a month as YYYY-MM.');
        }

        $result = $this->withLocale($locale, fn (): ReportWriteResult => $this->writer->generate($client, $start, $start->copy()->endOfMonth(), $locale, $composer, $hours, $this->user()));
        if ($result->outcome === ReportWriteResult::EXISTS) {
            throw new AgentRefusal(AgentRefusal::REPORT_EXISTS, "This month already has report #{$result->report->id}; regenerate or edit that one.", ['report_id' => $result->report->id]);
        }

        return $this->written($account, $result);
    }

    /** @return array<string, mixed> */
    public function updateBody(Account $account, string $id, string $body, string $version): array
    {
        $report = $this->draft($account, $id);
        try {
            $result = $this->writer->updateBody($report, $body, $version, $this->user());
        } catch (ValidationException $e) {
            throw AgentRefusal::invalid(collect($e->errors())->flatten()->first() ?? $e->getMessage());
        }

        return $this->written($account, $result, $report);
    }

    /** @return array<string, mixed> */
    public function regenerate(Account $account, string $id, ?string $composer, bool $recalculate, string $locale): array
    {
        $report = $this->draft($account, $id);
        $this->assertComposer($composer);

        return $this->written($account, $this->withLocale($locale, fn (): ReportWriteResult => $this->writer->regenerate($report, $locale, $composer, $recalculate, $this->user())), $report);
    }

    /** @return array<string, mixed> */
    public function openingBalance(Account $account, string $id, ?float $hours): array
    {
        $report = $this->draft($account, $id);

        return $this->written($account, $this->writer->updateOpening($report, $hours, null, null, $this->user()), $report);
    }

    /** @return array<string, mixed> */
    public function status(Account $account, string $id, string $action): array
    {
        $report = $this->find($account, $id);
        $user = $this->user();
        $result = match ($action) {
            'finalize' => $this->transitions->finalize($report, $user),
            'sent' => $this->transitions->markSent($report, $user),
            'reopen' => $this->transitions->reopen($report, $user),
            default => throw AgentRefusal::invalid('The action is finalize, sent or reopen.'),
        };

        return $this->transitioned($account, $result);
    }

    private function assertComposer(?string $composer): void
    {
        $keys = array_keys($this->composers->all());
        if ($composer !== null && ! in_array($composer, $keys, true)) {
            throw AgentRefusal::invalid("Unknown composer \"{$composer}\". Valid: ".implode(', ', $keys).'.', ['composers' => $keys]);
        }
    }

    private function find(Account $account, string $id): ClientReport
    {
        $this->reader->reportById($account, $id);

        return ClientReport::query()->where('account_id', $account->id)->findOrFail((int) $id);
    }

    private function draft(Account $account, string $id): ClientReport
    {
        $report = $this->find($account, $id);
        if ($report->status !== ClientReport::STATUS_DRAFT) {
            throw new AgentRefusal(AgentRefusal::NOT_A_DRAFT, "Report #{$report->id} is {$report->status}; agents change a draft only. Reopen it first if the owner agrees.", ['report_id' => $report->id, 'status' => $report->status]);
        }

        return $report;
    }

    private function user(): ?User
    {
        return app(AgentCallContext::class)->identity()->user;
    }

    /** @return array<string, mixed> */
    private function written(Account $account, ReportWriteResult $result, ?ClientReport $report = null): array
    {
        if ($result->isRefused()) {
            throw new AgentRefusal(AgentRefusal::REFUSED, $result->message, ['report_id' => $result->report?->id ?? $report?->id]);
        }
        $report = $result->report ?? $report;

        return [
            'outcome' => $result->outcome,
            'message' => $result->message,
            'report' => $this->reader->reportById($account, (string) $report->id),
        ];
    }

    /** @return array<string, mixed> */
    private function transitioned(Account $account, ReportTransitionResult $result): array
    {
        if ($result->refused()) {
            throw new AgentRefusal(AgentRefusal::REFUSED, $result->message, ['report_id' => $result->report->id]);
        }

        return [
            'outcome' => $result->outcome->value,
            'message' => $result->message,
            'report' => $this->reader->reportById($account, (string) $result->report->id),
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function withLocale(string $locale, callable $write): mixed
    {
        if (! in_array($locale, self::LOCALES, true)) {
            throw AgentRefusal::invalid('The locale is pl or en.');
        }
        $previous = App::getLocale();
        App::setLocale($locale);
        try {
            return $write();
        } finally {
            App::setLocale($previous);
        }
    }
}
