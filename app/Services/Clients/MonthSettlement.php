<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\ClientMonthClosure;
use App\Models\ClientMonthSettlementEvent;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\HoursText;
use App\Support\LegalName;
use App\Support\LocalCalendar;
use Carbon\CarbonInterface;

/** "Zamknij miesiąc": freezes a client's month in the hours bank, and reopens the latest one; every write is one event. */
final class MonthSettlement
{
    public function __construct(
        private readonly ClientFinancialLock $lock,
        private readonly HoursBank $bank,
        private readonly SettlementPolicy $policy,
    ) {}

    /**
     * Snapshots carry this, so a close confirms exactly the figures the owner saw.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function hash(array $snapshot): string
    {
        return sha1((string) json_encode(array_map(
            fn (mixed $value): mixed => is_float($value) ? round($value, 2) : $value,
            [
                $snapshot['period'],
                $snapshot['source'],
                $snapshot['report_id'],
                $snapshot['contracted'],
                $snapshot['opening'],
                $snapshot['cap'],
                $snapshot['used'],
            ],
        )));
    }

    /**
     * What closing $period would freeze, read now, with its hash; refusal says why it cannot close, without a lock.
     *
     * @return array{snapshot: array<string, mixed>, hash: string, refusal: ?string}
     */
    public function preview(Client $client, string $period, ?CarbonInterface $now = null): array
    {
        $snapshot = $this->snapshot($client, $period);

        return [
            'snapshot' => $snapshot,
            'hash' => self::hash($snapshot),
            'refusal' => ClientMonthClosure::query()->where('client_id', $client->id)->where('period', $period)->exists()
                ? self::alreadyClosed($period)
                : $this->refusal($client, $period, $now),
        ];
    }

    /**
     * What the confirm dialog shows: the figures a close would freeze now, the hash it posts back, and why it cannot close.
     *
     * @return array<string, mixed>
     */
    public function previewFigures(Client $client, string $period): array
    {
        $preview = $this->preview($client, $period);
        $snapshot = $preview['snapshot'];

        return [
            'client' => LegalName::short($client->name),
            'period' => $period,
            'next' => LocalCalendar::nextMonth($period),
            'contracted' => $snapshot['contracted'],
            'opening' => $snapshot['opening'],
            'cap' => $snapshot['cap'],
            'forfeited' => $snapshot['forfeited'],
            'used' => $snapshot['used'],
            'closing' => $snapshot['closing'],
            'source' => $snapshot['source'],
            'report_status' => $snapshot['report_status'],
            'entries' => $snapshot['entries'],
            'hash' => $preview['hash'],
            'refusal' => $preview['refusal'],
        ];
    }

    public function close(Client $client, string $period, string $previewHash, ?User $by = null, ?CarbonInterface $now = null): SettlementResult
    {
        return $this->lock->client($client->id, function (Client $client) use ($period, $previewHash, $by, $now): SettlementResult {
            if ($this->policy->closureFor($client, $period) !== null) {
                return SettlementResult::unchanged(self::alreadyClosed($period));
            }

            $refusal = $this->refusal($client, $period, $now);
            if ($refusal !== null) {
                return SettlementResult::refused($refusal);
            }

            $snapshot = $this->snapshot($client, $period);
            if (! hash_equals(self::hash($snapshot), $previewHash)) {
                return SettlementResult::refused(__('The figures changed, check them again.'));
            }

            $closure = ClientMonthClosure::create([
                'client_id' => $client->id,
                'period' => $period,
                'closed_at' => now(),
                'closed_by_user_id' => $by?->id,
                'snapshot' => $snapshot,
            ]);
            $this->record($client, $period, ClientMonthSettlementEvent::ACTION_CLOSE, $closure, $by);
            $flagged = $this->policy->flagLaterDrafts($client, SettlementPolicy::monthDates($period)[1]);

            return new SettlementResult(SettlementOutcome::Applied, self::withFlagged(__(':Month closed. Balance :balance carries into :next.', [
                'month' => LocalCalendar::monthName($period),
                'balance' => HoursText::signed((float) $snapshot['closing']),
                'next' => LocalCalendar::monthName(LocalCalendar::nextMonth($period)),
            ]), $flagged), $closure, [
                'action' => 'closed',
                'period' => $period,
                'closing' => (float) $snapshot['closing'],
                'next' => LocalCalendar::nextMonth($period),
                'recalculate' => $flagged[0] ?? null,
            ]);
        });
    }

    /** Reopens the closure the owner saw, by id, so a late retry never reopens a newer close of the same month. */
    public function reopen(Client $client, string $period, int $closureId, ?User $by = null): SettlementResult
    {
        return $this->lock->client($client->id, function (Client $client) use ($period, $closureId, $by): SettlementResult {
            $closure = ClientMonthClosure::query()->where('client_id', $client->id)->whereKey($closureId)->first();
            if ($closure === null || $closure->period !== $period) {
                return SettlementResult::unchanged(__(':Month is already open.', ['month' => LocalCalendar::monthName($period)]));
            }

            $latest = ClientMonthClosure::query()->where('client_id', $client->id)->orderByDesc('period')->first();
            if ($latest !== null && $latest->id !== $closure->id) {
                return SettlementResult::refused(__('Reopen :month first: only the latest closed month reopens.', ['month' => LocalCalendar::monthName($latest->period)]));
            }

            $refusal = $this->policy->laterReportRefusal($client, SettlementPolicy::monthDates($period)[1]);
            if ($refusal !== null) {
                return SettlementResult::refused($refusal);
            }

            $this->record($client, $period, ClientMonthSettlementEvent::ACTION_REOPEN, $closure, $by);
            $closure->delete();
            $flagged = $this->policy->flagLaterDrafts($client, SettlementPolicy::monthDates($period)[1]);

            return new SettlementResult(SettlementOutcome::Applied, self::withFlagged(
                __(':Month is open again.', ['month' => LocalCalendar::monthName($period)]),
                $flagged,
            ), toast: ['action' => 'reopened', 'period' => $period, 'recalculate' => $flagged[0] ?? null]);
        });
    }

