<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * An Infakt account in memory, for the endpoints the drafting path calls: invoice list and show, the async draft
 * POST and its status. A posted draft becomes a draft invoice the next GET can read.
 */
trait FakesInfaktInvoices
{
    /** @var array<string, array<string, mixed>> */
    private array $infaktInvoices = [];

    /** @param  list<array<string, mixed>>  $invoices */
    private function fakeInfaktInvoices(array $invoices): void
    {
        Http::preventStrayRequests();
        foreach ($invoices as $invoice) {
            $this->infaktInvoices[(string) $invoice['uuid']] = $invoice;
        }

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $q = (array) ($query['q'] ?? []);

            if ($request->method() === 'POST' && $path === '/v3/async/invoices.json') {
                $reference = 'ref-'.(count($this->infaktInvoices) + 1);
                $this->infaktInvoices["draft-{$reference}"] = [
                    ...$request->data()['invoice'],
                    'id' => 20000900 + count($this->infaktInvoices), 'uuid' => "draft-{$reference}", 'number' => null, 'status' => 'draft', 'kind' => 'vat',
                    'net_price' => collect($request->data()['invoice']['services'])->sum(fn (array $l): int => (int) round($l['unit_net_price'] * $l['quantity'])),
                ];

                return Http::response(['invoice_task_reference_number' => $reference, 'processing_code' => 100], 201);
            }
            if (preg_match('~^/v3/async/invoices/status/(.+)\.json$~', $path, $m) === 1) {
                return Http::response(['processing_code' => 201, 'invoice_uuid' => "draft-{$m[1]}"]);
            }
            if (preg_match('~^/v3/invoices/(.+)\.json$~', $path, $m) === 1) {
                return isset($this->infaktInvoices[$m[1]]) ? Http::response($this->infaktInvoices[$m[1]]) : Http::response(['error' => 'not found'], 404);
            }
            if ($path === '/v3/invoices.json') {
                $listed = collect($this->infaktInvoices)
                    ->filter(fn (array $i): bool => (! isset($q['client_id_eq']) || (string) $i['client_id'] === (string) $q['client_id_eq'])
                        && (! isset($q['number_eq']) || ($i['number'] ?? null) === $q['number_eq'])
                        && (! isset($q['kind_eq']) || ($i['kind'] ?? 'vat') === $q['kind_eq'])
                        && (! isset($q['sale_date_eq']) || ($i['sale_date'] ?? null) === $q['sale_date_eq']))
                    ->sortByDesc(fn (array $i): string => (string) ($i['invoice_date'] ?? ''))
                    ->values();

                return Http::response(['entities' => $listed->slice((int) ($query['offset'] ?? 0), (int) ($query['limit'] ?? 10))->values()->all(), 'metainfo' => ['total_count' => $listed->count()]]);
            }

            return Http::response(['error' => "unexpected {$request->method()} {$path}"], 500);
        });
    }

    /** @return Collection<int, Request> */
    private function infaktPosts(): Collection
    {
        return Http::recorded(fn (Request $request): bool => $request->method() !== 'GET')->map(fn (array $pair): Request => $pair[0])->values();
    }

    /**
     * An issued VAT invoice of the client, fields as the v3 show endpoint names them.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function issuedInvoice(string $uuid, string $number, int $net, string $line, array $overrides = []): array
    {
        return [
            'id' => 20000100 + count($this->infaktInvoices), 'uuid' => $uuid, 'number' => $number, 'kind' => 'vat', 'status' => 'sent',
            'client_id' => 10000002, 'currency' => 'PLN', 'payment_method' => 'transfer', 'invoice_date' => '2026-09-30', 'sale_date' => '2026-09-30',
            'payment_date' => '2026-10-14', 'net_price' => $net, 'paid_price' => 0, 'left_to_pay' => $net,
            'services' => [['id' => 1, 'name' => $line, 'tax_symbol' => '23', 'unit' => 'usł.', 'quantity' => 1, 'unit_net_price' => $net, 'net_price' => $net]],
            ...$overrides,
        ];
    }
}
