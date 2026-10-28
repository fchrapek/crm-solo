<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Account;
use App\Models\ClientReport;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Lead;
use App\Services\Agent\ClientDirectory;
use App\Services\Agent\TaskList;
use Illuminate\Database\Eloquent\Builder;

/**
 * ⌘K, `crm search` and the MCP `search` tool: one query over everything the owner can open, grouped by type.
 * Clients and tasks go through the agent directories the `clients` and `tasks` verbs use; archived clients,
 * done and archived tasks, deleted contacts and leads, and the reports and invoices of archived clients stay
 * out unless asked for.
 */
final class GlobalSearch
{
    public const array GROUPS = ['clients', 'tasks', 'contacts', 'leads', 'reports', 'invoices'];

    public const int PER_GROUP = 5;

    public const int MORE = 20;

    public const int MIN_LENGTH = 2;

    public function __construct(
        private readonly ClientDirectory $clients,
        private readonly TaskList $tasks,
    ) {}

    /**
     * @return array{query: string, archived: bool, groups: list<array{group: string, total: int, items: list<array<string, mixed>>}>}
     */
    public function search(int $accountId, string $query, bool $archived = false, ?string $group = null): array
    {
        $query = mb_trim($query);
        $groups = $group !== null && in_array($group, self::GROUPS, true) ? [$group] : self::GROUPS;
        if (! Account::query()->findOrFail($accountId)->hasFeature('billing')) {
            $groups = array_values(array_diff($groups, ['reports', 'invoices']));
        }
        $limit = count($groups) === 1 ? self::MORE : self::PER_GROUP;

        if (mb_strlen($query) < self::MIN_LENGTH) {
            return ['query' => $query, 'archived' => $archived, 'groups' => []];
        }

        $results = [];
        foreach ($groups as $name) {
            $found = match ($name) {
                'clients' => $this->clients($accountId, $query, $archived, $limit),
                'tasks' => $this->tasks($accountId, $query, $archived, $limit),
                'contacts' => $this->contacts($accountId, $query, $archived, $limit),
                'leads' => $this->leads($accountId, $query, $archived, $limit),
                'reports' => $this->reports($accountId, $query, $archived, $limit),
                'invoices' => $this->invoices($accountId, $query, $archived, $limit),
            };
            if ($found['total'] > 0) {
                $results[] = ['group' => $name, ...$found];
            }
        }

        return ['query' => $query, 'archived' => $archived, 'groups' => $results];
    }

    /** @return array{total: int, items: list<array<string, mixed>>} */
    private function clients(int $accountId, string $query, bool $archived, int $limit): array
    {
        $result = $this->clients->list($accountId, ['search' => $query, 'archived' => $archived, 'limit' => $limit]);

        return [
            'total' => $result['total'],
            'items' => array_map(fn (array $client): array => $this->item(
                id: $client['id'],
                title: $client['name'],
                client: null,
                url: route('clients.show', [$client['id'], 'przeglad'], false),
                archived: $client['archived'],
                detail: ['type' => $client['type'], 'stage' => $client['stage']],
            ), $result['clients']),
        ];
    }

    /** @return array{total: int, items: list<array<string, mixed>>} */
    private function tasks(int $accountId, string $query, bool $archived, int $limit): array
    {
        $result = $this->tasks->list($accountId, ['search' => $query, 'status' => $archived ? 'all' : 'open', 'limit' => $limit]);

        return [
            'total' => $result['total'],
            'items' => array_map(fn (array $task): array => $this->item(
                id: $task['id'],
                title: $task['name'],
                client: $task['client'],
                url: route('tasks.show', $task['id'], false),
                archived: $task['done'] || $task['archived'],
                detail: ['done' => $task['done']],
                untrusted: array_values(array_intersect($task['untrusted'], ['name', 'project'])) !== [] ? ['title'] : [],
            ), $result['tasks']),
        ];
    }

    /** @return array{total: int, items: list<array<string, mixed>>} */
    private function contacts(int $accountId, string $query, bool $archived, int $limit): array
    {
        $found = Contact::query()
            ->where('account_id', $accountId)
            ->filter(['search' => $query, 'trashed' => $archived ? 'with' : null], $accountId)
            ->with('client:id,name');

        return $this->page($found->clone()->count(), $found->orderBy('last_name')->orderBy('first_name')->limit($limit)->get(), fn (Contact $contact): array => $this->item(
            id: $contact->id,
            title: mb_trim($contact->first_name.' '.$contact->last_name),
            client: $contact->client?->name,
            url: route('contacts.index', ['kontakt' => $contact->id], false),
            archived: $contact->trashed(),
            detail: ['email' => $contact->emails[0] ?? null],
        ));
    }

