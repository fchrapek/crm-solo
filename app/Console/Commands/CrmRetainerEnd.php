<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\RetainerEditor;
use Illuminate\Console\Command;

/** Agent verb: end an abonament position; nothing is deleted, elapsed months keep their terms. */
#[AccountScope(AccountScope::ACTING)]
final class CrmRetainerEnd extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:retainer-end
                            {client : Client id or name fragment}
                            {id : Position id from crm brief}
                            {--to= : First day it no longer applies, YYYY-MM-DD (default: the 1st of next month)}
                            {--json : Machine-readable output}';

    protected $description = 'End a retainer position from a date (exclusive); never deletes it';

    public function handle(RetainerEditor $editor): int
    {
        if (! ctype_digit((string) $this->argument('id'))) {
            return $this->refusal(AgentRefusal::invalid('The id is a position id; crm brief lists them.'));
        }

        try {
            $result = $editor->end($this->actingIdentity()->account, (string) $this->argument('client'), (int) $this->argument('id'), $this->option('to') !== null ? (string) $this->option('to') : null);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson($result));

            return self::SUCCESS;
        }

        $this->raw($result['message'].' '.$this->literal($result['client']['name'])." · position #{$result['retainer']['id']}");

        return self::SUCCESS;
    }
}
