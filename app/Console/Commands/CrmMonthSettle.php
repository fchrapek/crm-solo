<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\MonthCloseReader;
use App\Services\Agent\ReferenceException;
use Illuminate\Console\Command;

/** Agent verb: what closing a client's month would freeze, with the preview hash. Only the preview for now. */
#[AccountScope(AccountScope::ACTING)]
final class CrmMonthSettle extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:month-settle
                            {client : Client id or name fragment}
                            {period : Month YYYY-MM}
                            {--preview : Show what closing the month would freeze, without closing it}
                            {--json : Machine-readable output}';

    protected $description = 'Preview the financial close of a client month: figures, hash and why it cannot close';

    public function handle(MonthCloseReader $reader, CrmEntityResolver $resolver): int
    {
        if (! $this->option('preview')) {
            return $this->refusal(AgentRefusal::invalid('Only --preview is available: closing a month is done in the app for now.'));
        }

        try {
            $preview = $reader->settlementPreview($resolver->client((string) $this->argument('client'), $this->actingIdentity()->account->id), (string) $this->argument('period'));
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson($preview));

            return self::SUCCESS;
        }

        $this->raw($this->literal($preview['client'])." · closing {$preview['period']} would freeze:");
        $this->raw('  '.($preview['contracted'] ?? '-')." contracted, {$preview['opening']} carried, {$preview['used']} used ({$preview['source']}), {$preview['forfeited']} forfeited, {$preview['closing']} carries into {$preview['next']}");
        $this->raw("  hash {$preview['hash']}");
        $this->raw($preview['refusal'] !== null ? '  cannot close: '.$this->literal($preview['refusal']) : '  can close');

        return self::SUCCESS;
    }
}
