<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Messaging\MessageSettings;
use Illuminate\Console\Command;

/** Agent verb: add, change or delete a text message template. */
#[AccountScope(AccountScope::ACTING)]
final class CrmMessageTemplateSet extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:message-template-set
        {key : Template key, e.g. visit_booked}
        {--name= : Name shown in the app}
        {--body= : Text; placeholders in curly braces: client_name, assignee_name, field:KEY}
        {--delete : Delete the template (refused while a rule uses it)}
        {--json : Machine-readable output}';

    protected $description = 'Add, change or delete a text message template';

    public function handle(MessageSettings $settings): int
    {
        if ($this->option('delete')) {
            try {
                $settings->deleteTemplate($this->actingIdentity()->account->id, (string) $this->argument('key'));
            } catch (AgentRefusal $e) {
                return $this->refusal($e);
            }
            $this->wantsJson() ? $this->raw($this->encodeJson(['deleted' => (string) $this->argument('key')])) : $this->raw('Deleted template '.$this->literal((string) $this->argument('key')).'.');

            return self::SUCCESS;
        }

        try {
            $template = $settings->template($this->actingIdentity()->account->id, (string) $this->argument('key'), $this->option('name'), $this->option('body'));
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson(['template' => ['key' => $template->key, 'name' => $template->name, 'body' => $template->body, 'untrusted' => ['name', 'body']]]));

            return self::SUCCESS;
        }

        $this->raw('Saved template '.$this->literal($template->key).'.');

        return self::SUCCESS;
    }
}