    private static function alreadyClosed(string $period): string
    {
        return __(':Month is already closed.', ['month' => LocalCalendar::monthName($period)]);
    }

    /** @param  list<string>  $flagged */
    private static function withFlagged(string $message, array $flagged): string
    {
        return $flagged === []
            ? $message
            : $message.' '.__('Recalculate the report for :month.', ['month' => LocalCalendar::monthName($flagged[0])]);
    }

    /** Why $period cannot close now; the duplicate close is answered before this. */
    private function refusal(Client $client, string $period, ?CarbonInterface $now): ?string
    {
        $state = $this->bank->settlement($client, $now);
        if ($period >= $state->currentPeriod) {
            return __('The month in progress cannot be closed.');
        }
        if (! $state->isEligible($period)) {
            return __(':Month has no retainer to settle.', ['month' => LocalCalendar::monthName($period)]);
        }
        $later = collect($state->closures)->keys()->filter(fn (string $closed): bool => $closed > $period)->max();
        if ($later !== null) {
            return __('Reopen :month first: a later month is already closed.', ['month' => LocalCalendar::monthName($later)]);
        }
        if ($period !== $state->activePeriod) {
            return __('Close :month first.', ['month' => LocalCalendar::monthName($state->activePeriod)]);
        }

        [$from, $to] = LocalCalendar::monthRange($period);
        $running = TimeEntry::query()
            ->where('client_id', $client->id)
            ->whereNull('end_time')
            ->where('start_time', '>=', $from)
            ->where('start_time', '<', $to)
            ->exists();
        if ($running) {
            return __('Stop the timer running for :month first.', ['month' => LocalCalendar::monthName($period)]);
        }

        return $this->policy->earlierFlaggedRefusal($client, SettlementPolicy::monthDates(LocalCalendar::nextMonth($period))[0])
            ?? $this->policy->laterReportRefusal($client, SettlementPolicy::monthDates($period)[1]);
    }

    /**
     * The month's figures as they stand: a governing whole-month report's, whatever its status, else live reportable time.
     * Hours are rounded to 2 places; null means not applicable.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Client $client, string $period): array
    {
        $month = $this->bank->month($client, $period);
        $contracted = $month->contractedHours;
        $cap = $month->rolloverCapHours;
        if ($contracted === null && $month->reportId === null) {
            // A reopened month whose position changed since keeps the terms it was settled on.
            $last = ClientMonthSettlementEvent::query()->where('client_id', $client->id)->where('period', $period)->latest('id')->first();
            $contracted = isset($last?->snapshot['contracted']) ? (float) $last->snapshot['contracted'] : null;
            $cap = isset($last?->snapshot['cap']) ? (float) $last->snapshot['cap'] : $cap;
        }

        [$from, $to] = LocalCalendar::monthRange($period);
        $entries = round((int) TimeEntry::query()
            ->where('client_id', $client->id)
            ->where('start_time', '>=', $from)
            ->where('start_time', '<', $to)
            ->reportable()
            ->sum('duration_minutes') / 60, 2);

        $opening = round($month->openingBalanceHours, 2);
        $used = round($month->usedHours, 2);
        $available = HoursBank::available($opening, $contracted ?? 0.0, $cap);
        $fromReport = $month->source === HoursBankMonth::SOURCE_REPORT;

        return [
            'schema_version' => ClientMonthClosure::SNAPSHOT_SCHEMA_VERSION,
            'period' => $period,
            'eligible' => true,
            'source' => $fromReport ? HoursBankMonth::SOURCE_REPORT : HoursBankMonth::SOURCE_ENTRIES,
            'report_id' => $fromReport ? $month->reportId : null,
            'report_status' => $fromReport ? $month->reportStatus : null,
            'contracted' => $contracted !== null ? round($contracted, 2) : null,
            'opening' => $opening,
            'cap' => $cap !== null ? round($cap, 2) : null,
            'available' => $available,
            'used' => $used,
            'forfeited' => HoursBank::forfeited($opening, $contracted ?? 0.0, $cap),
            'closing' => $month->outsideBank ? $opening : HoursBank::closing($available, $used),
            'entries' => $entries,
            'logged' => round($month->loggedHours, 2),
            ...($month->outsideBank ? ['outside_bank' => true] : []),
        ];
    }

    private function record(Client $client, string $period, string $action, ClientMonthClosure $closure, ?User $by): void
    {
        ClientMonthSettlementEvent::create([
            'client_id' => $client->id,
            'period' => $period,
            'action' => $action,
            'closure_id' => $closure->id,
            'snapshot' => $closure->snapshot,
            'actor_user_id' => $by?->id,
            'created_at' => now(),
        ]);
    }
}
