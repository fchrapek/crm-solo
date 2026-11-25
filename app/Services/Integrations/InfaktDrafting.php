<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\Client;
use App\Models\InfaktDraftRequest;
use App\Models\Integration;
use App\Models\Invoice;
use App\Models\Project;
use App\Support\LocalCalendar;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The one way into an Infakt draft for the command, the agent verb and tool, and the Billing block. Draft only.
 * The unit is the invoice group, named by its positions' projects; each group is drafted once a month, from the
 * retainer or as a copy (Wystaw podobna) of the invoice recorded for it in the latest earlier month. A group with no
 * usable source is `needs_source` until the owner picks an invoice; the other groups still draft.
 */
final class InfaktDrafting
{
    public const string NEEDS_SOURCE = 'needs_source';

    public const string PREVIEW = 'preview';

    /** The month holds a copy recorded before groups were per project; nothing is sent until it is resolved. */
    public const string BLOCKED = 'blocked';

    /** Copies recorded before groups were per project. */
    public const int LEGACY_COPY_GROUP = 0;

    /**
     * @return array{period: string, mode: string, results: list<array<string, mixed>>}
     *
     * @throws RuntimeException
     */
    public function preview(Client $client, ?string $period, ?string $like, ?Project $project = null, ?int $group = null, bool $chainOnly = false): array
    {
        $month = $this->month($period);
        $plans = $this->plans($client, $month, $like, $project, $group, $chainOnly);

        return [
            'period' => $month->format('Y-m'),
            'mode' => $like === null ? InfaktDraftRequest::SOURCE_RETAINER : InfaktDraftRequest::SOURCE_COPY,
            'results' => array_values(array_map(fn (array $plan): array => $this->row($plan, $plan['outcome'] ?? self::PREVIEW, $plan['message'] ?? null), $plans)),
        ];
    }

    /**
     * $chainOnly: `last` copies only a group's recorded invoice, never the client's latest one (the Billing block,
     * where a first copy is always a pick).
     *
     * @return array{period: string, mode: string, results: list<array<string, mixed>>}
     *
     * @throws RuntimeException
     * @throws InfaktApiException
     */
    public function draft(Client $client, ?string $period, ?string $like, bool $resendUnconfirmed = false, ?Project $project = null, ?int $group = null, bool $chainOnly = false): array
    {
        $month = $this->month($period);
        $plans = $this->plans($client, $month, $like, $project, $group, $chainOnly);

        try {
            $results = Cache::lock("infakt-draft:{$client->id}:{$month->format('Y-m')}", 300)
                ->block(15, fn (): array => $this->send($client, $month, $plans, $resendUnconfirmed));
        } catch (LockTimeoutException) {
            throw new RuntimeException("Another run is drafting {$month->format('Y-m')} for {$client->name} right now; try again in a minute.");
        }

        return [
            'period' => $month->format('Y-m'),
            'mode' => $like === null ? InfaktDraftRequest::SOURCE_RETAINER : InfaktDraftRequest::SOURCE_COPY,
            'results' => $results,
        ];
    }

    /**
     * What the Billing picker offers: the client's issued VAT invoices, newest first.
     *
     * @return Collection<int, array{uuid: ?string, number: ?string, invoice_date: ?string, sale_date: ?string, net_price: int, currency: string, lines: list<string>}>
     *
     * @throws RuntimeException
     * @throws InfaktApiException
     */
    public function copyable(Client $client): Collection
    {
        $service = $this->service($client);

        return $service->copyableInvoices($service->assertDraftableClient($client), 1)
            ->map(fn (array $i): array => [
                'uuid' => $i['uuid'] ?? null,
                'number' => $i['number'] ?? null,
                'invoice_date' => $i['invoice_date'] ?? null,
                'sale_date' => $i['sale_date'] ?? null,
                'net_price' => (int) ($i['net_price'] ?? 0),
                'currency' => (string) ($i['currency'] ?? 'PLN'),
                'lines' => array_values(array_map(fn (array $line): string => (string) ($line['name'] ?? ''), (array) ($i['services'] ?? []))),
            ])
            ->values();
    }

