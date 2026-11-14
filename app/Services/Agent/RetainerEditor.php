<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientRetainer;
use App\Services\Clients\RetainerChangeRefused;
use App\Services\Clients\RetainerPositions;
use App\Support\LocalCalendar;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** The agent side of RetainerPositions: adds, changes and ends an abonament position inside the identity's account. */
final class RetainerEditor
{
    public const array FIELDS = ['project_id', 'label', 'description', 'monthly_hours', 'monthly_fee', 'overage_hourly_rate', 'rollover_cap_hours', 'invoice_group', 'vat_symbol', 'currency', 'effective_from', 'notes'];

    public function __construct(
        private readonly RetainerPositions $positions,
        private readonly CrmEntityResolver $resolver,
        private readonly MonthCloseReader $months,
    ) {}

    /**
     * Without $id a new position; with it, only the fields given change and a null clears one.
     *
     * @param  array<string, mixed>  $fields  keys from FIELDS
     * @return array{client: array{id: int, name: string}, retainer: array<string, mixed>, message: string}
     *
     * @throws AgentRefusal
     * @throws ReferenceException
     */
    public function set(Account $account, string $clientNeedle, ?int $id, array $fields): array
    {
        $this->months->assertBilling($account);
        $client = $this->resolver->client($clientNeedle, $account->id);
        $fields = array_intersect_key($fields, array_flip(self::FIELDS));

        if ($id === null) {
            $retainer = $this->write(fn (): ClientRetainer => $this->positions->create($client, $this->validated($client, $fields)));

            return $this->result($client, $retainer, 'Position added.');
        }

        $retainer = $this->position($client, $id);
        $merged = [...array_intersect_key($retainer->toPayload(), array_flip(self::FIELDS)), ...$fields];
        $data = array_intersect_key($this->validated($client, $merged), $fields);
        $retainer = $this->write(fn (): ClientRetainer => $this->positions->update($client, $retainer, $data));

        return $this->result($client, $retainer, $data === [] ? '' : 'Position updated.');
    }

    /**
     * Ends a position on $to, the first day it no longer applies (default: the 1st of next month).
     *
     * @return array{client: array{id: int, name: string}, retainer: array<string, mixed>, message: string}
     *
     * @throws AgentRefusal
     * @throws ReferenceException
     */
    public function end(Account $account, string $clientNeedle, int $id, ?string $to): array
    {
        $this->months->assertBilling($account);
        $client = $this->resolver->client($clientNeedle, $account->id);
        $retainer = $this->position($client, $id);
        $to ??= LocalCalendar::monthFrom(LocalCalendar::nextMonth(LocalCalendar::currentMonth()))->toDateString();
        $this->validated($client, ['monthly_hours' => (float) $retainer->monthly_hours, 'effective_to' => $to]);
        $retainer = $this->write(fn (): ClientRetainer => $this->positions->update($client, $retainer, ['effective_to' => $to]));

        return $this->result($client, $retainer, "Position ends on {$to} (the first day it no longer applies).");
    }

    private function position(Client $client, int $id): ClientRetainer
    {
        $retainer = ClientRetainer::query()->where('client_id', $client->id)->where('account_id', $client->account_id)->find($id);
        if ($retainer === null) {
            throw AgentRefusal::invalid("{$client->name} has no retainer position #{$id}; crm brief lists them.", ['client_id' => $client->id]);
        }

        return $retainer;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(Client $client, array $data): array
    {
        $validator = Validator::make($data, RetainerPositions::rules($client));
        if ($validator->fails()) {
            throw AgentRefusal::invalid($validator->errors()->first(), ['fields' => $validator->errors()->keys()]);
        }

        return $validator->validated();
    }

    /** @param  callable(): ClientRetainer  $write */
    private function write(callable $write): ClientRetainer
    {
        try {
            return $write();
        } catch (RetainerChangeRefused $refused) {
            throw new AgentRefusal(AgentRefusal::REFUSED, $refused->getMessage());
        } catch (ValidationException $invalid) {
            throw AgentRefusal::invalid((string) collect($invalid->errors())->flatten()->first());
        }
    }

    /** @return array{client: array{id: int, name: string}, retainer: array<string, mixed>, message: string} */
    private function result(Client $client, ClientRetainer $retainer, string $message): array
    {
        return ['client' => ['id' => $client->id, 'name' => $client->name], 'retainer' => $retainer->toPayload(), 'message' => $message];
    }
}
