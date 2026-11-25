<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Models\Account;
use App\Services\Agent\AgentRefusal;
use App\Services\Leads\IntakeForms;
use Illuminate\Console\Command;

#[AccountScope(AccountScope::OPERATOR)]
final class CreateIntakeForm extends Command
{
    protected $signature = 'intake-form:create
        {--account= : The id of the account the leads belong to}
        {--name= : What the form is, e.g. "Contact drawer"}
        {--pipeline= : An existing lead pipeline}
        {--source= : A lead source valid for the account}
        {--origin=* : An allowed website origin, e.g. https://www.example.test (repeat for each)}
        {--notify-to= : Address the lead notice goes to}';

    protected $description = 'Create a website form posting leads to /intake/{key}; set its Turnstile secret with intake-form:turnstile (operator: names any account)';

    public function handle(IntakeForms $forms): int
    {
        if (config('app.demo')) {
            $this->error(__('The demo has no intake forms. Nothing was changed.'));

            return self::FAILURE;
        }

        $raw = (string) $this->option('account');
        if (! ctype_digit($raw) || ! Account::query()->whereKey((int) $raw)->exists()) {
            $this->error(__('Name the account with --account=<id> of an existing account.'));

            return self::FAILURE;
        }

        $notifyTo = mb_trim((string) $this->option('notify-to'));
        try {
            $form = $forms->create((int) $raw, IntakeForms::onlyGiven([
                'name' => (string) $this->option('name'),
                'pipeline' => (string) $this->option('pipeline'),
                'source' => (string) $this->option('source'),
                'origins' => (array) $this->option('origin'),
                'notify_to' => $notifyTo === '' ? null : $notifyTo,
            ]));
        } catch (AgentRefusal $e) {
            $this->error(__(':reason Nothing was changed.', ['reason' => $e->getMessage()]));

            return self::FAILURE;
        }

        $this->info(__('Form :form of account :account posts to /intake/:key from :origins.', [
            'form' => $form['id'],
            'account' => $raw,
            'key' => basename((string) $form['endpoint']),
            'origins' => implode(', ', $form['origins']),
        ]));

        return self::SUCCESS;
    }
}
