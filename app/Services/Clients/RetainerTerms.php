<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\ClientRetainer;
use App\Support\LocalCalendar;
use Closure;
use Illuminate\Support\Carbon;

/**
 * A retainer position change may not move what leads on the 1st of an elapsed month that owes a close, closed or not,
 * nor of any month up to the latest closure, where it would make a month owed behind a closed one. Later months are free.
 */
final class RetainerTerms
{
    public function __construct(
        private readonly ClientFinancialLock $lock,
        private readonly HoursBank $bank,
    ) {}

    /**
     * Applies $change under the client lock and rolls it back when it reaches a settled month.
     *
     * @param  Closure(Client): void  $change
     * @return string|null why the change was refused, or null when it was saved
     */
    public function change(Client $client, Closure $change): ?string
    {
        try {
            return $this->lock->client($client->id, function (Client $client) use ($change): ?string {
                $state = $this->bank->settlement($client);
                $latest = $state->latestClosure()?->period;
                $settled = $latest !== null ? LocalCalendar::monthsBetween($state->baseline, LocalCalendar::nextMonth($latest)) : [];
                $months = array_values(array_unique([...$state->eligible, ...$settled]));
                $before = $this->leading($client, $months);
                $change($client);
                $after = $this->leading($client, $months);

                foreach ($months as $period) {
                    if ($before[$period] !== $after[$period]) {
                        throw new RetainerTermsSettled($period);
                    }
                }

                return null;
            });
        } catch (RetainerTermsSettled $settled) {
            return __(':Month is closed or being settled; change the retainer from next month on.', ['month' => LocalCalendar::monthName($settled->period)]);
        }
    }

    /**
     * @param  list<string>  $months
     * @return array<string, ?array{int, string, ?string}>
     */
    private function leading(Client $client, array $months): array
    {
        $leading = [];
        foreach ($months as $period) {
            $position = ClientRetainer::query()
                ->where('client_id', $client->id)
                ->leadingOn(Carbon::parse(LocalCalendar::monthFrom($period)->toDateString()))
                ->first(['id', 'monthly_hours', 'rollover_cap_hours']);
            $leading[$period] = $position !== null ? [$position->id, (string) $position->monthly_hours, $position->rollover_cap_hours !== null ? (string) $position->rollover_cap_hours : null] : null;
        }

        return $leading;
    }
}
