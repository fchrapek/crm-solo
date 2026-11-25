<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\Client;
use App\Models\ClientRetainer;
use App\Models\Contact;
use App\Models\InfaktDraftRequest;
use App\Models\Integration;
use App\Models\Invoice;
use App\Support\LocalCalendar;
use App\Support\Redaction;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

final class InfaktService
{
    /** Default line-item wording for a maintenance draft invoice (overridable per client). */
    public const DEFAULT_MAINTENANCE_DESCRIPTION = 'Utrzymanie strony i wsparcie techniczne';

    /** VAT rate for maintenance services (net retainer + 23% at invoice level). */
    public const MAINTENANCE_VAT_SYMBOL = '23';

    /**
     * The only fields a draft may carry, each with its type: a scalar type, `list:<type>` or the name of another
     * object below. A copy keeps everything Infakt's own copy keeps; nothing here issues, numbers, sends or marks an
     * invoice paid. Names from the v3 Invoice definition (Infakt API docs, Przychody, Faktury VAT).
     */
    public const array DRAFT_INVOICE_FIELDS = [
        'client_id' => 'int', 'sale_date' => 'date', 'invoice_date' => 'date', 'payment_method' => 'string', 'payment_date' => 'date',
        'currency' => 'string', 'vat_exchange_date_kind' => 'string', 'services' => 'list:line', 'notes' => 'string',
        'split_payment' => 'bool', 'split_payment_type' => 'string', 'recipient_signature' => 'string', 'seller_signature' => 'string',
        'bank_name' => 'string', 'bank_account' => 'string', 'swift' => 'string', 'sale_type' => 'string', 'invoice_date_kind' => 'string',
        'continuous_service_start_on' => 'date', 'continuous_service_end_on' => 'date', 'vat_date_value' => 'string',
        'occasional_sale' => 'bool', 'building_service' => 'bool', 'sales_kind' => 'string', 'vat_exemption_reason' => 'int',
        'bdo_code' => 'string', 'transaction_kind_id' => 'int', 'document_markings_ids' => 'list:int', 'not_income' => 'bool',
        'third_party_addresses' => 'list:address', 'transaction_terms' => 'terms', 'local_government_recipient_address' => 'address',
        'local_government_seller_address' => 'address',
    ];

    public const array DRAFT_LINE_FIELDS = [
        'name' => 'string', 'unit_net_price' => 'int', 'quantity' => 'number', 'tax_symbol' => 'code', 'unit' => 'string',
        'pkwiu' => 'string', 'cn' => 'string', 'pkob' => 'string', 'gtin' => 'string', 'flat_rate_tax_symbol' => 'code',
        'discount' => 'numeric', 'unit_net_price_before_discount' => 'int', 'gtu_id' => 'int',
    ];

    /** A Dodatkowy podmiot (third_party_addresses) and the JST address slots. */
    public const array DRAFT_ADDRESS_FIELDS = [
        'company_name' => 'string', 'tax_id' => 'string', 'street' => 'string', 'building_number' => 'string', 'door_number' => 'string',
        'post_code' => 'string', 'city' => 'string', 'country_code' => 'string', 'country' => 'string', 'role_code' => 'code',
        'internal_identifier' => 'string',
    ];

    public const array DRAFT_TERMS_FIELDS = ['contracts' => 'list:deal', 'orders' => 'list:deal'];

    /** A contract or an order: the one place a draft names a number. */
    public const array DRAFT_DEAL_FIELDS = ['date' => 'date', 'number' => 'string'];

    /** Refused at any depth of any payload, and never copied: identity, numbering, state, money received, KSeF, links. */
    public const array DRAFT_FORBIDDEN_FIELDS = [
        'id', 'uuid', 'parent_id', 'number', 'status', 'kind', 'paid_price', 'paid_date', 'left_to_pay', 'net_price', 'tax_price',
        'gross_price', 'client_uuid', 'check_duplicate_number', 'extensions', 'ksef_data', 'ksef_number', 'amount_in_words',
        'reconciliation_id', 'related_documents', 'receipt_number', 'created_at', 'updated_at', 'send_to_ksef', 'related_id',
        'symbol', '_destroy',
    ];

