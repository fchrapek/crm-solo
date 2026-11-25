<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\Contact;
use App\Models\Project;
use App\Services\Clients\Fields\ClientFieldsQuery;
use App\Support\LocalCalendar;
use Illuminate\Support\Facades\Auth;

final class ClientDetailsQuery
{
    /**
     * Dane: the client's data, contacts, documents and the month close and report settings the old edit page held.
     *
     * @return array{card: array<string, mixed>|null, details: array<string, mixed>, contacts: list<array<string, mixed>>, documents: list<array<string, mixed>>}
     */
    public function props(Client $client): array
    {
        $infaktId = $client->external_ids['infakt'] ?? null;

        $fields = app(ClientFieldsQuery::class);
        $uses = $fields->uses($client->account_id);
        $billed = $client->account->shows('billing', Auth::user());

        return [
            'card' => $uses ? $fields->card($client) : null,
            'details' => [
                'type' => $client->type ?? 'business',
                'name' => $client->name,
                'tax_id' => $client->tax_id,
                'business_type' => $client->business_type,
                'email' => $client->email,
                'phone' => $client->phone,
                'address' => $client->address,
                'postal_code' => $client->postal_code,
                'city' => $client->city,
                'region' => $client->region,
                'country' => $client->country,
                'cooperation_type' => $client->cooperation_type,
                'month_close_type' => $client->month_close_type,
                'infakt_id' => $infaktId !== null && $infaktId !== '' ? (string) $infaktId : null,
                'currency' => $client->currency ?? 'PLN',
                'segment' => $client->segment,
                'hourly_rate' => $client->hourly_rate !== null ? (float) $client->hourly_rate : null,
                'report_mode' => $client->report_mode,
                'include_in_month_close' => (bool) $client->include_in_month_close,
                'ssh_config' => $client->ssh_config,
                'maintenance_invoice_description' => $client->maintenance_invoice_description,
                'notes' => $client->notes,
                'archived' => $client->trashed(),
                'has_report_baseline' => $client->report_baseline_markdown !== null && mb_trim($client->report_baseline_markdown) !== '',
                // A module's tile and menu entries show while the account has it on, the month close also for a client it holds.
                'modules' => [
                    'month_close' => $billed && ($client->account->usesModule('month_close') || app(MonthCloseWorklist::class)->holds($client)),
                    'report_baseline' => $billed && $client->account->usesModule('report_baseline'),
                ],
            ],
            'stage' => app(ClientJournalQuery::class)->stage($client),
            'projects' => $client->projects()
                ->where('account_id', $client->account_id)
                ->whereNull('archived_at')
                ->withCount('repositories')
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name', 'trello_board_id', 'include_in_month_close'])
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'is_private' => $project->trello_board_id === null,
                    'include_in_month_close' => (bool) $project->include_in_month_close,
                    'repositories' => (int) $project->repositories_count,
                ])
                ->all(),
            'documents' => $client->documents()
                ->latest()
                ->get()
                ->map(fn ($document): array => [
                    'id' => $document->id,
                    'url' => route('client-documents.show', [$client->id, $document->id]),
                    'original_name' => $document->original_name,
                    'mime' => $document->mime,
                    'size' => $document->size,
                    'category' => $document->category,
                    'label' => $document->label,
                    'created_at' => $document->created_at->toIso8601String(),
                    'created_on' => $document->created_at->copy()->setTimezone(LocalCalendar::timezone())->toDateString(),
                ])
                ->values()
                ->all(),
            'contacts' => $client->contacts()
                ->orderByName()
                ->get(['id', 'first_name', 'last_name', 'emails', 'phone', 'position'])
                ->map(fn (Contact $contact): array => [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'email' => $contact->primaryEmail(),
                    'phone' => $contact->phone,
                    'position' => $contact->position,
                ])
                ->values()
                ->all(),
        ];
    }
}