    /** @return array{total: int, items: list<array<string, mixed>>} */
    private function leads(int $accountId, string $query, bool $archived, int $limit): array
    {
        $found = Lead::query()
            ->where('account_id', $accountId)
            ->filter(['search' => $query, 'trashed' => $archived ? 'with' : null]);

        return $this->page($found->clone()->count(), $found->orderByDesc('captured_at')->limit($limit)->get(), fn (Lead $lead): array => $this->item(
            id: $lead->id,
            title: $lead->name,
            client: $lead->company,
            url: route('leads.edit', $lead->id, false),
            archived: $lead->trashed(),
            detail: ['stage' => $lead->stage, 'pipeline' => $lead->pipeline],
            untrusted: ['title', 'client'],
        ));
    }

    /** @return array{total: int, items: list<array<string, mixed>>} */
    private function reports(int $accountId, string $query, bool $archived, int $limit): array
    {
        $found = ClientReport::query()
            ->where('account_id', $accountId)
            ->whereHas('client', fn (Builder $client) => $this->clientNamed($client, $query, $archived))
            ->with(['client' => fn ($client) => $client->withTrashed()->select('id', 'name')]);

        return $this->page($found->clone()->count(), $found->orderByDesc('period_start')->orderByDesc('id')->limit($limit)->get(), fn (ClientReport $report): array => $this->item(
            id: $report->id,
            title: $report->period_start->format('Y-m'),
            client: $report->client?->name,
            url: route('client-reports.edit', [$report->client_id, $report->id], false),
            archived: $report->client?->trashed() ?? false,
            detail: ['period' => $report->period_start->format('Y-m'), 'status' => $report->status],
        ));
    }

    /** @return array{total: int, items: list<array<string, mixed>>} */
    private function invoices(int $accountId, string $query, bool $archived, int $limit): array
    {
        $like = $this->like($query);
        $found = Invoice::query()
            ->where('account_id', $accountId)
            ->where(fn (Builder $q) => $q
                ->where('number', 'like', $like)
                ->orWhere('client_company_name', 'like', $like)
                ->orWhereHas('client', fn (Builder $client) => $this->clientNamed($client, $query, $archived)))
            ->when(! $archived, fn (Builder $q) => $q->where(fn (Builder $live) => $live->whereNull('client_id')->orWhereHas('client')))
            ->with(['client' => fn ($client) => $client->withTrashed()->select('id', 'name')]);

        return $this->page($found->clone()->count(), $found->orderByDesc('invoice_date')->orderByDesc('id')->limit($limit)->get(), fn (Invoice $invoice): array => $this->item(
            id: $invoice->id,
            title: (string) $invoice->number,
            client: $invoice->client?->name ?? $invoice->client_company_name,
            url: $invoice->client_id !== null ? route('clients.show', [$invoice->client_id, 'rozliczenia'], false).'#faktura-'.$invoice->id : null,
            archived: $invoice->client?->trashed() ?? false,
            detail: [
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'net' => $invoice->net_price !== null ? (float) ($invoice->net_price / 100) : null,
                'currency' => $invoice->currency,
            ],
            untrusted: $invoice->client === null ? ['client'] : [],
        ));
    }

    private function clientNamed(Builder $client, string $query, bool $archived): void
    {
        $client->where('name', 'like', $this->like($query))->when($archived, fn (Builder $q) => $q->withTrashed());
    }

    private function like(string $query): string
    {
        return '%'.addcslashes($query, '\\%_').'%';
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Support\Collection<int, T>  $rows
     * @param  callable(T): array<string, mixed>  $map
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function page(int $total, $rows, callable $map): array
    {
        return ['total' => $total, 'items' => $rows->map($map)->values()->all()];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @param  list<string>  $untrusted
     * @return array<string, mixed>
     */
    private function item(int $id, string $title, ?string $client, ?string $url, bool $archived, array $detail, array $untrusted = []): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'client' => $client,
            'url' => $url,
            'archived' => $archived,
            'detail' => $detail,
            'untrusted' => $untrusted,
        ];
    }
}