    /** Patterns of DRAFT_FORBIDDEN_FIELDS for names a later API version may add. */
    public const array DRAFT_FORBIDDEN_PATTERNS = ['/^ksef_/', '/_url$/', '/_at$/', '/(^|_)(sent|send|printed|delivered|paid)(_|$)/'];

    /**
     * Set fresh on a copy, never taken from the source. The buyer's snapshot is left out so Infakt fills it from
     * client_id, the client record as Infakt holds it now.
     */
    public const array DRAFT_REPLACED_FIELDS = [
        'invoice_date', 'sale_date', 'payment_date', 'client_id', 'continuous_service_start_on', 'continuous_service_end_on',
        'client_company_name', 'client_first_name', 'client_last_name', 'client_business_activity_kind', 'client_street',
        'client_street_number', 'client_flat_number', 'client_city', 'client_post_code', 'client_tax_code', 'client_country',
    ];

    private const array DRAFT_OBJECTS = [
        'invoice' => self::DRAFT_INVOICE_FIELDS,
        'line' => self::DRAFT_LINE_FIELDS,
        'address' => self::DRAFT_ADDRESS_FIELDS,
        'terms' => self::DRAFT_TERMS_FIELDS,
        'deal' => self::DRAFT_DEAL_FIELDS,
    ];

    private const BASE_URL = 'https://api.infakt.pl/v3';

    /** Client fields Infakt keeps current until the CRM edits them; the rest it only seeds. */
    private const SYNCED_FIELDS = ['name', 'email', 'phone', 'address', 'city', 'country', 'postal_code', 'tax_id', 'notes'];

    /** external_ids key holding the synced fields as Infakt last wrote them. */
    private const SYNCED_SNAPSHOT_KEY = 'infakt_synced';

    private PendingRequest $http;

    public function __construct(
        private readonly Integration $integration
    ) {
        $this->integration->assertSecretsReadable();

        $this->http = Http::baseUrl(self::BASE_URL)
            ->withHeaders([
                'X-inFakt-ApiKey' => $this->integration->api_key,
                'Content-Type' => 'application/json',
            ])
            ->timeout(30);
    }

    /**
     * Walks the payload by path: every object only its own fields, every value its type, lists as lists.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws RuntimeException when the payload holds a forbidden field, or anything off the draft schema
     */
    public static function assertDraftOnly(array $payload): void
    {
        if (array_keys($payload) !== ['invoice'] || ! is_array($payload['invoice'])) {
            throw new RuntimeException('Refusing to send an Infakt draft that is not a single invoice: the CRM only ever sends a draft.');
        }

        $forbidden = [];
        $wrong = self::schemaErrors($payload['invoice'], 'invoice', 'invoice', $forbidden);
        if ($forbidden !== []) {
            throw new RuntimeException('Refusing to send an Infakt draft with '.implode(', ', $forbidden).': a draft never carries a number, a state, a payment or a send.');
        }
        if ($wrong !== []) {
            throw new RuntimeException('Refusing to send an Infakt draft with '.implode(', ', $wrong).': the CRM only ever sends a draft built from its fixed fields.');
        }
    }

    /**
     * Why an invoice cannot be copied, or null: only an issued VAT invoice that is not a correction, an advance
     * (ZAL) or a final invoice.
     *
     * @param  array<string, mixed>  $invoice
     */
    public static function sourceRefusal(array $invoice): ?string
    {
        $number = (string) ($invoice['number'] ?? '');

        return match (self::sourceRefusalCode($invoice)) {
            'not_vat' => "{$number} is a {$invoice['kind']} invoice, not a VAT invoice",
            'draft' => ($number !== '' ? $number : 'it').' is still a draft in Infakt',
            'advance' => "{$number} is an advance, final or correction invoice",
            'settles_advance' => "{$number} settles an advance invoice",
            default => null,
        };
    }

    /**
     * sourceRefusal as a code the Billing block can name: not_vat, draft, advance, settles_advance, or null.
     *
     * @param  array<string, mixed>  $invoice
     */
    public static function sourceRefusalCode(array $invoice): ?string
    {
        $number = (string) ($invoice['number'] ?? '');

        return match (true) {
            ($invoice['kind'] ?? 'vat') !== 'vat' => 'not_vat',
            ($invoice['status'] ?? 'draft') === 'draft' || $number === '' => 'draft',
            preg_match('~(^|/)(ZAL|KOR|KON|FK)(/|$)~i', $number) === 1 => 'advance',
            ($invoice['parent_id'] ?? null) !== null => 'settles_advance',
            default => null,
        };
    }

