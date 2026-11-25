<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Models\IntakeForm;
use App\Models\LeadIntake;
use Illuminate\Console\Command;

#[AccountScope(AccountScope::OPERATOR)]
final class LeadIntakeStatus extends Command
{
    protected $signature = 'lead-intake:status {--account= : Only this account}';

    protected $description = 'List website forms with their waiting, failed, sent and skipped lead notices (operator: every account unless --account)';

    public function handle(): int
    {
        if (config('app.demo')) {
            $this->error(__('The demo has no intake forms.'));

            return self::FAILURE;
        }

        $account = $this->option('account');
        if ($account !== null && ! ctype_digit((string) $account)) {
            $this->error(__('Name the account with --account=<id> of an existing account.'));

            return self::FAILURE;
        }

        $counts = LeadIntake::query()
            ->selectRaw('intake_form_id, notice_state, COUNT(*) AS total')
            ->groupBy('intake_form_id', 'notice_state')
            ->get()
            ->groupBy('intake_form_id');

        $rows = IntakeForm::query()
            ->when($account !== null, fn ($query) => $query->where('account_id', (int) $account))
            ->orderBy('account_id')->orderBy('id')
            ->get()
            ->map(function (IntakeForm $form) use ($counts): array {
                $states = ($counts->get($form->id) ?? collect())->pluck('total', 'notice_state');

                return [
                    $form->account_id,
                    $form->id,
                    $form->name,
                    $form->is_active ? __('yes') : __('no'),
                    (int) ($states[LeadIntake::WAITING] ?? 0),
                    (int) ($states[LeadIntake::FAILED] ?? 0),
                    (int) ($states[LeadIntake::SENT] ?? 0),
                    (int) ($states[LeadIntake::SKIPPED] ?? 0),
                ];
            });

        $this->table([__('Account'), __('Form'), __('Name'), __('Active'), __('Waiting notices'), __('Failed notices'), __('Sent notices'), __('Skipped notices')], $rows->all());

        return self::SUCCESS;
    }
}
