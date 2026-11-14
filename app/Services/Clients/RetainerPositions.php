<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\ClientRetainer;
use App\Support\LocalCalendar;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Adds and changes a client's abonament positions, for the abonament dialog and the agent verbs alike. Positions are
 * independent: adding one never closes the others. Every change goes through RetainerTerms, so a settled month keeps
 * the terms it was settled on.
 */
final class RetainerPositions
{
    public function __construct(private readonly RetainerTerms $terms) {}

    /** @return array<string, list<mixed>> */
    public static function rules(Client $client): array
    {
        return [
            'project_id' => [
                'nullable', 'integer',
                Rule::exists('projects', 'id')->where(fn ($q) => $q->where('client_id', $client->id)),
            ],
            'label' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'monthly_hours' => ['required', 'numeric', 'min:0', 'max:744'],
            'monthly_fee' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'overage_hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'rollover_cap_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'invoice_group' => ['nullable', 'integer', 'min:1', 'max:50'],
            'vat_symbol' => ['nullable', 'string', 'max:8'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated against rules()
     *
     * @throws RetainerChangeRefused
     */
    public function create(Client $client, array $data): ClientRetainer
    {
        $created = null;
        $this->apply($client, function (Client $locked) use ($data, &$created): void {
            $created = $locked->retainers()->create([
                'account_id' => $locked->account_id,
                'project_id' => $data['project_id'] ?? null,
                'label' => $data['label'] ?? null,
                'description' => $data['description'] ?? null,
                'monthly_hours' => $data['monthly_hours'],
                'monthly_fee' => $data['monthly_fee'] ?? null,
                'overage_hourly_rate' => $data['overage_hourly_rate'] ?? null,
                'rollover_cap_hours' => $data['rollover_cap_hours'] ?? null,
                'invoice_group' => $data['invoice_group'] ?? 1,
                'vat_symbol' => $data['vat_symbol'] ?? '23',
                'is_active' => $data['is_active'] ?? true,
                'sort_order' => $data['sort_order'] ?? 0,
                'currency' => $data['currency'] ?? $locked->currency ?? 'PLN',
                'effective_from' => Carbon::parse($data['effective_from'] ?? LocalCalendar::today()->toDateString())->startOfDay(),
                'effective_to' => null,
                'notes' => $data['notes'] ?? null,
            ]);
        });

        // A client with an abonament position defaults into the maintenance close cohort.
        if ($client->month_close_type === null) {
            $client->update(['month_close_type' => Client::MONTH_CLOSE_TYPES[0]]);
        }

        return $created;
    }

    /**
     * A partial update: a field not in $data keeps its value, one present as null is cleared.
     *
     * @param  array<string, mixed>  $data  validated against rules()
     *
     * @throws RetainerChangeRefused
     * @throws ValidationException
     */
    public function update(Client $client, ClientRetainer $retainer, array $data): ClientRetainer
    {
        $changes = Arr::only($data, ['project_id', 'label', 'description', 'monthly_hours', 'monthly_fee', 'overage_hourly_rate', 'rollover_cap_hours', 'invoice_group', 'vat_symbol', 'is_active', 'sort_order', 'currency', 'notes']);
        foreach (['invoice_group' => 1, 'vat_symbol' => '23', 'is_active' => true, 'sort_order' => 0, 'currency' => $retainer->currency] as $key => $default) {
            if (array_key_exists($key, $changes) && $changes[$key] === null) {
                $changes[$key] = $default;
            }
        }
        if (isset($data['effective_from'])) {
            $changes['effective_from'] = Carbon::parse($data['effective_from'])->startOfDay();
        }
        if (array_key_exists('effective_to', $data)) {
            $changes['effective_to'] = $this->endDate($retainer, $data['effective_to'], $changes['effective_from'] ?? $retainer->effective_from);
        }

        $this->apply($client, fn () => $retainer->update($changes));

        return $retainer->refresh();
    }

    /** @throws RetainerChangeRefused */
    private function apply(Client $client, callable $change): void
    {
        $refusal = $this->terms->change($client, $change(...));
        if ($refusal !== null) {
            throw new RetainerChangeRefused($refusal);
        }
    }

    /**
     * The end date is exclusive; moving it only from this month's 1st on keeps every elapsed month's obligation.
     *
     * @throws ValidationException
     */
    private function endDate(ClientRetainer $retainer, mixed $value, Carbon $from): ?Carbon
    {
        $to = $value !== null ? Carbon::parse((string) $value)->startOfDay() : null;
        $firstOfMonth = LocalCalendar::monthFrom(LocalCalendar::currentMonth())->toDateString();
        $stored = $retainer->effective_to?->toDateString();
        if ($to?->toDateString() !== $stored) {
            if ($stored !== null && $stored < $firstOfMonth) {
                throw ValidationException::withMessages(['effective_to' => __('This position ended before the current month; add a new position instead.')]);
            }
            if ($to !== null && $to->toDateString() < $firstOfMonth) {
                throw ValidationException::withMessages(['effective_to' => __('A position ends from the 1st of the current month or later.')]);
            }
        }
        if ($to !== null && $to->lte($from)) {
            throw ValidationException::withMessages(['effective_to' => __('A position ends after it starts.')]);
        }

        return $to;
    }
}