    /**
     * The billed month's invoices per group for the Billing block, read from the CRM only (draft rows and the
     * invoice mirror): what each group would copy, or its draft. Null when the client is not drafted through Infakt.
     *
     * @return array{period: string, groups: list<array<string, mixed>>}|null
     */
    public function monthState(Client $client): ?array
    {
        if ($this->integration($client) === null || $client->month_close_type !== 'maintenance' || ($client->external_ids['infakt'] ?? null) === null) {
            return null;
        }

        $month = $this->month(null);
        $period = $month->format('Y-m');
        $groups = InfaktService::invoiceGroupProjects($client, $month);
        if ($groups === []) {
            return null;
        }
        $this->confirmSubmitted($client, $period);
        $rows = InfaktDraftRequest::query()->where('client_id', $client->id)->where('period', $period)->get()->keyBy('invoice_group');
        $blocked = $this->legacyBlocks($rows);
        $names = Project::query()->whereIn('id', collect($groups)->flatten()->all())->pluck('name', 'id');

        $out = [];
        foreach ($groups as $group => $projectIds) {
            $row = $rows->get($group);
            $previous = $this->previousDraft($client, $group, $period);
            $chained = $previous !== null && ($previous->project_ids === null || $previous->project_ids === $projectIds);
            $source = $chained ? $this->mirrored($client, $previous->invoice_uuid) : null;
            $draft = $row?->status === InfaktDraftRequest::STATUS_CREATED ? $this->mirrored($client, $row->invoice_uuid) : null;
            $reason = Cache::get($this->refusalKey($client, $period, $group))
                ?? ($source !== null ? InfaktService::sourceRefusalCode([
                    'kind' => $source->kind ?? 'vat', 'status' => $source->status, 'number' => $source->number, 'parent_id' => $source->parent_external_id,
                ]) : null)
                ?? ($previous !== null && ! $chained ? 'projects_changed' : null);

            $state = match (true) {
                $row?->status === InfaktDraftRequest::STATUS_CREATED => 'drafted',
                $row !== null && $row->isLost() => 'unconfirmed',
                $row !== null && $row->status !== InfaktDraftRequest::STATUS_FAILED => 'processing',
                $blocked => self::BLOCKED,
                $chained && $reason === null => 'ready',
                default => self::NEEDS_SOURCE,
            };
            $shown = $state === 'drafted' ? $draft : ($state === 'ready' ? $source : null);

            $out[] = [
                'group' => $group,
                'projects' => array_values(array_map(fn (int $id): string => (string) ($names[$id] ?? ''), $projectIds)),
                'state' => $state,
                'net' => $shown?->net_price,
                'currency' => $shown?->currency ?? mb_strtoupper($client->currency ?: 'PLN'),
                'chained' => $chained,
                'reason' => $state === self::NEEDS_SOURCE ? $reason : null,
                'source' => $source !== null ? ['number' => $source->number, 'sale_date' => $source->sale_date?->toDateString()] : null,
                'draft_url' => $draft?->infaktUrl(),
            ];
        }

        return ['period' => $period, 'groups' => $out];
    }

    public function service(Client $client): InfaktService
    {
        return new InfaktService($this->integration($client) ?? throw new RuntimeException('No enabled Infakt integration for this account.'));
    }

    private function integration(Client $client): ?Integration
    {
        // The demo calls nobody, whatever credentials its database still holds.
        if (config('app.demo')) {
            return null;
        }

        return Integration::query()->where('provider', 'infakt')->where('account_id', $client->account_id)
            ->where('is_enabled', true)->whereNotNull('api_key')->first();
    }

    /**
     * @param  array<int, array<string, mixed>>  $plans
     * @return list<array<string, mixed>>
     */
    private function send(Client $client, Carbon $month, array $plans, bool $resendUnconfirmed): array
    {
        $requests = InfaktDraftRequest::query()->where('client_id', $client->id)->where('period', $month->format('Y-m'))->get()->keyBy('invoice_group');
        if ($this->legacyBlocks($requests)) {
            $plans = $this->blocked($plans, $requests, $month->format('Y-m'));
        }
        $sendable = array_filter($plans, fn (array $plan): bool => ! isset($plan['outcome']));
        $ran = [];
        if ($sendable !== []) {
            $service = $this->service($client);
            $runs = (new InfaktDraftRun($service))->run(
                $client,
                $month,
                $resendUnconfirmed,
                array_map(fn (array $plan): array => $plan['payload'], $sendable),
                array_map(fn (array $plan): array => $plan['origin'], $sendable),
            );
            foreach ($runs as $run) {
                $ran[$run['group']] = $run;
            }
        }
        $drafted = collect($plans)->filter(fn (array $plan): bool => in_array($ran[$plan['group']]['outcome'] ?? $plan['outcome'] ?? null, [InfaktDraftRun::CREATED, InfaktDraftRun::ALREADY_DRAFTED], true));
        foreach ($drafted as $plan) {
            Cache::forget($this->refusalKey($client, $month->format('Y-m'), $plan['group']));
            $this->mirror($client, $month->format('Y-m'), $plan['group']);
        }

        return array_values(array_map(
            fn (array $plan): array => isset($ran[$plan['group']])
                ? [...$this->row($plan, $ran[$plan['group']]['outcome'], $ran[$plan['group']]['message']), 'task_reference' => $ran[$plan['group']]['task_reference']]
                : $this->row($plan, $plan['outcome'] ?? self::NEEDS_SOURCE, $plan['message'] ?? null),
            $plans,
        ));
    }

