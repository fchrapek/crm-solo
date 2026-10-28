<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\TimeEntry;
use App\Support\LegalName;
use App\Support\LocalCalendar;
use Illuminate\Database\Eloquent\Builder;

final class ClientListQuery
{
    public const int PER_PAGE = 10;

    public const string ARCHIVED = 'archived';

    /** The cooperation filter for clients with no type, so Wszyscy is the sum of the rows below it. */
    public const string NO_TYPE = 'none';

    public const array STAGES = [...Client::LIFECYCLE_STAGES, self::ARCHIVED];

    public function __construct(private readonly HoursBank $bank) {}

    /**
     * Each facet counts with the other facet applied, never the search, so the tabs and the menu describe the book.
     *
     * @param  array<string, mixed>  $input  stage, cooperation_type, search from the query string
     * @return array<string, mixed>
     */
    public function props(int $accountId, array $input, ?int $userId = null): array
    {
        $text = fn (string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
        $cards = app(ClientCardFilters::class);
        $card = $cards->uses($accountId) ? $cards->parse($accountId, $input, $userId) : null;
        $stage = in_array($text('stage'), self::STAGES, true) ? $text('stage') : 'active';
        $cooperation = in_array($text('cooperation_type'), [...Client::COOPERATION_TYPES, self::NO_TYPE], true) ? $text('cooperation_type') : null;
        $search = mb_trim($text('search'));
        $period = LocalCalendar::currentMonth();
        [$from, $to] = LocalCalendar::monthRange($period);

        $page = $this->inStage($accountId, $stage)
            ->when($cooperation !== null, fn (Builder $query) => $this->whereCooperation($query, $cooperation))
            ->when($search !== '', fn (Builder $query) => $query->filter(['search' => $search], $accountId))
            ->when($card !== null, fn (Builder $query) => $cards->apply($query, $card))
            ->select(['id', 'name', 'lifecycle_stage', 'cooperation_type', 'is_pinned', 'deleted_at', 'custom_fields', 'assigned_user_id'])
            ->withExists(['timeEntries as agent_live' => fn (Builder $query) => $query->whereNull('end_time')->whereNotNull('actor_via')])
            ->addSelect(['month_minutes' => TimeEntry::query()
                ->selectRaw('COALESCE(SUM(duration_minutes), 0)')
                ->whereColumn('time_entries.client_id', 'clients.id')
                ->where('start_time', '>=', $from)
                ->where('start_time', '<', $to)])
            ->orderByDesc('is_pinned')
            ->orderByDesc('agent_live')
            ->orderByDesc('month_minutes')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Every non-archived client's settlement feeds the note; archived rows on the page get theirs in one more batch.
        $book = $this->bank->settlements(Client::query()->where('account_id', $accountId)->pluck('id')->map(intval(...))->all());
        $pageIds = collect($page->items())->map(fn (Client $client): int => $client->id)->all();
        $states = $book + $this->bank->settlements(array_values(array_diff($pageIds, array_keys($book))));
        $active = collect($pageIds)->mapWithKeys(fn (int $id): array => [$id => $states[$id]->activePeriod])->all();
        $months = $this->bank->months($active);
        // A billing-off account owes no month close.
        $behind = collect($card !== null ? [] : $book)->filter(fn (ClientSettlementState $state): bool => $state->openBehind());
        $behindPeriods = $behind->map(fn (ClientSettlementState $state): string => $state->activePeriod)->unique()->values();

        return [
            'filters' => ['stage' => $stage, 'cooperation_type' => $cooperation, 'search' => $search],
            'period' => $period,
            'open_months' => [
                'count' => $behind->count(),
                // Named only when every client behind owes the same month.
                'period' => $behindPeriods->count() === 1 ? $behindPeriods->first() : null,
                // Where the note links: the oldest month still owed.
                'oldest' => $behindPeriods->min(),
            ],
            'stage_counts' => $this->stageCounts($accountId, $cooperation),
            'cooperation_counts' => $this->cooperationCounts($accountId, $stage),
            'clients' => $page->through(fn (Client $client): array => [
                ...$this->row($client, $months[$client->id], $states[$client->id]),
                ...($card !== null ? $cards->row($client, $card) : []),
            ]),
            'card' => $card === null ? null : $cards->props($accountId, $card, $this->inStage($accountId, $stage)
                ->when($cooperation !== null, fn (Builder $query) => $this->whereCooperation($query, $cooperation))),
        ];
    }

    /** @return array{id: int, name: string, short_name: string, lifecycle_stage: string, cooperation_type: ?string, is_archived: bool, is_pinned: bool, agent_live: bool, hours: array{period: string, behind: bool, has_retainer: bool, used: float, available: float, remaining: float, logged: float}} */
    private function row(Client $client, HoursBankMonth $month, ClientSettlementState $state): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'short_name' => LegalName::short($client->name),
            'lifecycle_stage' => $client->lifecycle_stage,
            'cooperation_type' => $client->cooperation_type,
            'is_archived' => $client->deleted_at !== null,
            'is_pinned' => $client->is_pinned,
            'agent_live' => (bool) $client->agent_live,
            'hours' => [
                'period' => $state->activePeriod,
                'behind' => $state->openBehind(),
                'has_retainer' => $month->hasRetainer(),
                'used' => $month->usedHours,
                'available' => $month->availableHours(),
                'remaining' => $month->closingBalanceHours(),
                'logged' => $month->loggedHours,
            ],
        ];
    }

    /** @return Builder<Client> */
    private function inStage(int $accountId, string $stage): Builder
    {
        $query = Client::query()->where('account_id', $accountId);

        return $stage === self::ARCHIVED ? $query->onlyTrashed() : $query->where('lifecycle_stage', $stage);
    }

    /** @return array<string, int> */
    private function stageCounts(int $accountId, ?string $cooperation): array
    {
        $bucket = "CASE WHEN deleted_at IS NULL THEN lifecycle_stage ELSE '".self::ARCHIVED."' END";
        $counts = Client::withTrashed()
            ->where('account_id', $accountId)
            ->when($cooperation !== null, fn (Builder $query) => $this->whereCooperation($query, $cooperation))
            ->selectRaw("{$bucket} as bucket, COUNT(*) as aggregate")
            ->groupByRaw($bucket)
            ->pluck('aggregate', 'bucket');

        return collect(self::STAGES)->mapWithKeys(fn (string $stage): array => [$stage => (int) ($counts[$stage] ?? 0)])->all();
    }

    /** @return array<string, int> */
    private function cooperationCounts(int $accountId, string $stage): array
    {
        $counts = $this->inStage($accountId, $stage)
            ->selectRaw('cooperation_type, COUNT(*) as aggregate')
            ->groupBy('cooperation_type')
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->cooperation_type => (int) $row->aggregate]);

        return [
            'all' => (int) $counts->sum(),
            ...collect(Client::COOPERATION_TYPES)->mapWithKeys(fn (string $type): array => [$type => $counts[$type] ?? 0])->all(),
            self::NO_TYPE => $counts[''] ?? 0,
        ];
    }

    /**
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    private function whereCooperation(Builder $query, string $cooperation): Builder
    {
        return $cooperation === self::NO_TYPE ? $query->whereNull('cooperation_type') : $query->where('cooperation_type', $cooperation);
    }
}
