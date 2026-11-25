<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Models\IntakeForm;
use App\Services\Leads\IntakeFormSecrets;
use Illuminate\Console\Command;

#[AccountScope(AccountScope::OPERATOR)]
final class RotateIntakeFormWebhookSecret extends Command
{
    protected $signature = 'intake-form:webhook-secret
        {--account= : The id of the account the form belongs to}
        {--form= : The form id}
        {--clear : Remove the secret, so the form takes browser posts only}';

    protected $description = "Create or rotate a website form's webhook secret, shown once and stored as a hash (operator: names any account)";

    public function handle(IntakeFormSecrets $secrets): int
    {
        if (config('app.demo')) {
            $this->error(__('The demo has no intake forms. Nothing was changed.'));

            return self::FAILURE;
        }

        $account = (string) $this->option('account');
        $id = (string) $this->option('form');
        $form = ctype_digit($account) && ctype_digit($id)
            ? IntakeForm::query()->where('account_id', (int) $account)->whereKey((int) $id)->first()
            : null;
        if ($form === null) {
            $this->error(__('Name an existing form of that account with --account=<id> --form=<id>.'));

            return self::FAILURE;
        }

        if ($this->option('clear')) {
            $secrets->clearWebhook($form->account_id, $form->id);
            $this->info(__('Form :form has no webhook secret now; every request with Authorization is refused.', ['form' => $form->id]));

            return self::SUCCESS;
        }

        $secret = $secrets->rotateWebhook($form->account_id, $form->id);

        $this->info(__('Webhook secret of form :form, shown once; any earlier one stops working. Send it as Authorization: Bearer <secret>.', ['form' => $form->id]));
        $this->line($secret);

        return self::SUCCESS;
    }
}
