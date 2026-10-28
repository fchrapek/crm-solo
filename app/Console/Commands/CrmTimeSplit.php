<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\PrintsAgentLists;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\TimeEntryEditor;
use Illuminate\Console\Command;

/**
 * Agent verb: split one stopped time entry across tasks. Parts run end to end
 * from its start; what they leave stays on the entry unless --drop-rest.
 */
#[AccountScope(AccountScope::ACTING)]
final class CrmTimeSplit extends Command
{
    use PrintsAgentLists;

    protected $signature = 'crm:time-split {entry : Time entry id (crm time-entries lists them)}
        {--part=* : <task>:<minutes>[:<description>], once per part, in order; the task is an id or a name fragment without a colon}
        {--drop-rest : Stop logging the minutes the parts leave, instead of keeping them on the entry}
        {--json : Machine-readable output}';

    protected $description = 'Split a time entry across tasks, end to end from its start';

    public function handle(TimeEntryEditor $editor): int
    {
        $parts = [];
        foreach ((array) $this->option('part') as $index => $part) {
            $fields = explode(':', (string) $part, 3);
            if (count($fields) < 2) {
                return $this->refusal(AgentRefusal::invalid('Part '.($index + 1).' takes <task>:<minutes>[:<description>].', ['field' => 'parts']));
            }
            $parts[] = ['task' => $fields[0], 'minutes' => $fields[1], 'description' => $fields[2] ?? null];
        }

        try {
            $result = $editor->split($this->actingIdentity()->account->id, (string) $this->argument('entry'), $parts, (bool) $this->option('drop-rest'));
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson($result));

            return self::SUCCESS;
        }

        $this->raw('Split into '.count($result['entries']).' entries'
            .($result['remainder_minutes'] > 0 ? ", {$result['remainder_minutes']} min left on the original" : '')
            .($result['dropped_minutes'] > 0 ? ", {$result['dropped_minutes']} min dropped" : '').'.');
        $this->fenced(array_map(fn (array $entry): string => $this->timeEntryLine($entry), $result['entries']));

        return self::SUCCESS;
    }
}
