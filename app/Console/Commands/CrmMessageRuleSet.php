<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Messaging\MessageSettings;
use Illuminate\Console\Command;

/** Agent verb: add, change or delete when a template goes out. */
#[AccountScope(AccountScope::ACTING)]
final class CrmMessageRuleSet extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:message-rule-set
        {key : Rule key, e.g. visit_reminder}
        {--template= : Template key}
        {--trigger= : field_value (when a field takes a value) or date_offset (some time before or after a date field)}
        {--field= : The client field the rule watches}
        {--value=* : field_value: option keys that trigger it (none: any value)}
        {--offset= : date_offset: -2d, -48h, -30m or 0 (negative is before)}
        {--at= : date_offset: send at this local time, HH:MM}
        {--expire= : Minutes after the planned time a message may still go out}
        {--enable : Turn the rule on}
        {--disable : Turn the rule off}
        {--delete : Delete the rule; its waiting messages are withdrawn}
        {--json : Machine-readable output}';

    protected $description = 'Add, change or delete a text message rule (trigger, field, timing)';

    public function handle(MessageSettings $settings): int
    {
        if ($this->option('delete')) {
            try {
                $settings->deleteRule($this->actingIdentity()->account->id, (string) $this->argument('key'));
            } catch (AgentRefusal $e) {
                return $this->refusal($e);
            }
            $this->wantsJson() ? $this->raw($this->encodeJson(['deleted' => (string) $this->argument('key')])) : $this->raw('Deleted rule '.$this->literal((string) $this->argument('key')).'.');

            return self::SUCCESS;
        }

        $offset = null;
        if ($this->option('offset') !== null) {
            if (! preg_match('/^([+-]?\d+)([dhm]?)$/', (string) $this->option('offset'), $m)) {
                return $this->refusal(AgentRefusal::invalid('--offset is a number with d, h or m, e.g. -2d.'));
            }
            $offset = (int) $m[1] * ['d' => 1440, 'h' => 60, 'm' => 1, '' => 1][$m[2]];
        }

        try {
            $rule = $settings->rule($this->actingIdentity()->account->id, (string) $this->argument('key'), [
                'template' => $this->option('template'),
                'trigger' => $this->option('trigger'),
                'field' => $this->option('field'),
                'values' => $this->option('value') !== [] ? (array) $this->option('value') : null,
                'offset_minutes' => $offset,
                'at' => $this->option('at'),
                'expire_minutes' => $this->option('expire') !== null ? (int) $this->option('expire') : null,
                'enabled' => $this->option('enable') ? true : ($this->option('disable') ? false : null),
            ]);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson(['rule' => $rule->toAgentArray()]));

            return self::SUCCESS;
        }

        $this->raw('Saved rule '.$this->literal($rule->key).($rule->enabled ? '' : ' (off)').'.');

        return self::SUCCESS;
    }
}
