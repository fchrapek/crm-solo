<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Services\Agent\AgentRefusal;
use App\Services\Leads\IntakeForms;
use Illuminate\Console\Command;

#[AccountScope(AccountScope::OPERATOR)]
final class SetIntakeForm extends Command
{
    protected $signature = 'intake-form:set
        {--account= : The id of the account the form belongs to}
        {--form= : The form id}
        {--active= : yes or no}
        {--name= : A new name}
        {--pipeline= : An existing lead pipeline}
        {--source= : A lead source valid for the account}
        {--origin=* : The allowed origins, replacing the list (repeat for each)}
        {--notify-to= : Address the lead notice goes to}';

    protected $description = "Change a website form's switch, name, pipeline, source, origins or notify-to address; only the options given change (operator: names any account)";

    public function handle(IntakeForms $forms): int
    {
        if (config('app.demo')) {
            $this->error(__('The demo has no intake forms. Nothing was changed.'));

            return self::FAILURE;
        }

        $account = (string) $this->option('account');
        $id = (string) $this->option('form');
        if (! ctype_digit($account) || ! ctype_digit($id)) {
            $this->error(__('Name an existing form of that account with --account=<id> --form=<id>.'));

            return self::FAILURE;
        }

        $active = $this->option('active');
        try {
            $form = $forms->edit((int) $account, (int) $id, IntakeForms::onlyGiven([
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
            $this->error(__(':reason Nothing was changed.', ['reason' => $e->getMessage()]));

            return self::FAILURE;
        }

        $this->info(__('Form :form of account :account: :active, :name, origins :origins, notices to :notify.', [
            'form' => $form['id'],
            'account' => $account,
            'active' => $form['active'] ? __('active') : __('inactive'),
            'name' => $form['name'],
            'origins' => implode(', ', $form['origins']),
            'notify' => $form['notify_to'] ?? __('nobody'),
        ]));

        return self::SUCCESS;
    }
}