    /**
     * The projects of each fee-bearing invoice group on the month's sale date, sorted: what names a group.
     *
     * @return array<int, list<int>>
     */
    public static function invoiceGroupProjects(Client $client, Carbon $periodMonth): array
    {
        return $client->activeRetainersOn($periodMonth->copy()->endOfMonth())
            ->filter(fn (ClientRetainer $p): bool => $p->monthly_fee !== null && (float) $p->monthly_fee > 0)
            ->groupBy(fn (ClientRetainer $p): int => (int) $p->invoice_group)
            ->map(fn (Collection $group): array => $group->pluck('project_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->sort()->values()->all())
            ->sortKeys()
            ->all();
    }

    public function testConnection(): bool
    {
        try {
            $response = $this->http->get('/clients.json', ['limit' => 1]);

            return $response->successful();
        } catch (Exception $e) {
            // The key travels in a header, and a malformed one comes back in the
            // client's own error text, so it is cut out before logging.
            Log::error('Infakt API connection test failed', [
                'error' => str_replace(Redaction::variants([(string) $this->integration->api_key]), '[redacted]', $e->getMessage()),
            ]);

            return false;
        }
    }

    /**
     * Create the DRAFT maintenance invoices for a client and month, one per
     * invoice group, through InfaktDraftRun: each request is recorded before
     * it is sent, so a second run resumes instead of drafting twice.
     *
     * Intentionally narrow: this is the ONLY invoice-writing path in the CRM.
     * It refuses any client that is not a `maintenance` client with an active
     * fee-bearing position and a linked Infakt id, and every invoice lands as
     * a `draft` (never issued, sent, printed or marked paid).
     *
     * @return list<array{group: int, outcome: string, payload: array<string, mixed>, task_reference: ?string, message: ?string}>
     *
     * @throws InfaktApiException on a non-2xx create response
     */
    public function createDraftMaintenanceInvoices(Client $client, Carbon $periodMonth, bool $resendUnconfirmed = false): array
    {
        return (new InfaktDraftRun($this))->run($client, $periodMonth, $resendUnconfirmed);
    }

    /**
     * Sends one draft request; Infakt answers with a task reference and builds
     * the draft asynchronously.
     *
     * @param  array{invoice: array<string, mixed>}  $payload
     *
     * @throws InfaktApiException on a non-2xx response
     */
    public function postDraftInvoice(array $payload): ?string
    {
        self::assertDraftOnly($payload);
        $response = $this->http->post('/async/invoices.json', $payload);

        if (! $response->successful()) {
            throw new InfaktApiException($response->status(), $response->body());
        }

        return $response->json('invoice_task_reference_number');
    }

    /**
     * One invoice as Infakt holds it now, services and Dodatkowy podmiot included; null when Infakt has no such invoice.
     *
     * @return array<string, mixed>|null
     *
     * @throws InfaktApiException on any other non-2xx response
     */
    public function fetchInvoice(string $uuid): ?array
    {
        $response = $this->http->get('/invoices/'.rawurlencode($uuid).'.json');
        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new InfaktApiException($response->status(), $response->body());
        }

        return (array) $response->json();
    }

    /**
     * The client's invoice with this number, read whole.
     *
     * @return array<string, mixed>|null
     *
     * @throws InfaktApiException
     */
    public function invoiceNumbered(string $infaktClientId, string $number): ?array
    {
        $response = $this->http->get('/invoices.json', ['q[client_id_eq]' => $infaktClientId, 'q[number_eq]' => $number, 'limit' => 10]);
        if (! $response->successful()) {
            throw new InfaktApiException($response->status(), $response->body());
        }
        $listed = collect($response->json()['entities'] ?? [])
            ->first(fn (array $i): bool => ($i['number'] ?? null) === $number && (string) ($i['client_id'] ?? $infaktClientId) === $infaktClientId);

        return isset($listed['uuid']) ? $this->fetchInvoice((string) $listed['uuid']) : null;
    }

    /**
     * The client's invoices a copy may start from (VAT, issued, not an advance or final), newest first, as listed:
     * what the Billing picker offers and what `last` takes the first of.
     *
     * @return Collection<int, array<string, mixed>>
     *
     * @throws InfaktApiException
     */
    public function copyableInvoices(string $infaktClientId, int $pages = 3): Collection
    {
        $found = collect();
        for ($page = 0; $page < $pages; $page++) {
            $response = $this->http->get('/invoices.json', [
                'q[client_id_eq]' => $infaktClientId, 'q[kind_eq]' => 'vat', 'order' => 'invoice_date DESC', 'offset' => $page * 100, 'limit' => 100,
            ]);
            if (! $response->successful()) {
                throw new InfaktApiException($response->status(), $response->body());
            }
            $entities = collect($response->json()['entities'] ?? []);
            $found = $found->merge($entities->filter(fn (array $i): bool => (string) ($i['client_id'] ?? '') === $infaktClientId && self::sourceRefusal($i) === null));
            if ($entities->count() < 100) {
                break;
            }
        }

        return $found->sortByDesc(fn (array $i): string => ($i['invoice_date'] ?? '').'#'.mb_str_pad((string) ($i['id'] ?? 0), 12, '0', STR_PAD_LEFT))->values();
    }

    /**
     * Wystaw podobna: the source invoice whole, minus DRAFT_FORBIDDEN_FIELDS and DRAFT_REPLACED_FIELDS, with the
     * billed month's sale date, today's issue date and the source's payment term from today.
     *
     * @param  array<string, mixed>  $source  a GET of one invoice
     * @return array{invoice: array<string, mixed>}
     *
     * @throws RuntimeException when the source's payment term cannot be carried over
     */
    public function copyInvoicePayload(array $source, string $infaktClientId, Carbon $periodMonth): array
    {
        $copy = self::withoutForbidden($source);
        foreach (self::DRAFT_REPLACED_FIELDS as $field) {
            unset($copy[$field]);
        }
        if (($copy['services'] ?? []) === []) {
            throw new RuntimeException("Refusing: Infakt sent invoice {$source['number']} without its lines.");
        }
        // A contract or an order keeps only its date and number; anything else on it, its id included, is not copied.
        $deals = fn (mixed $list): array => array_values(array_filter(array_map(
            fn (mixed $deal): array => is_array($deal) ? array_filter(array_intersect_key($deal, self::DRAFT_DEAL_FIELDS), fn (mixed $v): bool => $v !== null && $v !== '') : [],
            is_array($list) ? $list : [],
        )));
        $terms = array_filter(array_map($deals, array_intersect_key((array) ($copy['transaction_terms'] ?? []), self::DRAFT_TERMS_FIELDS)));
        unset($copy['transaction_terms']);
        if ($terms !== []) {
            $copy['transaction_terms'] = $terms;
        }
        $copy['services'] = array_map(fn (array $line): array => [
            ...$line,
            'quantity' => self::quantity($line['quantity'] ?? 1),
        ], array_values($copy['services']));

        $today = LocalCalendar::today();
        $saleDate = $periodMonth->copy()->endOfMonth();
        $method = (string) ($source['payment_method'] ?? 'transfer');
        $term = isset($source['payment_date'], $source['invoice_date'])
            ? (int) round(Carbon::parse((string) $source['invoice_date'])->diffInDays(Carbon::parse((string) $source['payment_date']), false))
            : null;
        if ($term !== null && ($term < 0 || ($term === 0 && ! in_array($method, ['cash', 'card'], true)))) {
            throw new RuntimeException("Refusing: invoice {$source['number']} is due {$term} days after it was issued; fix the term in Infakt or pick another invoice.");
        }
        $continuous = ($source['invoice_date_kind'] ?? null) === 'continuous_service_end_on'
            ? ['continuous_service_start_on' => $periodMonth->copy()->startOfMonth()->toDateString(), 'continuous_service_end_on' => $saleDate->toDateString()]
            : [];
        $currency = mb_strtoupper((string) ($source['currency'] ?? 'PLN'));

        $invoice = [
            ...$copy,
            'client_id' => (int) $infaktClientId,
            'sale_date' => $saleDate->toDateString(),
            'invoice_date' => $today->toDateString(),
            ...($term !== null ? ['payment_date' => $today->copy()->addDays($term)->toDateString()] : []),
            ...$continuous,
            'currency' => $currency,
        ];
        if ($currency !== 'PLN' && ! isset($invoice['vat_exchange_date_kind'])) {
            $invoice['vat_exchange_date_kind'] = $this->vatExchangeDateKind();
        }

        return ['invoice' => $invoice];
    }

    /**
     * What an async creation task says now: created (201, with the invoice
     * uuid), failed (422), or pending (100 accepted, 140 processing, or no
     * readable answer).
     *
     * @return array{state: string, invoice_uuid: ?string, error: ?string}
     */
    public function draftTaskState(string $reference): array
    {
        $response = $this->http->get("/async/invoices/status/{$reference}.json");
        $data = $response->successful() ? (array) $response->json() : [];
        $code = (int) ($data['processing_code'] ?? 0);

        // Only 201 (created) and 422 (could not create) are final in Infakt's docs; anything else is still open.
        return match (true) {
            $code === 201 || isset($data['invoice_uuid']) => [
                'state' => 'created',
                'invoice_uuid' => $data['invoice_uuid'] ?? ($data['invoice']['uuid'] ?? null),
                'error' => null,
            ],
            $code === 422 => [
                'state' => 'failed',
                'invoice_uuid' => null,
                'error' => mb_trim(($data['processing_description'] ?? 'rejected').' '.json_encode($data['invoice_errors'] ?? [], JSON_UNESCAPED_UNICODE)),
            ],
            default => ['state' => 'pending', 'invoice_uuid' => null, 'error' => null],
        };
    }

    /**
     * Polls a fresh task for a few seconds; a draft still pending after that
     * is reconciled by the next run.
     *
     * @return array{state: string, invoice_uuid: ?string, error: ?string}
     */
    public function awaitDraft(string $reference, int $attempts = 8): array
    {
        for ($i = 0; $i < $attempts; $i++) {
            $state = $this->draftTaskState($reference);
            if ($state['state'] !== 'pending') {
                return $state;
            }
            Sleep::for(1)->second();
        }

        return ['state' => 'pending', 'invoice_uuid' => null, 'error' => null];
    }

    /**
     * Build (but do not send) the draft payloads for a client — one per invoice
     * group. Pure; used for dry-run preview and by createDraftMaintenanceInvoices.
     * Reads the client's active retainer positions: positions sharing an
     * `invoice_group` land on one invoice, different groups become separate
     * invoices. Only fee-bearing positions are billed — a 0-fee position (e.g.
     * an included dev-hours allowance) is tracked for reports but never emitted
     * as a line.
     *
     * @return array<int, array{invoice: array<string, mixed>}>
     */
    public function buildMaintenanceInvoicePayloads(Client $client, Carbon $periodMonth): array
    {
        return array_values($this->maintenanceInvoiceGroups($client, $periodMonth));
    }

    /**
     * The draft payloads keyed by invoice group, the key a draft request is recorded under.
     *
     * @return array<int, array{invoice: array<string, mixed>}>
     */
    public function maintenanceInvoiceGroups(Client $client, Carbon $periodMonth): array
    {
        $infaktClientId = $this->assertDraftableClient($client);

        $saleDate = $periodMonth->copy()->endOfMonth();

        $positions = $client->activeRetainersOn($saleDate)
            ->filter(fn (ClientRetainer $p): bool => $p->monthly_fee !== null && (float) $p->monthly_fee > 0);

        if ($positions->isEmpty()) {
            throw new RuntimeException("Refusing: no active fee-bearing retainer position for {$client->name} on {$saleDate->toDateString()}.");
        }

        $default = $client->maintenance_invoice_description ?: self::DEFAULT_MAINTENANCE_DESCRIPTION;

        $currency = mb_strtoupper($client->currency ?: 'PLN');
        // Infakt requires the exchange-rate date kind on any invoice not in PLN.
        $foreign = $currency !== 'PLN' ? ['vat_exchange_date_kind' => $this->vatExchangeDateKind()] : [];

        return $positions
            ->groupBy(fn (ClientRetainer $p): int => (int) $p->invoice_group)
            ->map(fn (Collection $group): array => ['invoice' => [
                'client_id' => (int) $infaktClientId,
                'sale_date' => $saleDate->toDateString(),
                'invoice_date' => LocalCalendar::today()->toDateString(),
                'payment_method' => 'transfer',
                'currency' => $currency,
                ...$foreign,
                'services' => $group->map(fn (ClientRetainer $p): array => [
                    'name' => $p->description ?: ($p->label ?: $default),
                    'unit_net_price' => (int) round(((float) $p->monthly_fee) * 100),
                    'quantity' => 1,
                    'tax_symbol' => (string) ($p->vat_symbol ?: self::MAINTENANCE_VAT_SYMBOL),
                    'unit' => 'usł.',
                ])->values()->all(),
            ]])
            ->sortKeys()
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
     *
     * @return array{created: int, updated: int, skipped: int, errors: int, conflicts: list<string>}
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
            'conflicts' => [],
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

        // Kept until a sync finds none, so the integration page shows what still needs a human.
        $this->integration->update([
            'last_synced_at' => now(),
            'last_sync_error' => null,
            'settings' => [...($this->integration->settings ?? []), 'client_conflicts' => $stats['conflicts']],
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
     * Forgets drafts Infakt no longer holds: the mirrored row goes and so does the draft request, so the month
     * reads as never drafted. Each draft is asked for by its own id and only a 404 counts. Nothing is forgotten
     * unless the oldest issued invoice this account knows still answers, which proves the key reads the same company.
     *
     * @return int how many drafts were forgotten
     */
    public function forgetDeletedDrafts(int $accountId): int
    {
        $known = Invoice::query()->where('account_id', $accountId)->whereNotNull('external_uuid')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'draft'))->orderBy('id')->value('external_uuid');
        try {
            if ($known === null || $this->fetchInvoice((string) $known) === null) {
                return 0;
            }
        } catch (Throwable) {
            return 0;
        }

        $drafted = InfaktDraftRequest::query()->where('account_id', $accountId)->where('status', InfaktDraftRequest::STATUS_CREATED)->whereNotNull('invoice_uuid')->pluck('invoice_uuid');
        $mirrored = Invoice::query()->where('account_id', $accountId)->where('status', 'draft')->whereNotNull('external_uuid')->pluck('external_uuid');
        $forgotten = 0;

        foreach ($drafted->merge($mirrored)->unique() as $uuid) {
            try {
                if ($this->fetchInvoice((string) $uuid) !== null) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }

            DB::transaction(function () use ($accountId, $uuid): void {
                InfaktDraftRequest::query()->where('account_id', $accountId)->where('invoice_uuid', $uuid)->where('status', InfaktDraftRequest::STATUS_CREATED)->delete();
                Invoice::query()->where('account_id', $accountId)->where('external_uuid', $uuid)->where('status', 'draft')->delete();
            });
            $forgotten++;
        }

        return $forgotten;
    }

    /**
     * One invoice into the local mirror, as the nightly sync would write it.
     *
     * @param  array<string, mixed>  $infaktInvoice
     */
    public function mirrorInvoice(array $infaktInvoice, Client $client): Invoice
    {
        $data = $this->mapInfaktInvoiceToLocal($infaktInvoice, $client->account_id, $client->id);

        return Invoice::updateOrCreate(['account_id' => $client->account_id, 'external_id' => $data['external_id']], $data);
    }

    /**
     * Invoices Infakt already holds for an Infakt client with this sale date,
     * drafts included. Infakt is asked rather than the local invoices table,
     * which only learns about a draft at the next sync.
     *
     * @return Collection<int, array<string, mixed>>
     *
     * @throws InfaktApiException on any non-2xx response
     */
    public function invoicesWithSaleDate(string $infaktClientId, string $saleDate): Collection
    {
        $response = $this->http->get('/invoices.json', [
            'q[client_id_eq]' => $infaktClientId,
            'q[sale_date_eq]' => $saleDate,
            'limit' => 100,
        ]);

        if (! $response->successful()) {
            throw new InfaktApiException($response->status(), $response->body());
        }

        return collect($response->json()['entities'] ?? [])
            ->filter(fn (array $invoice): bool => (string) ($invoice['client_id'] ?? $infaktClientId) === $infaktClientId
                && ($invoice['sale_date'] ?? null) === $saleDate)
            ->values();
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

    public function assertDraftableClient(Client $client): string
    {
        if ($client->month_close_type !== 'maintenance') {
            throw new RuntimeException("Refusing: {$client->name} is not a maintenance client — the CRM only drafts maintenance invoices.");
        }

        $infaktClientId = $client->external_ids['infakt'] ?? null;
        if ($infaktClientId === null) {
            throw new RuntimeException("Refusing: {$client->name} has no linked Infakt client id.");
        }

        return (string) $infaktClientId;
    }

    /**
     * @param  list<string>  $forbidden  paths of forbidden fields, filled as found
     * @return list<string> paths off the schema or of the wrong type
     */
    private static function schemaErrors(mixed $value, string $type, string $path, array &$forbidden): array
    {
        if (str_starts_with($type, 'list:')) {
            if (! is_array($value) || ! array_is_list($value)) {
                return ["{$path} (not a list)"];
            }
            $errors = [];
            foreach ($value as $index => $item) {
                $errors = [...$errors, ...self::schemaErrors($item, mb_substr($type, 5), "{$path}[{$index}]", $forbidden)];
            }

            return $errors;
        }

        if (isset(self::DRAFT_OBJECTS[$type])) {
            if (! is_array($value) || ($value !== [] && array_is_list($value))) {
                return ["{$path} (not an object)"];
            }
            $errors = [];
            foreach ($value as $key => $item) {
                $at = "{$path}.{$key}";
                $allowed = self::DRAFT_OBJECTS[$type][$key] ?? null;
                if ($allowed === null) {
                    if (is_string($key) && self::isForbidden($key)) {
                        $forbidden[] = $at;
                    } else {
                        $errors[] = $at;
                    }

                    continue;
                }
                $errors = [...$errors, ...self::schemaErrors($item, $allowed, $at, $forbidden)];
            }

            return $errors;
        }

        $fits = match ($type) {
            'int' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'numeric' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'code' => is_int($value) || (is_string($value) && $value !== ''),
            'bool' => is_bool($value),
            'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1,
            default => is_string($value),
        };

        return $fits ? [] : ["{$path} (not {$type})"];
    }

    private static function isForbidden(string $key): bool
    {
        return in_array($key, self::DRAFT_FORBIDDEN_FIELDS, true)
            || collect(self::DRAFT_FORBIDDEN_PATTERNS)->contains(fn (string $pattern): bool => preg_match($pattern, $key) === 1);
    }

    /**
     * @param  array<array-key, mixed>  $fields
     * @return array<array-key, mixed>
     */
    private static function withoutForbidden(array $fields): array
    {
        $kept = [];
        foreach ($fields as $key => $value) {
            if (is_string($key) && self::isForbidden($key)) {
                continue;
            }
            if (is_array($value) && $key !== 'transaction_terms') {
                $value = self::withoutForbidden($value);
            }
            // Empty values are what Infakt shows for an unset field; sending them back could clear or fail one.
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $kept[$key] = $value;
        }

        return array_is_list($fields) ? array_values($kept) : $kept;
    }

    private static function quantity(mixed $quantity): int|float
    {
        $value = (float) $quantity;

        return floor($value) === $value ? (int) $value : $value;
    }

    private static function comparable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function vatExchangeDateKind(): string
    {
        $kind = (string) config('services.infakt.vat_exchange_date_kind', 'vat');
        if (! in_array($kind, ['vat', 'pit'], true)) {
            throw new RuntimeException("Refusing: services.infakt.vat_exchange_date_kind is \"{$kind}\"; Infakt accepts vat or pit.");
        }

        return $kind;
    }

    private function syncClient(array $infaktClient, int $accountId, array &$stats): void
    {
        $infaktId = (string) $infaktClient['id'];
        $nip = $this->cleanNip($infaktClient['nip'] ?? null);

        $byInfaktId = Client::withTrashed()
            ->where('account_id', $accountId)
            ->whereJsonContains('external_ids->infakt', $infaktId)
            ->orderBy('id')
            ->first();

        if ($byInfaktId !== null) {
            // A client deleted in the CRM stays deleted, whatever else matches.
            if ($byInfaktId->trashed()) {
                $stats['skipped']++;

                return;
            }
            $client = $byInfaktId;
        } else {
            $client = null;
            if ($nip !== null && $nip !== '') {
                $byNip = Client::withTrashed()
                    ->where('account_id', $accountId)
                    ->where('tax_id', $nip)
                    ->orderByRaw('deleted_at is null desc')
                    ->orderBy('id')
                    ->get();

                // Only a client with no Infakt id yet can be linked by NIP.
                $unlinked = $byNip->first(fn (Client $candidate): bool => empty($candidate->external_ids['infakt'] ?? null));

                if ($unlinked?->trashed()) {
                    $stats['skipped']++;

                    return;
                }

                if ($unlinked === null && $byNip->isNotEmpty()) {
                    $other = $byNip->first();
                    $stats['conflicts'][] = sprintf(
                        'Infakt client %s (NIP %s) matches CRM client #%d, already linked to Infakt client %s; not linked.',
                        $infaktId,
                        $nip,
                        $other->id,
                        (string) $other->external_ids['infakt'],
                    );
                    Log::warning('Infakt client matches a CRM client linked to another Infakt client by NIP', [
                        'infakt_id' => $infaktId,
                        'client_id' => $other->id,
                        'linked_infakt_id' => (string) $other->external_ids['infakt'],
                    ]);

                    return;
                }

                $client = $unlinked;
            }
        }

        $clientData = $this->mapInfaktClientToLocal($infaktClient, $accountId);

        if ($client !== null) {
            $this->applyInfaktFields($client, $clientData, $infaktId);
            $stats['updated']++;
        } else {
            $client = Client::create([
                ...$clientData,
                'external_ids' => [
                    'infakt' => $infaktId,
                    self::SYNCED_SNAPSHOT_KEY => $this->snapshotOf($clientData),
                ],
            ]);
            $stats['created']++;
        }

        // Infakt lists all of a client's addresses in one `email` field (often
        // comma-separated). The client keeps the first as its primary; any
        // further addresses become Contacts so nothing is lost or truncated.
        $this->syncClientContactsFromEmails($client, array_slice($this->parseEmails($infaktClient['email'] ?? null), 1));
    }

    /**
     * Infakt seeds a client; after that it updates a field only while the CRM
     * value is still the one Infakt last wrote. The baseline of a field moves
     * only when the sync writes that field, so a value the owner typed never
     * becomes "what Infakt wrote", even when Infakt later reports the same
     * value. A client imported before the baseline existed gets one only for
     * the fields whose CRM value already equals Infakt's.
     *
     * @param  array<string, mixed>  $clientData
     */
    private function applyInfaktFields(Client $client, array $clientData, string $infaktId): void
    {
        $externalIds = is_array($client->external_ids) ? $client->external_ids : [];
        $lastSynced = is_array($externalIds[self::SYNCED_SNAPSHOT_KEY] ?? null) ? $externalIds[self::SYNCED_SNAPSHOT_KEY] : null;
        $incoming = $this->snapshotOf($clientData);
        $baseline = $lastSynced ?? [];

        foreach (self::SYNCED_FIELDS as $field) {
            $current = self::comparable($client->getAttribute($field));

            if ($lastSynced === null) {
                if ($current === $incoming[$field]) {
                    $baseline[$field] = $incoming[$field];
                }

                continue;
            }

            if (array_key_exists($field, $lastSynced) && $lastSynced[$field] === $current) {
                $client->setAttribute($field, $clientData[$field]);
                $baseline[$field] = $incoming[$field];
            }
        }

        $client->external_ids = [
            ...$externalIds,
            'infakt' => $infaktId,
            self::SYNCED_SNAPSHOT_KEY => $baseline,
        ];
        $client->save();
    }

    /**
     * Each synced field as the client model reads it back, so a cast that
     * rewrites on save (notes are humanized) does not look like an edit.
     *
     * @param  array<string, mixed>  $clientData
     * @return array<string, string|null>
     */
    private function snapshotOf(array $clientData): array
    {
        $probe = new Client;
        $snapshot = [];

        foreach (self::SYNCED_FIELDS as $field) {
            $probe->setAttribute($field, $clientData[$field]);
            $snapshot[$field] = self::comparable($probe->getAttribute($field));
        }

        return $snapshot;
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
            'external_uuid' => $infaktInvoice['uuid'] ?? null,
            'kind' => $infaktInvoice['kind'] ?? null,
            'parent_external_id' => isset($infaktInvoice['parent_id']) ? (string) $infaktInvoice['parent_id'] : null,
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
