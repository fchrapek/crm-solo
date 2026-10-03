<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\Client;
use App\Models\InfaktDraftRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * One maintenance-draft run for a client and month, recorded per invoice
 * group in infakt_draft_requests so no group is ever drafted twice.
 *
 * Every send is a numbered attempt. Claiming one bumps `attempt` in a single
 * guarded statement (id, expected attempt, expected state), so of two callers
 * holding the same stale row only one wins. Every later write for that send,
 * the task reference included, is guarded by the same attempt and the state
 * it expects, so a late answer for an older attempt changes nothing.
 *
 * | State       | Moved to         | By                                                      |
 * |-------------|------------------|---------------------------------------------------------|
 * | (none)      | sending          | a run claiming the group (insert, attempt 1)            |
 * | sending     | submitted        | the claiming attempt: 2xx with a task reference         |
 * | sending     | failed           | the claiming attempt: HTTP 422 (Infakt created nothing) |
 * | sending     | unconfirmed      | the claiming attempt: any other answer, or none         |
 * | sending     | (counts as lost) | nobody: the lease ran out (a crashed run)               |
 * | submitted   | created          | a poll or a later run: the task says 201                |
 * | submitted   | failed           | a poll or a later run: the task says 422                |
 * | failed      | sending          | any run, as a new attempt                               |
 * | lost        | created          | any run that finds exactly its draft in Infakt (no send)|
 * | lost        | sending          | a run with --resend-unconfirmed, as a new attempt       |
 * | created     | (final)          |                                                         |
 *
 * "Lost" is unconfirmed, or sending past its lease. A month with no recorded
 * request is first checked for invoices made by hand in Infakt.
 */
final class InfaktDraftRun
{
    public const string CREATED = 'created';

    public const string ALREADY_DRAFTED = 'already_drafted';

    public const string PROCESSING = 'processing';

    public const string UNCONFIRMED = 'unconfirmed';

    public const string BUSY = 'busy';

    /** How long a claimed send may stay in flight before it counts as lost. */
    public const int LEASE_MINUTES = 10;

    /** @var array<int, string> per group, Infakt invoices that may be its lost draft, for the owner to check */
    private array $leads = [];

    public function __construct(private readonly InfaktService $infakt) {}

