<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Messaging\MessageSettings;
use Illuminate\Console\Command;

/** Agent verb: a template text as a client would get it, and how many SMS it takes; nothing is saved. */
#[AccountScope(AccountScope::ACTING)]
final class CrmMessagePreview extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:message-preview
        {body : Template text with client_name, assignee_name or field:KEY in curly braces}
        {--json : Machine-readable output}';

    protected $description = 'Preview a text message template filled from a client, with its SMS count';

    public function handle(MessageSettings $settings): int
    {
        try {
            $preview = $settings->preview($this->actingIdentity()->account->id, (string) $this->argument('body'));
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson(['preview' => [...$preview, 'untrusted' => ['text', 'client']]]));

            return self::SUCCESS;
        }

        $this->raw("{$preview['characters']} characters, {$preview['segments']} SMS".($preview['unicode'] ? ' (unicode)' : '').'.');
        $this->raw($this->literal($preview['text']));

        return self::SUCCESS;
    }
}
