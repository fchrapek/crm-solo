<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Task;
use App\Support\LegalName;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;

final class ClientOrderSummary
{
    public const string STATE_IN_PROGRESS = 'in_progress';

    public const string STATE_DONE = 'done';

    public const string INVOICE_DRAFT = 'draft';

    public const string INVOICE_UNPAID = 'unpaid';

    public const string INVOICE_PAID = 'paid';

    /**
     * @return array{title: string, project: ?array<string, mixed>, started_on: ?string, hours: float, month_hours: float, tasks: array{done: int, total: int}, state: ?string, invoice: ?array<string, mixed>}
     */
    public function props(Client $client): array
    {
        $project = $client->generalProject();
        // The catch-all project keeps its stored name; until someone renames it, the client's name stands in for it.
        $title = $project !== null && $project->name !== 'General' ? $project->name : LegalName::short($client->name);
        [$from, $to] = LocalCalendar::monthRange(LocalCalendar::currentMonth());

        $tasks = Task::query()->whereHas('project', fn ($q) => $q->where('client_id', $client->id)->where('account_id', $client->account_id));
        $total = (clone $tasks)->count();
        $open = (clone $tasks)->open()->count();
        $first = $client->timeEntries()->min('start_time');

        return [
            'title' => $title,
            'project' => $project !== null ? [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'include_in_month_close' => (bool) $project->include_in_month_close,
                'backup_path' => $project->backup_path,
            ] : null,
            'started_on' => $first !== null ? CarbonImmutable::parse($first, 'UTC')->setTimezone(LocalCalendar::timezone())->toDateString() : null,
            'hours' => round((int) $client->timeEntries()->sum('duration_minutes') / 60, 2),
            'month_hours' => round((int) $client->timeEntries()->where('start_time', '>=', $from)->where('start_time', '<', $to)->sum('duration_minutes') / 60, 2),
            'tasks' => ['done' => $total - $open, 'total' => $total],
            'state' => match (true) {
                $open > 0 => self::STATE_IN_PROGRESS,
                $total > 0 => self::STATE_DONE,
                default => null,
            },
            'invoice' => $this->latestInvoice($client),
        ];
    }

    /** @return array<string, mixed>|null */
    private function latestInvoice(Client $client): ?array
    {
        $invoice = Invoice::query()
            ->where('client_id', $client->id)
            ->where('account_id', $client->account_id)
            ->orderByRaw('CASE WHEN invoice_date IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->first();

        if ($invoice === null) {
            return null;
        }

        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'state' => match ($invoice->status) {
                'draft' => self::INVOICE_DRAFT,
                'paid' => self::INVOICE_PAID,
                default => self::INVOICE_UNPAID,
            },
            'currency' => $invoice->currency ?? $client->currency ?? 'PLN',
            'gross' => round((int) $invoice->gross_price / 100, 2),
            'net' => round((int) $invoice->net_price / 100, 2),
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'payment_date' => $invoice->payment_date?->toDateString(),
            'paid_date' => $invoice->paid_date?->toDateString(),
            'overdue' => $invoice->isOverdueOn(LocalCalendar::todayDate()),
        ];
    }
}