    /**
     * Per invoice group: its payload and origin, or an outcome reached without sending (already drafted, needs a source).
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RuntimeException
     */
    private function plans(Client $client, Carbon $month, ?string $like, ?Project $project, ?int $only, bool $chainOnly): array
    {
        $service = $this->service($client);
        $infaktClientId = $service->assertDraftableClient($client);
        $groups = InfaktService::invoiceGroupProjects($client, $month);
        $period = $month->format('Y-m');
        if ($groups === []) {
            throw new RuntimeException("Refusing: no active fee-bearing retainer position for {$client->name} on {$month->copy()->endOfMonth()->toDateString()}.");
        }
        if ($project !== null && ! collect($groups)->contains(fn (array $ids): bool => in_array($project->id, $ids, true))) {
            throw new RuntimeException("Refusing: {$project->name} is on no fee-bearing retainer position of {$client->name} on {$month->copy()->endOfMonth()->toDateString()}.");
        }
        $wanted = fn (int $group, array $ids): bool => ($project === null || in_array($project->id, $ids, true)) && ($only === null || $group === $only);

        if ($like === null) {
            $payloads = $service->maintenanceInvoiceGroups($client, $month);
            $plans = [];
            foreach ($payloads as $group => $payload) {
                $plans[$group] = $this->plan($group, $groups[$group] ?? [], ['source' => InfaktDraftRequest::SOURCE_RETAINER], $payload);
            }
        } else {
            $plans = $this->copyPlans($client, $service, $infaktClientId, $month, $like, $groups, $wanted, $chainOnly);
        }

        $plans = array_filter($plans, fn (array $plan): bool => $wanted($plan['group'], $plan['project_ids']));
        if ($plans === []) {
            throw new RuntimeException("Refusing: {$client->name} has no invoice group {$only} on {$month->copy()->endOfMonth()->toDateString()}.");
        }

        if ($like !== null) {
            foreach ($plans as $group => $plan) {
                $key = $this->refusalKey($client, $period, $group);
                match (true) {
                    ($plan['outcome'] ?? null) === self::NEEDS_SOURCE && ($plan['reason'] ?? null) !== null => Cache::put($key, $plan['reason'], now()->addDays(45)),
                    $plan['payload'] !== null => Cache::forget($key),
                    default => null,
                };
            }
        }

        $requests = InfaktDraftRequest::query()->where('client_id', $client->id)->where('period', $period)->get()->keyBy('invoice_group');

        return $this->legacyBlocks($requests) ? $this->blocked($plans, $requests, $period) : $plans;
    }

    /**
     * A copy recorded as group 0 may stand for any group of its month, so no group of that month is sent or sent
     * again, a failed one included, until the row is resolved by hand.
     *
     * @param  Collection<int, InfaktDraftRequest>  $requests  the month's rows by group
     */
    private function legacyBlocks(Collection $requests): bool
    {
        $legacy = $requests->get(self::LEGACY_COPY_GROUP);

        return $legacy !== null && $legacy->status !== InfaktDraftRequest::STATUS_FAILED;
    }

    /**
     * @param  array<int, array<string, mixed>>  $plans
     * @param  Collection<int, InfaktDraftRequest>  $requests
     * @return array<int, array<string, mixed>>
     */
    private function blocked(array $plans, Collection $requests, string $period): array
    {
        foreach ($plans as $group => $plan) {
            if ($requests->get($group)?->status !== InfaktDraftRequest::STATUS_CREATED) {
                $plans[$group] = [...$plan, 'outcome' => self::BLOCKED, 'payload' => null, 'message' => "{$period} holds a copy recorded before drafts were kept per project (invoice group 0), so nothing is sent for it: check the month in Infakt and resolve that row first."];
            }
        }

        return $plans;
    }

