<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\PrintsIntakeForms;
use App\Services\Agent\AgentRefusal;
use App\Services\Leads\IntakeForms;
use Illuminate\Console\Command;

/** Agent verb: a new website form with its non-secret settings; the Turnstile and webhook secrets stay server commands. */
#[AccountScope(AccountScope::ACTING)]
final class CrmIntakeFormCreate extends Command
{
    use PrintsIntakeForms;

    protected $signature = 'crm:intake-form-create
        {--name= : What the form is, e.g. "Contact drawer"}
        {--pipeline= : An existing lead pipeline}
        {--source= : A lead source valid for the account}
        {--origin=* : An allowed website origin, e.g. https://www.example.test (repeat for each)}
        {--notify-to= : Address the lead notice goes to}
        {--json : Machine-readable output}';

    protected $description = 'Create a website form posting leads (non-secret settings only; the Turnstile and webhook secrets and the SMTP password are set on the server, never through a verb)';

    public function handle(IntakeForms $forms): int
    {
        try {
            $form = $forms->create($this->actingIdentity()->account->id, IntakeForms::onlyGiven([
                'name' => $this->option('name'),
                'pipeline' => $this->option('pipeline'),
                'source' => $this->option('source'),
                'origins' => (array) $this->option('origin'),
                'notify_to' => $this->option('notify-to'),
            ]));
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson(['form' => $form]));

            return self::SUCCESS;
        }

        $this->raw('Created form '.$this->formLine($form));
        $this->raw('A browser form also needs its Turnstile secret, set by the owner on the server (intake-form:turnstile).');

        return self::SUCCESS;
    }
}
