<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\Client;
use App\Models\ClientRetainer;
use App\Models\Contact;
use App\Models\Integration;
use App\Models\Invoice;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class InfaktService
{
    /** Default line-item wording for a maintenance draft invoice (overridable per client). */
    public const DEFAULT_MAINTENANCE_DESCRIPTION = 'Utrzymanie strony i wsparcie techniczne';

    /** VAT rate for maintenance services (netto retainer + 23% at invoice level). */
    public const MAINTENANCE_VAT_SYMBOL = '23';

    private const BASE_URL = 'https://api.infakt.pl/v3';

    private PendingRequest $http;

    public function __construct(
        private readonly Integration $integration
    ) {
        $this->http = Http::baseUrl(self::BASE_URL)
            ->withHeaders([
                'X-inFakt-ApiKey' => $this->integration->api_key,
                'Content-Type' => 'application/json',
            ])
            ->timeout(30);
    }

    public function testConnection(): bool
    {
        try {
            $response = $this->http->get('/clients.json', ['limit' => 1]);

            return $response->successful();
        } catch (Exception $e) {
            Log::error('Infakt API connection test failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Create a DRAFT maintenance invoice in Infakt for a maintenance client,
     * derived from the retainer in force for the given month.
     *
     * Intentionally narrow — this is the ONLY invoice-writing path in the CRM.
     * It refuses any client that is not a `maintenance` client with an active
     * retainer + a linked Infakt id, always emits a single retainer-derived
     * line item, and always lands as a `draft` (Infakt creates invoices as
     * draft; this never issues, sends, prints or marks-paid). The CRM cannot
     * create arbitrary invoices.
     *
     * @return array{payload: array<string, mixed>, task_reference: ?string, status: ?array<string, mixed>}
     *
     * @throws InfaktApiException on a non-2xx create response
     */
    public function createDraftMaintenanceInvoice(Client $client, Carbon $periodMonth): array
    {
        $payload = $this->buildMaintenanceInvoicePayload($client, $periodMonth);

        $response = $this->http->post('/async/invoices.json', $payload);

        if (! $response->successful()) {
            throw new InfaktApiException($response->status(), $response->body());
        }

        $reference = $response->json('invoice_task_reference_number');

        return [
            'payload' => $payload,
            'task_reference' => $reference,
            'status' => $reference !== null ? $this->pollInvoiceTask($reference) : null,
        ];
    }

    /**
     * Create one or more DRAFT maintenance invoices for a client. A single
     * Infakt payer can cover several sites: lines sharing an `invoice_group`
     * land on one invoice, different groups produce separate invoices. When the
     * client has no active maintenance lines this falls back to the single
     * retainer-derived draft. Every invoice is a `draft` — never issued or sent.
     *
     * @return array<int, array{payload: array<string, mixed>, task_reference: ?string, status: ?array<string, mixed>}>
     *
     * @throws InfaktApiException on a non-2xx create response
     */
    public function createDraftMaintenanceInvoices(Client $client, Carbon $periodMonth): array
    {
        $results = [];

        foreach ($this->buildMaintenanceInvoicePayloads($client, $periodMonth) as $payload) {
            $response = $this->http->post('/async/invoices.json', $payload);

            if (! $response->successful()) {
                throw new InfaktApiException($response->status(), $response->body());
            }

            $reference = $response->json('invoice_task_reference_number');

            $results[] = [
                'payload' => $payload,
                'task_reference' => $reference,
                'status' => $reference !== null ? $this->pollInvoiceTask($reference) : null,
            ];
        }

        return $results;
    }

    /**
     * Build (but do not send) the draft payloads for a client — one per invoice
     * group. Pure; used for dry-run preview and by createDraftMaintenanceInvoices.
     * Reads the client's active abonament positions: positions sharing an
     * `invoice_group` land on one invoice, different groups become separate
     * invoices. Only fee-bearing positions are billed — a 0-fee position (e.g.
     * an included dev-hours allowance) is tracked for reports but never emitted
     * as a line.
     *
     * @return array<int, array{invoice: array<string, mixed>}>
     */
    public function buildMaintenanceInvoicePayloads(Client $client, Carbon $periodMonth): array
    {
        if ($client->month_close_type !== 'maintenance') {
            throw new RuntimeException("Refusing: {$client->name} is not a maintenance client — the CRM only drafts maintenance invoices.");
        }

        $infaktClientId = $client->external_ids['infakt'] ?? null;
        if ($infaktClientId === null) {
            throw new RuntimeException("Refusing: {$client->name} has no linked Infakt client id.");
        }

        $saleDate = $periodMonth->copy()->endOfMonth();

        $positions = $client->activeRetainersOn($saleDate)
            ->filter(fn (ClientRetainer $p): bool => $p->monthly_fee !== null && (float) $p->monthly_fee > 0);

        if ($positions->isEmpty()) {
            throw new RuntimeException("Refusing: no active fee-bearing abonament position for {$client->name} on {$saleDate->toDateString()}.");
        }

        $default = $client->maintenance_invoice_description ?: self::DEFAULT_MAINTENANCE_DESCRIPTION;

        return $positions
            ->groupBy('invoice_group')
            ->map(fn (Collection $group): array => ['invoice' => [
                'client_id' => (int) $infaktClientId,
                'sale_date' => $saleDate->toDateString(),
                'invoice_date' => Carbon::now()->toDateString(),
                'payment_method' => 'transfer',
                'services' => $group->map(fn (ClientRetainer $p): array => [
                    'name' => $p->description ?: ($p->label ?: $default),
                    'unit_net_price' => (int) round(((float) $p->monthly_fee) * 100),
                    'quantity' => 1,
                    'tax_symbol' => (string) ($p->vat_symbol ?: self::MAINTENANCE_VAT_SYMBOL),
                    'unit' => 'usł.',
                ])->values()->all(),
            ]])
            ->values()
            ->all();
    }

    /**
     * Build (but do not send) the FIRST draft-maintenance-invoice payload. Pure —
     * used for a dry-run preview and by callers expecting a single invoice
     * (single-position clients). Enforces the maintenance-only guardrails.
     *
     * @return array{invoice: array<string, mixed>}
     */
    public function buildMaintenanceInvoicePayload(Client $client, Carbon $periodMonth): array
    {
        return $this->buildMaintenanceInvoicePayloads($client, $periodMonth)[0];
    }

    /**
     * Fetch clients from Infakt with offset-based pagination.
     *
     * @return array{entities: Collection, total_count: int}
     *
     * @throws InfaktApiException on any non-2xx response
     */
    public function fetchClients(int $offset = 0, int $limit = 100): array
    {
        $response = $this->http->get('/clients.json', [
            'offset' => $offset,
            'limit' => $limit,
        ]);

        if (! $response->successful()) {
            Log::error('Failed to fetch clients from Infakt', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new InfaktApiException($response->status(), $response->body());
        }

        $data = $response->json();

        return [
            'entities' => collect($data['entities'] ?? []),
            'total_count' => $data['metainfo']['total_count'] ?? 0,
        ];
    }

    /**
     * Fetch all clients (paginated).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function fetchAllClients(): Collection
    {
        $allClients = collect();
        $offset = 0;
        $limit = 100;

        $result = $this->fetchClients($offset, $limit);
        $totalCount = $result['total_count'];
        $allClients = $allClients->merge($result['entities']);

        while ($allClients->count() < $totalCount) {
            $offset += $result['entities']->count();
            $result = $this->fetchClients($offset, $limit);

            if ($result['entities']->isEmpty()) {
                break;
            }

            $allClients = $allClients->merge($result['entities']);
        }

        return $allClients;
    }

    /**
     * Sync clients from Infakt to local database.
     *
     * @throws InfaktApiException when the Infakt API rejects the request
     *                            (auth, rate-limit, server error). On failure
     *                            the integration's last_sync_error is written
     *                            and last_synced_at is NOT bumped.
     */
    public function syncClients(int $accountId): array
    {
        try {
            $infaktClients = $this->fetchAllClients();
        } catch (InfaktApiException $e) {
            $this->integration->update(['last_sync_error' => $e->userMessage()]);

            throw $e;
        }

        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        foreach ($infaktClients as $infaktClient) {
            try {
                $this->syncClient($infaktClient, $accountId, $stats);
            } catch (Exception $e) {
                Log::error('Failed to sync Infakt client', [
                    'infakt_id' => $infaktClient['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
                $stats['errors']++;
            }
        }

        $this->integration->update([
            'last_synced_at' => now(),
            'last_sync_error' => null,
        ]);

        return $stats;
    }

    /**
     * Fetch invoices from Infakt with offset-based pagination. When
     * `$issuedSince` is given, only invoices on/after that date are returned
     * (Infakt Ransack filter `q[invoice_date_gteq]`).
     *
     * @return array{entities: Collection, total_count: int}
     *
     * @throws InfaktApiException on any non-2xx response
     */
    public function fetchInvoices(int $offset = 0, int $limit = 100, ?string $issuedSince = null): array
    {
        $params = ['offset' => $offset, 'limit' => $limit];
        if ($issuedSince !== null) {
            $params['q[invoice_date_gteq]'] = $issuedSince;
        }

        $response = $this->http->get('/invoices.json', $params);

        if (! $response->successful()) {
            Log::error('Failed to fetch invoices from Infakt', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new InfaktApiException($response->status(), $response->body());
        }

        $data = $response->json();

        return [
            'entities' => collect($data['entities'] ?? []),
            'total_count' => $data['metainfo']['total_count'] ?? 0,
        ];
    }

    /**
     * Fetch all invoices (paginated), optionally since a YYYY-MM-DD date.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function fetchAllInvoices(?string $issuedSince = null): Collection
    {
        $all = collect();
        $offset = 0;
        $limit = 100;

        $result = $this->fetchInvoices($offset, $limit, $issuedSince);
        $totalCount = $result['total_count'];
        $all = $all->merge($result['entities']);

        while ($all->count() < $totalCount) {
            $offset += $result['entities']->count();
            $result = $this->fetchInvoices($offset, $limit, $issuedSince);

            if ($result['entities']->isEmpty()) {
                break;
            }

            $all = $all->merge($result['entities']);
        }

        return $all;
    }

    /**
     * Fetch a single client's most recent invoices (line items / `services`
     * included), filtered server-side by Infakt client id. Used by the
     * month-close flow to read past maintenance amounts before drafting a new
     * one. Returns entities as Infakt sends them, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     *
     * @throws InfaktApiException on any non-2xx response
     */
    public function fetchClientInvoices(string $infaktClientId, int $limit = 25): Collection
    {
        $response = $this->http->get('/invoices.json', [
            'q[client_id_eq]' => $infaktClientId,
            'limit' => $limit,
        ]);

        if (! $response->successful()) {
            Log::error('Failed to fetch client invoices from Infakt', [
                'infakt_client_id' => $infaktClientId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new InfaktApiException($response->status(), $response->body());
        }

        return collect($response->json()['entities'] ?? [])
            ->sortByDesc('invoice_date')
            ->values();
    }

    /**
     * Sync invoices from Infakt into the local `invoices` table. Upserts on
     * (account_id, external_id); links each invoice to a local client by Infakt
     * client id (preferred) or NIP, leaving client_id null when neither matches.
     *
     * @return array{created: int, updated: int, errors: int}
     *
     * @throws InfaktApiException when the Infakt API rejects the request
     */
    public function syncInvoices(int $accountId, ?string $issuedSince = null): array
    {
        try {
            $invoices = $this->fetchAllInvoices($issuedSince);
        } catch (InfaktApiException $e) {
            $this->integration->update(['last_sync_error' => $e->userMessage()]);

            throw $e;
        }

        // Build client lookup maps once (infakt id + NIP → local client id) so
        // matching is O(1) per invoice instead of a query each.
        $clients = Client::where('account_id', $accountId)
            ->withTrashed()
            ->get(['id', 'tax_id', 'external_ids']);
        $byInfaktId = [];
        $byNip = [];
        foreach ($clients as $client) {
            $infaktId = $client->external_ids['infakt'] ?? null;
            if ($infaktId !== null) {
                $byInfaktId[(string) $infaktId] = $client->id;
            }
            if (! empty($client->tax_id)) {
                $byNip[$client->tax_id] = $client->id;
            }
        }

        $stats = ['created' => 0, 'updated' => 0, 'errors' => 0];

        foreach ($invoices as $infaktInvoice) {
            try {
                $clientId = $this->resolveInvoiceClientId($infaktInvoice, $byInfaktId, $byNip);
                $data = $this->mapInfaktInvoiceToLocal($infaktInvoice, $accountId, $clientId);

                $invoice = Invoice::updateOrCreate(
                    ['account_id' => $accountId, 'external_id' => $data['external_id']],
                    $data
                );

                $invoice->wasRecentlyCreated ? $stats['created']++ : $stats['updated']++;
            } catch (Exception $e) {
                Log::error('Failed to sync Infakt invoice', [
                    'infakt_id' => $infaktInvoice['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
                $stats['errors']++;
            }
        }

        $this->integration->update([
            'last_synced_at' => now(),
            'last_sync_error' => null,
        ]);

        return $stats;
    }

    /**
     * Single-shot read of an async invoice-creation task's status.
     *
     * @return array{status: int, body: mixed}
     */
    public function checkInvoiceTask(string $reference): array
    {
        $response = $this->http->get("/async/invoices/status/{$reference}.json");

        return [
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ];
    }

    /**
     * Fetch a single Infakt client by numeric id or UUID.
     *
     * @return array{status: int, body: mixed}
     */
    public function getClient(string $reference): array
    {
        $response = $this->http->get("/clients/{$reference}.json");

        return [
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ];
    }

    /**
     * Best-effort poll of the async invoice-creation task. Returns the last
     * status payload, or null if still processing after the attempts.
     *
     * @return array<string, mixed>|null
     */
    private function pollInvoiceTask(string $reference, int $attempts = 8): ?array
    {
        for ($i = 0; $i < $attempts; $i++) {
            $response = $this->http->get("/async/invoices/status/{$reference}.json");

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['invoice']) || isset($data['id']) || ($data['processing_code'] ?? null) === 200) {
                    return $data;
                }
            }

            usleep(1_000_000);
        }

        return null;
    }

    private function syncClient(array $infaktClient, int $accountId, array &$stats): void
    {
        $infaktId = (string) $infaktClient['id'];

        $existingClient = Client::where('account_id', $accountId)
            ->where(function ($query) use ($infaktId, $infaktClient) {
                $query->whereJsonContains('external_ids->infakt', $infaktId);

                if (! empty($infaktClient['nip'])) {
                    $query->orWhere('tax_id', $this->cleanNip($infaktClient['nip']));
                }
            })
            ->first();

        $clientData = $this->mapInfaktClientToLocal($infaktClient, $accountId);

        if ($existingClient) {
            $existingClient->update($clientData);
            $client = $existingClient;
            $stats['updated']++;
        } else {
            $client = Client::create($clientData);
            $stats['created']++;
        }

        // Infakt lists all of a client's addresses in one `email` field (often
        // comma-separated). The client keeps the first as its primary; any
        // further addresses become Contacts so nothing is lost or truncated.
        $this->syncClientContactsFromEmails($client, array_slice($this->parseEmails($infaktClient['email'] ?? null), 1));
    }

    /**
     * Split Infakt's `email` field (which may be a comma/semicolon-separated
     * list) into a trimmed, de-duplicated list of addresses.
     *
     * @return list<string>
     */
    private function parseEmails(?string $raw): array
    {
        if ($raw === null || mb_trim($raw) === '') {
            return [];
        }

        $emails = [];
        foreach (preg_split('/[,;]+/', $raw) ?: [] as $part) {
            $email = mb_trim($part);
            if ($email !== '' && ! in_array($email, $emails, true)) {
                $emails[] = $email;
            }
        }

        return $emails;
    }

    /**
     * Ensure a Contact exists on the client for each given email. Idempotent:
     * skips an address already present on one of the client's contacts. The
     * name is guessed from the address local-part (e.g. anna.kowalska ->
     * Anna Kowalska); the user can refine it later.
     *
     * @param  list<string>  $emails
     */
    private function syncClientContactsFromEmails(Client $client, array $emails): void
    {
        if ($emails === []) {
            return;
        }

        $seen = $client->contacts()
            ->withTrashed()
            ->get(['id', 'emails'])
            ->flatMap(fn (Contact $contact): array => $contact->emails ?? [])
            ->map(fn (string $email): string => mb_strtolower($email))
            ->all();

        foreach ($emails as $email) {
            if (in_array(mb_strtolower($email), $seen, true)) {
                continue;
            }

            $local = mb_strstr($email, '@', true) ?: $email;
            $parts = array_values(array_filter(preg_split('/[._-]+/', $local) ?: []));

            $client->contacts()->create([
                'account_id' => $client->account_id,
                'first_name' => isset($parts[0]) ? mb_convert_case($parts[0], MB_CASE_TITLE) : $email,
                'last_name' => isset($parts[1]) ? mb_convert_case($parts[1], MB_CASE_TITLE) : '',
                'emails' => [$email],
            ]);
            $seen[] = mb_strtolower($email);
        }
    }

    private function mapInfaktClientToLocal(array $infaktClient, int $accountId): array
    {
        $infaktId = (string) $infaktClient['id'];

        return [
            'account_id' => $accountId,
            'type' => 'business', // Infakt mainly deals with businesses
            'name' => $this->extractName($infaktClient),
            // Primary address only; further addresses become Contacts (see syncClient).
            'email' => $this->parseEmails($infaktClient['email'] ?? null)[0] ?? null,
            'phone' => $infaktClient['phone_number'] ?? null,
            'address' => $infaktClient['street'] ?? null,
            'city' => $infaktClient['city'] ?? null,
            'region' => null, // Infakt doesn't have region
            'country' => $this->mapCountryCode($infaktClient['country'] ?? 'PL'),
            'postal_code' => $infaktClient['postal_code'] ?? null,
            'tax_id' => $this->cleanNip($infaktClient['nip'] ?? null),
            'business_type' => null,
            'notes' => $infaktClient['note'] ?? null,
            'external_ids' => [
                'infakt' => $infaktId,
            ],
        ];
    }

    private function extractName(array $infaktClient): string
    {
        if (! empty($infaktClient['company_name']) && is_string($infaktClient['company_name'])) {
            return $this->normalizeName($infaktClient['company_name']);
        }

        $firstName = $infaktClient['first_name'] ?? '';
        $lastName = $infaktClient['last_name'] ?? '';
        $fullName = $this->normalizeName("$firstName $lastName");

        if ($fullName !== '') {
            return $fullName;
        }

        if (! empty($infaktClient['name']) && is_string($infaktClient['name'])) {
            return $this->normalizeName($infaktClient['name']);
        }

        return 'Unknown';
    }

    private function normalizeName(string $name): string
    {
        return mb_trim(preg_replace('/\s+/', ' ', str_replace('"', '', $name)));
    }

    private function cleanNip(?string $nip): ?string
    {
        if (empty($nip)) {
            return null;
        }

        return preg_replace('/[^0-9]/', '', $nip);
    }

    private function resolveInvoiceClientId(array $infaktInvoice, array $byInfaktId, array $byNip): ?int
    {
        $infaktClientId = isset($infaktInvoice['client_id']) ? (string) $infaktInvoice['client_id'] : null;
        if ($infaktClientId !== null && isset($byInfaktId[$infaktClientId])) {
            return $byInfaktId[$infaktClientId];
        }

        $nip = $this->cleanNip($infaktInvoice['client_tax_code'] ?? null);
        if ($nip !== null && isset($byNip[$nip])) {
            return $byNip[$nip];
        }

        return null;
    }

    /**
     * Map an Infakt invoice payload to local `invoices` columns. Monetary
     * fields stay as integer grosze exactly as Infakt returns them.
     *
     * @return array<string, mixed>
     */
    private function mapInfaktInvoiceToLocal(array $infaktInvoice, int $accountId, ?int $clientId): array
    {
        return [
            'account_id' => $accountId,
            'client_id' => $clientId,
            'external_id' => (string) $infaktInvoice['id'],
            'number' => $infaktInvoice['number'] ?? null,
            'status' => $infaktInvoice['status'] ?? null,
            'client_company_name' => $this->normalizeName((string) ($infaktInvoice['client_company_name'] ?? '')) ?: null,
            'client_tax_code' => $this->cleanNip($infaktInvoice['client_tax_code'] ?? null),
            'currency' => $infaktInvoice['currency'] ?? 'PLN',
            'net_price' => (int) ($infaktInvoice['net_price'] ?? 0),
            'gross_price' => (int) ($infaktInvoice['gross_price'] ?? 0),
            'tax_price' => (int) ($infaktInvoice['tax_price'] ?? 0),
            'paid_price' => (int) ($infaktInvoice['paid_price'] ?? 0),
            'left_to_pay' => (int) ($infaktInvoice['left_to_pay'] ?? 0),
            'invoice_date' => $infaktInvoice['invoice_date'] ?? null,
            'sale_date' => $infaktInvoice['sale_date'] ?? null,
            'payment_date' => $infaktInvoice['payment_date'] ?? null,
            'paid_date' => $infaktInvoice['paid_date'] ?? null,
        ];
    }

    private function mapCountryCode(?string $country): string
    {
        if (empty($country)) {
            return 'PL';
        }

        $countryMap = [
            'Polska' => 'PL',
            'Poland' => 'PL',
            'PL' => 'PL',
            'Niemcy' => 'DE',
            'Germany' => 'DE',
            'DE' => 'DE',
            'Wielka Brytania' => 'GB',
            'United Kingdom' => 'GB',
            'GB' => 'GB',
            'UK' => 'GB',
        ];

        return $countryMap[$country] ?? 'PL';
    }
}
