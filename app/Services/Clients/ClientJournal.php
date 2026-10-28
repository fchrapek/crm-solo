<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The client's journal rows: one per agent session, else one per time entry or journal event.
 *
 * Grouping happens in SQL, so a page of the work list reads only its own rows however long the history is.
 */
final class ClientJournal
{
    private const string SESSION = "actor_session_id IS NOT NULL AND actor_session_id <> ''";

    /**
     * Rows with anything written at or after the instant, newest first; a row holds only what falls in that window.
     *
     * @return Collection<int, array{key: string, kind: 'session'|'entry'|'event', at: CarbonImmutable, session: string|null, entry_ids: list<int>, event_ids: list<int>}>
     */
    public function rowsSince(Client $client, CarbonInterface $since): Collection
    {
        $since = $this->utc($since);

        return $this->withMembers($client, $this->groups($client, true, $since)->get(), $since);
    }

    /** How many work-list rows have anything at or after the instant. */
    public function workCountSince(Client $client, CarbonInterface $since): int
    {
        return DB::query()->fromSub($this->groups($client, false, $this->utc($since)), 'g')->count();
    }

    /**
     * One page of the work list (time entries and agent sessions with what a session wrote to the journal), newest
     * first, strictly older than the cursor. A page never splits rows that share their newest instant, so the next
     * page starts strictly before the last one shown.
     *
     * @return array{rows: Collection<int, array{key: string, kind: 'session'|'entry'|'event', at: CarbonImmutable, session: string|null, entry_ids: list<int>, event_ids: list<int>}>, next: CarbonImmutable|null}
     */
    public function workPage(Client $client, ?CarbonInterface $before, int $size): array
    {
        $before = $before !== null ? $this->utc($before) : null;
        $groups = $this->groups($client, false, null, $before)->limit($size + 1)->get();

        if ($groups->count() <= $size) {
            return ['rows' => $this->withMembers($client, $groups), 'next' => null];
        }

        $boundary = $this->at($groups[$size - 1]->at);
        $page = $groups->take($size)
            ->filter(fn (object $group): bool => $this->at($group->at)->greaterThan($boundary))
            ->concat($this->groups($client, false, null, $before)->havingRaw('MAX(at) = ?', [$this->sql($boundary)])->get());
        $more = $this->groups($client, false, null, $boundary)->limit(1)->exists();

        return ['rows' => $this->withMembers($client, $page->values()), 'next' => $more ? $boundary : null];
    }

    /** Groups as (gkind, gsession, gid, at = newest member), newest first. */
    private function groups(Client $client, bool $allEvents, ?CarbonImmutable $since = null, ?CarbonImmutable $before = null): Builder
    {
        $session = self::SESSION;
        // Session ids are case-sensitive; MariaDB's default collation would fold `Run-A` and `run-a` into one group.
        $key = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'CAST(actor_session_id AS BINARY)' : 'actor_session_id';
        $select = fn (string $kind, string $at): string => "CASE WHEN {$session} THEN 'session' ELSE '{$kind}' END AS gkind,"
            ." CASE WHEN {$session} THEN {$key} END AS gsession,"
            ." CASE WHEN {$session} THEN NULL ELSE id END AS gid, {$at} AS at";

        $entries = DB::table('time_entries')
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->id)
            ->when($since, fn (Builder $q) => $q->where('start_time', '>=', $this->sql($since)))
            ->selectRaw($select('entry', 'start_time'));
        $events = DB::table('client_lifecycle_events')
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->id)
            ->when(! $allEvents, fn (Builder $q) => $q->whereRaw($session))
            ->when($since, fn (Builder $q) => $q->where('created_at', '>=', $this->sql($since)))
            ->selectRaw($select('event', 'created_at'));

        return DB::query()
            ->fromSub($entries->unionAll($events), 'm')
            ->select(['gkind', 'gsession', 'gid'])
            ->selectRaw('MAX(at) AS at')
            ->groupBy('gkind', 'gsession', 'gid')
            ->when($before, fn (Builder $q) => $q->havingRaw('MAX(at) < ?', [$this->sql($before)]))
            ->orderByDesc('at')
            ->orderBy('gkind')
            ->orderBy('gsession')
            ->orderByDesc('gid');
    }

    /**
     * @param  Collection<int, object>  $groups
     * @return Collection<int, array{key: string, kind: 'session'|'entry'|'event', at: CarbonImmutable, session: string|null, entry_ids: list<int>, event_ids: list<int>}>
     */
    private function withMembers(Client $client, Collection $groups, ?CarbonImmutable $since = null): Collection
    {
        $sessions = $groups->where('gkind', 'session')->pluck('gsession')->values();
        $member = fn (string $table, string $at) => DB::table($table)
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->id)
            ->whereIn('actor_session_id', $sessions)
            ->when($since, fn (Builder $q) => $q->where($at, '>=', $this->sql($since)))
            ->orderByDesc($at)
            ->orderByDesc('id')
            ->get(['id', 'actor_session_id'])
            ->groupBy('actor_session_id')
            ->map(fn (Collection $rows): array => $rows->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $sessionEntries = $sessions->isEmpty() ? collect() : $member('time_entries', 'start_time');
        $sessionEvents = $sessions->isEmpty() ? collect() : $member('client_lifecycle_events', 'created_at');

        return $groups->map(function (object $group) use ($sessionEntries, $sessionEvents): array {
            $at = $this->at($group->at);

            if ($group->gkind === 'session') {
                $session = (string) $group->gsession;

                return ['key' => "session:{$session}", 'kind' => 'session', 'at' => $at, 'session' => $session, 'entry_ids' => $sessionEntries[$session] ?? [], 'event_ids' => $sessionEvents[$session] ?? []];
            }

            $id = (int) $group->gid;

            return [
                'key' => "{$group->gkind}:{$id}",
                'kind' => $group->gkind === 'entry' ? 'entry' : 'event',
                'at' => $at,
                'session' => null,
                'entry_ids' => $group->gkind === 'entry' ? [$id] : [],
                'event_ids' => $group->gkind === 'event' ? [$id] : [],
            ];
        })->values();
    }

    private function utc(CarbonInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone('UTC');
    }

    private function at(mixed $value): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $value, 'UTC');
    }

    private function sql(CarbonImmutable $instant): string
    {
        return $instant->format('Y-m-d H:i:s');
    }
}
