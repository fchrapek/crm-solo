<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\PrintsIntakeForms;
use App\Services\Agent\AgentRefusal;
use App\Services\Leads\IntakeForms;
use Illuminate\Console\Command;

/** Agent verb: the account's website forms with their notice counts; secrets show only as set or not. */
#[AccountScope(AccountScope::ACTING)]
final class CrmIntakeForms extends Command
{
    use PrintsIntakeForms;

    protected $signature = 'crm:intake-forms {--json : Machine-readable output}';

    protected $description = 'List the website forms posting leads: endpoint, origins, pipeline, source, notify-to, switch and notice counts; secrets are set on the server only';

    public function handle(IntakeForms $forms): int
    {
        try {
            $result = $forms->list($this->actingIdentity()->account->id);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson($result));

            return self::SUCCESS;
        }

        $this->raw("Forms: {$result['total']}.");
        foreach ($result['forms'] as $form) {
            $this->raw('  '.$this->formLine($form));
        }

        return self::SUCCESS;
    }
}
