<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Models\IntakeForm;
use App\Services\Agent\AgentRefusal;
use App\Services\Leads\IntakeFormSecrets;
use App\Support\SecretInput;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[AccountScope(AccountScope::OPERATOR)]
final class SetIntakeFormTurnstile extends Command
{
    protected $signature = 'intake-form:turnstile
        {--account= : The id of the account the form belongs to}
        {--form= : The form id}
        {--clear : Remove the secret instead of reading one from stdin}';

    protected $description = "Set a website form's Turnstile secret, read from stdin only (operator: names any account)";

    public function handle(SecretInput $input, IntakeFormSecrets $secrets): int
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
            $secrets->clearTurnstile($form->account_id, $form->id);
            $this->info(__('Form :form has no Turnstile secret now; outside local it refuses every browser post.', ['form' => $form->id]));

            return self::SUCCESS;
        }

        try {
            $secret = $input->line();
        } catch (InvalidArgumentException $e) {
            $this->error(__(':reason Nothing was changed.', ['reason' => $e->getMessage()]));

            return self::FAILURE;
        }
        if ($secret === null || $secret === '') {
            $this->error(__('Pipe the Turnstile secret on stdin (docker compose exec -T), one line of at most 255 characters. Nothing was changed.'));

            return self::FAILURE;
        }

        try {
            $secrets->setTurnstile($form->account_id, $form->id, $secret);
        } catch (AgentRefusal $e) {
            $this->error(__(':reason Nothing was changed.', ['reason' => $e->getMessage()]));

            return self::FAILURE;
        }
        $this->info(__('The Turnstile secret of form :form is stored encrypted.', ['form' => $form->id]));

        return self::SUCCESS;
    }
}
