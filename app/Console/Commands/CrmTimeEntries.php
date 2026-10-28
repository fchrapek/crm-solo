<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\PrintsAgentLists;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\TimeEntryList;
use Illuminate\Console\Command;

/**
 * Agent verb: list time entries, newest first, by task, client, local day or
 * range, or only the running ones. Entry text is fenced, and listed under
 * `untrusted` in JSON.
 */
#[AccountScope(AccountScope::ACTING)]
final class CrmTimeEntries extends Command
{
    use PrintsAgentLists;

    protected $signature = 'crm:time-entries
        {--task= : Task id or name fragment}
        {--client= : Client id or name fragment}
        {--day= : One local day: today, yesterday or YYYY-MM-DD}
        {--from= : First local day, inclusive}
        {--to= : Last local day, inclusive}
        {--running : Only running timers}
        {--limit= : At most this many (default 50, up to 200)}
        {--json : Machine-readable output}';

    protected $description = 'List time entries: by task, client, local day or range, or running';

    public function handle(TimeEntryList $entries): int
    {
        try {
            $result = $entries->list($this->actingIdentity()->account->id, [
                'task' => $this->option('task'),
                'client' => $this->option('client'),
                'day' => $this->option('day'),
                'from' => $this->option('from'),
                'to' => $this->option('to'),
                'running' => (bool) $this->option('running'),
                'limit' => $this->option('limit'),
            ]);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson($result));

            return self::SUCCESS;
        }

        $this->raw('Time entries: '.count($result['entries']).' of '.$result['total'].', '.$result['total_minutes'].' min in all. Times are '.$result['timezone'].'.');
        $lines = array_map(fn (array $entry): string => $this->timeEntryLine($entry), $result['entries']);
        $this->fenced($lines !== [] ? $lines : ['  none']);

        return self::SUCCESS;
    }
}
