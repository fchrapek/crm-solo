<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Services\Messaging\MessageDispatcher;
use Illuminate\Console\Command;

#[AccountScope(AccountScope::OPERATOR)]
final class DispatchMessages extends Command
{
    protected $signature = 'messages:dispatch {--limit=100 : Most messages handled in one run}';

    protected $description = 'Send the text messages that are due, for every account (operator command, scheduled every minute).';

    public function handle(MessageDispatcher $dispatcher): int
    {
        $counts = $dispatcher->dispatchDue(max(1, (int) $this->option('limit')));
        if ($counts !== []) {
            $this->info(collect($counts)->map(fn (int $n, string $status): string => "{$status}: {$n}")->implode(', '));
        }

        return self::SUCCESS;
    }
}