    /**
     * @param  array<int, list<int>>  $groups
     * @return array<int, array<string, mixed>>
     *
     * @throws RuntimeException
     */
    private function copyPlans(Client $client, InfaktService $service, string $infaktClientId, Carbon $month, string $like, array $groups, Closure $wanted, bool $chainOnly): array
    {
        $period = $month->format('Y-m');
        $selected = array_filter($groups, fn (array $ids, int $group): bool => $wanted($group, $ids), ARRAY_FILTER_USE_BOTH);
        $recorded = InfaktDraftRequest::query()->where('client_id', $client->id)->where('period', $period)->get()->keyBy('invoice_group');

        if ($like !== 'last') {
            if (count($selected) !== 1) {
                throw new RuntimeException("Refusing: {$client->name} has ".count($selected)." invoice groups; name the project invoice {$like} is for (--project).");
            }
            $source = $service->invoiceNumbered($infaktClientId, $like)
                ?? throw new RuntimeException("Refusing: no invoice numbered {$like} for {$client->name} in Infakt.");
            if (($why = InfaktService::sourceRefusal($source)) !== null) {
                throw new RuntimeException("Refusing: {$why}.");
            }
            $group = (int) array_key_first($selected);

            return [$group => $this->copyPlan($service, $infaktClientId, $month, $group, $selected[$group], $source)];
        }

        $plans = [];
        foreach ($groups as $group => $projectIds) {
            $row = $recorded->get($group);
            if ($row !== null && $row->status === InfaktDraftRequest::STATUS_CREATED) {
                $plans[$group] = [...$this->plan($group, $projectIds, $this->originOf($row), null), 'outcome' => InfaktDraftRun::ALREADY_DRAFTED, 'task_reference' => $row->task_reference];

                continue;
            }
            if (! $wanted($group, $projectIds)) {
                continue;
            }
            $plans[$group] = $this->chainedPlan($client, $service, $infaktClientId, $month, $group, $projectIds, ! $chainOnly && count($groups) === 1);
        }

        return $plans;
    }

    /**
     * The source of a group is the invoice recorded for it in the latest earlier month; with $latestFallback (an agent's
     * `last` on a single-group client) a group with none starts from the client's latest issued VAT invoice.
     *
     * @param  list<int>  $projectIds
     * @return array<string, mixed>
     */
    private function chainedPlan(Client $client, InfaktService $service, string $infaktClientId, Carbon $month, int $group, array $projectIds, bool $latestFallback): array
    {
        $needs = fn (string $why, ?string $reason = null): array => [...$this->plan($group, $projectIds, ['source' => InfaktDraftRequest::SOURCE_COPY], null), 'outcome' => self::NEEDS_SOURCE, 'message' => $why, 'reason' => $reason];
        $previous = $this->previousDraft($client, $group, $month->format('Y-m'));

        if ($previous !== null) {
            if ($previous->project_ids !== null && $previous->project_ids !== $projectIds) {
                return $needs("The projects of this invoice group changed since {$previous->period}; pick the invoice to copy.", 'projects_changed');
            }
            $source = $service->fetchInvoice((string) $previous->invoice_uuid);
            if ($source === null) {
                return $needs("The invoice recorded for {$previous->period} is no longer in Infakt; pick the invoice to copy.", 'gone');
            }
        } elseif ($latestFallback) {
            $latest = $service->copyableInvoices($infaktClientId)->first();
            if ($latest === null || ! isset($latest['uuid'])) {
                return $needs("{$client->name} has no issued VAT invoice in Infakt to copy.");
            }
            $source = $service->fetchInvoice((string) $latest['uuid']) ?? $latest;
        } else {
            return $needs('No earlier invoice is recorded for this invoice group; pick the invoice to copy once, later months follow it.');
        }

        if (($why = InfaktService::sourceRefusal($source)) !== null) {
            return $needs("{$why}; pick the invoice to copy.", InfaktService::sourceRefusalCode($source));
        }

        return $this->copyPlan($service, $infaktClientId, $month, $group, $projectIds, $source);
    }

