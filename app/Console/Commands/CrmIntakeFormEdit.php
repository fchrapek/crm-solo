<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\PrintsIntakeForms;
use App\Services\Agent\AgentRefusal;
use App\Services\Leads\IntakeForms;
use Illuminate\Console\Command;

/** Agent verb: change a website form's non-secret settings; only the options given change. */
#[AccountScope(AccountScope::ACTING)]
final class CrmIntakeFormEdit extends Command
{
    use PrintsIntakeForms;

    protected $signature = 'crm:intake-form-edit
        {form : The form id}
        {--name= : A new name}
        {--pipeline= : An existing lead pipeline}
        {--source= : A lead source valid for the account}
        {--origin=* : The allowed origins, replacing the list (repeat for each)}
        {--notify-to= : Address the lead notice goes to}
        {--active= : yes or no}
        {--json : Machine-readable output}';

    protected $description = 'Change a website form: name, pipeline, source, origins, notify-to or the switch (never a secret: those are set on the server)';

    public function handle(IntakeForms $forms): int
    {
        $id = (string) $this->argument('form');
        $active = $this->option('active');

        try {
            if (! ctype_digit($id)) {
                throw AgentRefusal::invalid(__('No form #:form in this account.', ['form' => $id]));
            }
            $form = $forms->edit($this->actingIdentity()->account->id, (int) $id, IntakeForms::onlyGiven([
                'name' => $this->option('name'),
                'pipeline' => $this->option('pipeline'),
                'source' => $this->option('source'),
                'origins' => (array) $this->option('origin'),
                'notify_to' => $this->option('notify-to'),
                'active' => match ($active) {
                    'yes' => true,
                    'no' => false,
                    default => $active,
                },
            ]));
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson(['form' => $form]));

            return self::SUCCESS;
        }

        $this->raw('Changed form '.$this->formLine($form));

        return self::SUCCESS;
    }
}
