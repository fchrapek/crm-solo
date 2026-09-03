<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Client;
use App\Models\MonthCloseRun;
use App\Models\MonthCloseStep;
use App\Models\User;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The month-close checklist as a service: start the run if needed, move one
 * step, recompute the run status. Shared by the CLI verb and the MCP tool so
 * "tick a step" means the same thing from a terminal and from chat.
 */
final class MonthCloseTicker
{
    /**
     * @param  string|null  $projectNeedle  site id or name fragment; required when
     *                                      the step key repeats across sites
     * @param  string|null  $note  why — a skipped step without a reason tells the
     *                             next run nothing about what still has to happen
     * @return array<string, mixed> the checklist after the move
     */
    public function tick(Client $client, string $period, string $stepKey, string $state, ?User $actor = null, ?string $projectNeedle = null, ?string $note = null): array
    {
        $this->assertInClose($client);
        $this->assertPeriod($period);

        $valid = [MonthCloseStep::STATE_DONE, MonthCloseStep::STATE_SKIPPED, MonthCloseStep::STATE_PENDING];

        if (! in_array($state, $valid, true)) {
            throw new InvalidArgumentException('state must be one of: '.implode(', ', $valid));
        }

        $run = MonthCloseRun::startFor($client, $period);
        $step = $this->resolveStep($run, $stepKey, $projectNeedle);

        $isPending = $state === MonthCloseStep::STATE_PENDING;
        $attributes = [
            'state' => $state,
            'completed_at' => $isPending ? null : now(),
            'completed_by' => $isPending ? null : $actor?->id,
        ];

        // Only overwrite the note when one is given, so re-ticking a step does
        // not silently erase the reason someone recorded earlier.
        if ($note !== null) {
            $attributes['note'] = $note;
        }

        $step->update($attributes);
        $run->refreshStatusFromSteps();

        return $this->checklist($run->fresh());
    }

    /**
     * Read-only view of a period. Never creates the run — a status read must not
     * open a close as a side effect.
     *
     * @return array<string, mixed>
     */
    public function status(Client $client, string $period): array
    {
        $this->assertInClose($client);
        $this->assertPeriod($period);

        $run = $client->monthCloseRuns()->where('period', $period)->first();

        if ($run === null) {
            $template = MonthCloseRun::stepTemplateFor($client);

            return [
                'client_id' => $client->id,
                'client' => $client->name,
                'period' => $period,
                'close_type' => $client->month_close_type,
                'status' => 'not_started',
                'resolved' => 0,
                'total' => count($template),
                'steps' => collect($template)
                    ->map(fn (array $step, int $index): array => [
                        'key' => $step['key'],
                        'project' => $step['project'],
                        'state' => MonthCloseStep::STATE_PENDING,
                        'position' => $index,
                    ])->all(),
            ];
        }

        return $this->checklist($run);
    }

    /**
     * @return array<string, mixed>
     */
    public function checklist(MonthCloseRun $run): array
    {
        $run->load('steps.project:id,name', 'client:id,name');

        return [
            'client_id' => $run->client_id,
            'client' => $run->client?->name,
            'period' => $run->period,
            'close_type' => $run->close_type,
            'status' => $run->status,
            'resolved' => $run->steps->where('state', '!=', MonthCloseStep::STATE_PENDING)->count(),
            'total' => $run->steps->count(),
            'steps' => $run->steps->map(fn (MonthCloseStep $step): array => [
                'key' => $step->step_key,
                'project' => $step->project?->name,
                'state' => $step->state,
                'position' => $step->position,
            ])->values()->all(),
        ];
    }

    public function defaultPeriod(): string
    {
        return Carbon::now()->subMonthNoOverflow()->format('Y-m');
    }

    /**
     * Find the one step a (key, site) pair names. Site steps repeat per site, so
     * a bare key is ambiguous the moment a client runs more than one — report the
     * candidates rather than guessing which site was meant, the same way client
     * and task references behave elsewhere in the agent surface.
     */
    private function resolveStep(MonthCloseRun $run, string $stepKey, ?string $projectNeedle): MonthCloseStep
    {
        $matches = $run->steps()->with('project:id,name')->where('step_key', $stepKey)->get();

        if ($matches->isEmpty()) {
            throw new InvalidArgumentException(
                "Step '{$stepKey}' is not in this run. Available: ".$run->steps()->pluck('step_key')->unique()->implode(', ')
            );
        }

        if ($projectNeedle !== null && $projectNeedle !== '') {
            $needle = mb_strtolower($projectNeedle);

            $scoped = $matches->filter(fn (MonthCloseStep $step): bool => ctype_digit($projectNeedle)
                ? (int) $step->project_id === (int) $projectNeedle
                : str_contains(mb_strtolower((string) $step->project?->name), $needle));

            if ($scoped->isEmpty()) {
                throw new InvalidArgumentException(
                    "No '{$stepKey}' step for site '{$projectNeedle}'. Sites: ".$this->siteList($matches)
                );
            }

            if ($scoped->count() > 1) {
                throw new InvalidArgumentException(
                    "Site '{$projectNeedle}' matches several sites: ".$this->siteList($scoped)
                );
            }

            return $scoped->first();
        }

        if ($matches->count() > 1) {
            throw new InvalidArgumentException(
                "Step '{$stepKey}' exists for several sites — pass the site. Sites: ".$this->siteList($matches)
            );
        }

        return $matches->first();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, MonthCloseStep>  $steps
     */
    private function siteList($steps): string
    {
        return $steps
            ->map(fn (MonthCloseStep $step): string => sprintf('%s (#%d)', $step->project?->name ?? 'client-level', (int) $step->project_id))
            ->implode(', ');
    }

    private function assertInClose(Client $client): void
    {
        if ($client->month_close_type === null) {
            throw new InvalidArgumentException("{$client->name} is not in monthly close (no month_close_type set).");
        }
    }

    private function assertPeriod(string $period): void
    {
        if (preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
            throw new InvalidArgumentException('Invalid period (expected YYYY-MM).');
        }
    }
}