    /**
     * @param  list<int>  $projectIds
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function copyPlan(InfaktService $service, string $infaktClientId, Carbon $month, int $group, array $projectIds, array $source): array
    {
        $origin = [
            'source' => InfaktDraftRequest::SOURCE_COPY,
            'source_invoice_uuid' => isset($source['uuid']) ? (string) $source['uuid'] : null,
            'source_invoice_number' => isset($source['number']) ? (string) $source['number'] : null,
        ];
        try {
            $payload = $service->copyInvoicePayload($source, $infaktClientId, $month);
            InfaktService::assertDraftOnly($payload);
        } catch (RuntimeException $e) {
            return [...$this->plan($group, $projectIds, $origin, null), 'outcome' => self::NEEDS_SOURCE, 'message' => $e->getMessage(), 'reason' => 'unusable'];
        }

        return $this->plan($group, $projectIds, $origin, $payload);
    }

    /**
     * @param  list<int>  $projectIds
     * @param  array<string, mixed>  $origin
     * @param  array{invoice: array<string, mixed>}|null  $payload
     * @return array<string, mixed>
     */
    private function plan(int $group, array $projectIds, array $origin, ?array $payload): array
    {
        return [
            'group' => $group,
            'project_ids' => $projectIds,
            'origin' => [...$origin, 'project_ids' => $projectIds],
            'payload' => $payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function originOf(InfaktDraftRequest $row): array
    {
        return ['source' => $row->source, 'source_invoice_uuid' => $row->source_invoice_uuid, 'source_invoice_number' => $row->source_invoice_number];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function row(array $plan, string $outcome, ?string $message): array
    {
        $names = Project::query()->whereIn('id', $plan['project_ids'])->pluck('name', 'id');

        return [
            'group' => $plan['group'],
            'project' => array_values(array_map(fn (int $id): array => ['id' => $id, 'name' => $names[$id] ?? null], $plan['project_ids'])),
            'source' => [
                'kind' => $plan['origin']['source'],
                'invoice_uuid' => $plan['origin']['source_invoice_uuid'] ?? null,
                'invoice_number' => $plan['origin']['source_invoice_number'] ?? null,
            ],
            'outcome' => $outcome,
            'payload' => $plan['payload'],
            'task_reference' => $plan['task_reference'] ?? null,
            'message' => $message,
        ];
    }

    /** The draft in the local mirror at once, so its number, amount and link show before the nightly sync. */
    private function mirror(Client $client, string $period, int $group): void
    {
        $uuid = InfaktDraftRequest::query()->where('client_id', $client->id)->where('period', $period)->where('invoice_group', $group)->value('invoice_uuid');
        if ($uuid === null || $this->mirrored($client, (string) $uuid) !== null) {
            return;
        }
        try {
            $service = $this->service($client);
            $invoice = $service->fetchInvoice((string) $uuid);
            if ($invoice !== null) {
                $service->mirrorInvoice($invoice, $client);
            }
        } catch (Throwable) {
            // The nightly sync mirrors it anyway; a failed read here changes nothing in Infakt.
        }
    }

    /** Drafts Infakt was still building when their run ended, confirmed and mirrored; the page renders whatever Infakt says. */
    private function confirmSubmitted(Client $client, string $period): void
    {
        $submitted = InfaktDraftRequest::query()->where('client_id', $client->id)->where('period', $period)
            ->where('status', InfaktDraftRequest::STATUS_SUBMITTED)->pluck('invoice_group');
        if ($submitted->isEmpty()) {
            return;
        }
        try {
            (new InfaktDraftRun($this->service($client)))->confirmSubmitted($client, $period);
        } catch (Throwable) {
            return;
        }
        foreach ($submitted as $group) {
            $this->mirror($client, $period, (int) $group);
        }
    }

    /** The last copy attempt's refusal for a group of a month, kept so the block shows the pick instead of Copy invoice. */
    private function refusalKey(Client $client, string $period, int $group): string
    {
        return "infakt-draft-refusal:{$client->id}:{$period}:{$group}";
    }

    private function previousDraft(Client $client, int $group, string $period): ?InfaktDraftRequest
    {
        return InfaktDraftRequest::query()->where('client_id', $client->id)->where('invoice_group', $group)
            ->where('period', '<', $period)->where('status', InfaktDraftRequest::STATUS_CREATED)
            ->whereNotNull('invoice_uuid')->orderByDesc('period')->first();
    }

    private function mirrored(Client $client, ?string $uuid): ?Invoice
    {
        return $uuid === null ? null : Invoice::query()->where('account_id', $client->account_id)->where('external_uuid', $uuid)->first();
    }

    private function month(?string $period): Carbon
    {
        try {
            return Carbon::instance(LocalCalendar::monthFrom($period ?? LocalCalendar::previousMonth(), (string) config('app.timezone')));
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage());
        }
    }
}