    /**
     * What identifies a draft: its currency and exact line items, in any
     * order. Computed from the payload sent and from an invoice Infakt lists,
     * so the two can be compared.
     *
     * @param  list<array<string, mixed>>  $services
     */
    public static function fingerprint(?string $currency, array $services): string
    {
        $lines = collect($services)
            ->map(fn (array $line): array => [
                mb_trim((string) ($line['name'] ?? '')),
                (int) ($line['unit_net_price'] ?? 0),
                (string) (float) ($line['quantity'] ?? 0),
                (string) ($line['tax_symbol'] ?? ''),
            ])
            ->sortBy(fn (array $line): string => (string) json_encode($line))
            ->values()
            ->all();

        return hash('sha256', (string) json_encode([mb_strtoupper($currency ?: 'PLN'), $lines], JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return list<array{group: int, outcome: string, payload: array<string, mixed>, task_reference: ?string, message: ?string}>
     *
     * @throws InfaktApiException when Infakt rejects a request (422); groups before it stay recorded
     * @throws RuntimeException when an answer is uncertain, or Infakt holds an invoice for the month this CRM never asked for
     */
    public function run(Client $client, Carbon $periodMonth, bool $resendUnconfirmed = false): array
    {
        $groups = $this->infakt->maintenanceInvoiceGroups($client, $periodMonth);
        $period = $periodMonth->format('Y-m');

        $this->leads = [];

        // Each step reads the rows again: a step before it, or another run, may have moved them.
        foreach ($this->requests($client, $period) as $request) {
            if ($request->status === InfaktDraftRequest::STATUS_SUBMITTED && $request->task_reference !== null) {
                $this->recordTaskState($request, $request->task_reference, $this->infakt->draftTaskState($request->task_reference));
            }
        }

        if ($this->requests($client, $period)->contains(fn (InfaktDraftRequest $r): bool => $r->isLost())) {
            $this->adoptDraftsFoundInInfakt($client, $period, $groups);
        }

        $requests = $this->requests($client, $period);
        if ($requests->isEmpty()) {
            $this->refuseHandMadeInvoices($client, reset($groups)['invoice']);
        }

        $results = [];
        foreach ($groups as $group => $payload) {
            $results[] = $this->draftGroup($client, $period, $group, $payload, $requests->get($group), $resendUnconfirmed);
        }

        return $results;
    }

    /**
     * @param  array{invoice: array<string, mixed>}  $payload
     * @return array{group: int, outcome: string, payload: array<string, mixed>, task_reference: ?string, message: ?string}
     */
    private function draftGroup(Client $client, string $period, int $group, array $payload, ?InfaktDraftRequest $request, bool $resendUnconfirmed): array
    {
        $result = fn (string $outcome, ?string $message = null, ?string $reference = null): array => [
            'group' => $group,
            'outcome' => $outcome,
            'payload' => $payload,
            'task_reference' => $reference,
            'message' => $message,
        ];

        if ($request !== null) {
            if ($request->status === InfaktDraftRequest::STATUS_CREATED) {
                return $result(self::ALREADY_DRAFTED, null, $request->task_reference);
            }
            if ($request->status === InfaktDraftRequest::STATUS_SUBMITTED) {
                return $result(self::PROCESSING, 'Infakt is still building this draft; run again to confirm it.', $request->task_reference);
            }
            if ($request->status === InfaktDraftRequest::STATUS_SENDING && ! $request->isLost()) {
                return $result(self::BUSY, 'Another run is sending this group right now.');
            }
            if ($request->isLost() && ! $resendUnconfirmed) {
                $look = isset($this->leads[$group]) ? " Infakt invoices that may be it: {$this->leads[$group]}." : '';

                return $result(self::UNCONFIRMED, "Sent at {$request->updated_at} with no confirmed answer, so Infakt may or may not hold it.{$look} Check Infakt, then rerun with --resend-unconfirmed if it is not there.");
            }
        }

        $fingerprint = self::fingerprint($payload['invoice']['currency'] ?? null, $payload['invoice']['services']);
        $attempt = $request === null ? $this->claimNew($client, $period, $group, $fingerprint) : $this->claimAgain($request, $fingerprint);
        if ($attempt === null) {
            return $result(self::BUSY, 'Another run claimed this group first.');
        }
        [$id, $number] = $attempt;

        try {
            $reference = $this->infakt->postDraftInvoice($payload);
        } catch (InfaktApiException $e) {
            // 422 is Infakt's documented validation rejection: nothing was created, so a rerun may send again.
            if ($e->status === 422) {
                $this->move($id, $number, InfaktDraftRequest::STATUS_SENDING, ['status' => InfaktDraftRequest::STATUS_FAILED, 'error' => $e->userMessage()]);

                throw $e;
            }

            throw $this->unconfirmed($id, $number, $group, "HTTP {$e->status}", $e);
        } catch (Throwable $e) {
            throw $this->unconfirmed($id, $number, $group, $e->getMessage(), $e);
        }

        if (! is_string($reference) || $reference === '') {
            $this->move($id, $number, InfaktDraftRequest::STATUS_SENDING, ['status' => InfaktDraftRequest::STATUS_UNCONFIRMED, 'error' => 'Accepted without a task reference']);

            return $result(self::UNCONFIRMED, 'Infakt answered without a task reference, so it is unknown whether it holds the draft. Check Infakt, then rerun with --resend-unconfirmed if it is not there.');
        }

        $submitted = $this->move($id, $number, InfaktDraftRequest::STATUS_SENDING, [
            'status' => InfaktDraftRequest::STATUS_SUBMITTED,
            'task_reference' => $reference,
            'lease_expires_at' => null,
        ]);
        if (! $submitted) {
            return $result(self::BUSY, 'This send outlived its lease and another run took the group over; check Infakt.', $reference);
        }

        $state = $this->infakt->awaitDraft($reference);
        $this->recordTaskState(InfaktDraftRequest::findOrFail($id), $reference, $state);

        if ($state['state'] === 'failed') {
            throw new RuntimeException("Infakt rejected the draft for group {$group}: {$state['error']}");
        }

        return $state['state'] === 'created'
            ? $result(self::CREATED, null, $reference)
            : $result(self::PROCESSING, 'Infakt is still building this draft; run again to confirm it.', $reference);
    }

    /**
     * @return array{0: int, 1: int}|null the row id and attempt number, or null when another run holds the group
     */
    private function claimNew(Client $client, string $period, int $group, string $fingerprint): ?array
    {
        try {
            $request = InfaktDraftRequest::create([
                'account_id' => $client->account_id,
                'client_id' => $client->id,
                'period' => $period,
                'invoice_group' => $group,
                'status' => InfaktDraftRequest::STATUS_SENDING,
                'attempt' => 1,
                'lease_expires_at' => now()->addMinutes(self::LEASE_MINUTES),
                'payload_fingerprint' => $fingerprint,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return [$request->id, 1];
    }

    /**
     * A new attempt on a failed or lost row. The attempt number in the guard
     * is what makes the claim exclusive: whoever bumps it first wins, and a
     * second caller with the same stale copy matches nothing.
     *
     * @return array{0: int, 1: int}|null
     */
    private function claimAgain(InfaktDraftRequest $request, string $fingerprint): ?array
    {
        $taken = InfaktDraftRequest::query()
            ->whereKey($request->id)
            ->where('attempt', $request->attempt)
            ->where('status', $request->status)
            ->when($request->status === InfaktDraftRequest::STATUS_SENDING, fn ($q) => $q->where('lease_expires_at', '<', now()))
            ->update([
                'status' => InfaktDraftRequest::STATUS_SENDING,
                'attempt' => $request->attempt + 1,
                'lease_expires_at' => now()->addMinutes(self::LEASE_MINUTES),
                'payload_fingerprint' => $fingerprint,
                'task_reference' => null,
                'invoice_uuid' => null,
                'error' => null,
                'updated_at' => now(),
            ]);

        return $taken === 1 ? [$request->id, $request->attempt + 1] : null;
    }

    /**
     * Writes a result only while the row is still on this attempt and in the
     * state the result answers.
     *
     * @param  array<string, mixed>  $values
     */
    private function move(int $id, int $attempt, string $from, array $values, ?string $reference = null): bool
    {
        return InfaktDraftRequest::query()
            ->whereKey($id)
            ->where('attempt', $attempt)
            ->where('status', $from)
            ->when($reference !== null, fn ($q) => $q->where('task_reference', $reference))
            ->update([...$values, 'updated_at' => now()]) === 1;
    }

    /**
     * Records the Infakt invoice a row stands for. The database lets one
     * invoice belong to one group of a month only; a row that loses that race
     * is left unconfirmed for the owner instead of failing the run.
     *
     * @param  array<string, mixed>  $values
     */
    private function moveToCreated(int $id, int $attempt, string $from, array $values, ?string $reference = null): bool
    {
        try {
            return $this->move($id, $attempt, $from, $values, $reference);
        } catch (UniqueConstraintViolationException) {
            $this->move($id, $attempt, $from, [
                'status' => InfaktDraftRequest::STATUS_UNCONFIRMED,
                'error' => "Infakt invoice {$values['invoice_uuid']} is already recorded for another group of this month",
            ], $reference);

            return false;
        }
    }

    /**
     * @param  array{state: string, invoice_uuid: ?string, error: ?string}  $state
     */
    private function recordTaskState(InfaktDraftRequest $request, string $reference, array $state): void
    {
        $values = match ($state['state']) {
            'created' => ['status' => InfaktDraftRequest::STATUS_CREATED, 'invoice_uuid' => $state['invoice_uuid'], 'error' => null],
            'failed' => ['status' => InfaktDraftRequest::STATUS_FAILED, 'error' => $state['error']],
            default => null,
        };

        if ($values === null) {
            return;
        }

        $values['status'] === InfaktDraftRequest::STATUS_CREATED
            ? $this->moveToCreated($request->id, $request->attempt, InfaktDraftRequest::STATUS_SUBMITTED, $values, $reference)
            : $this->move($request->id, $request->attempt, InfaktDraftRequest::STATUS_SUBMITTED, $values, $reference);
    }

    /**
     * Any answer other than a documented rejection (a 5xx, a gateway timeout,
     * a dropped connection, an unreadable body) may hide a draft Infakt did
     * create, so the group is blocked until someone checks.
     */
    private function unconfirmed(int $id, int $attempt, int $group, string $why, Throwable $previous): RuntimeException
    {
        $this->move($id, $attempt, InfaktDraftRequest::STATUS_SENDING, ['status' => InfaktDraftRequest::STATUS_UNCONFIRMED, 'error' => $why]);

        return new RuntimeException("Infakt did not confirm the draft for group {$group} ({$why}), so it may or may not exist. Recorded as unconfirmed: check Infakt, then rerun with --resend-unconfirmed if it is not there.", 0, $previous);
    }

    /**
     * A lost send may have reached Infakt. A row is taken as drafted, with
     * nothing sent, only when exactly one listed invoice carries exactly what
     * that row sent (fingerprint of currency and line items), no other open
     * row sent the same thing, and the invoice is not recorded for another
     * group. Anything less stays lost, and the invoices worth a look (same
     * lines, or else the same net total) are named to the owner.
     *
     * @param  array<int, array{invoice: array<string, mixed>}>  $groups
     */
    private function adoptDraftsFoundInInfakt(Client $client, string $period, array $groups): void
    {
        $invoice = reset($groups)['invoice'];
        $net = fn (int $group): int => isset($groups[$group])
            ? collect($groups[$group]['invoice']['services'])->sum(fn (array $line): int => (int) $line['unit_net_price'] * (int) $line['quantity'])
            : -1;
        $listed = $this->infakt->invoicesWithSaleDate((string) $invoice['client_id'], (string) $invoice['sale_date']);
        $describe = fn (Collection $invoices): string => $invoices
            ->map(fn (array $i): string => ($i['number'] ?? '#'.($i['id'] ?? '?')).' ('.($i['status'] ?? 'unknown').')')
            ->implode(', ');

        $requests = $this->requests($client, $period);
        $known = $requests->pluck('invoice_uuid')->filter()->all();
        $lost = $requests->filter(fn (InfaktDraftRequest $r): bool => $r->isLost());

        foreach ($lost as $request) {
            $open = $listed->reject(fn (array $i): bool => in_array($i['uuid'] ?? null, $known, true));
            $same = $request->payload_fingerprint === null ? collect() : $open->filter(
                fn (array $i): bool => self::fingerprint($i['currency'] ?? null, $i['services'] ?? []) === $request->payload_fingerprint,
            );
            // Another group still open that sent the same lines could own the invoice just as well.
            $twins = $requests->filter(fn (InfaktDraftRequest $r): bool => $r->id !== $request->id
                && $r->status !== InfaktDraftRequest::STATUS_CREATED
                && $r->payload_fingerprint === $request->payload_fingerprint);

            if ($same->count() === 1 && $twins->isEmpty() && ($uuid = $same->first()['uuid'] ?? null) !== null) {
                $adopted = $this->moveToCreated($request->id, $request->attempt, $request->status, [
                    'status' => InfaktDraftRequest::STATUS_CREATED,
                    'invoice_uuid' => $uuid,
                    'lease_expires_at' => null,
                    'error' => null,
                ]);
                if ($adopted) {
                    $known[] = $uuid;

                    continue;
                }
            }

            $leads = $same->isNotEmpty() ? $same : $open->filter(fn (array $i): bool => (int) ($i['net_price'] ?? -1) === $net($request->invoice_group));
            if ($leads->isNotEmpty()) {
                $this->leads[$request->invoice_group] = $describe($leads);
            }
        }
    }

    /**
     * A month this CRM has never drafted can still hold an invoice someone
     * made by hand in Infakt. That stops the run; nothing is sent.
     *
     * @param  array<string, mixed>  $invoice
     */
    private function refuseHandMadeInvoices(Client $client, array $invoice): void
    {
        $existing = $this->infakt->invoicesWithSaleDate((string) $invoice['client_id'], (string) $invoice['sale_date']);

        if ($existing->isNotEmpty()) {
            $list = $existing->map(fn (array $i): string => ($i['number'] ?? '#'.($i['id'] ?? '?')).' ('.($i['status'] ?? 'unknown').')')->implode(', ');

            throw new RuntimeException("Refusing: Infakt already holds an invoice for {$client->name} with sale date {$invoice['sale_date']} that this CRM did not draft: {$list}. Nothing was sent; check the month in Infakt.");
        }
    }

    /**
     * @return Collection<int, InfaktDraftRequest>
     */
    private function requests(Client $client, string $period): Collection
    {
        return InfaktDraftRequest::query()
            ->where('client_id', $client->id)
            ->where('period', $period)
            ->get()
            ->keyBy('invoice_group');
    }
}
