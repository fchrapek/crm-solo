<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\TodayDigest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('The cross-client attention list: urgent and overdue tasks, open month-close runs, hot leads in play, and any running timer. Start here.')]
final class TodayTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'today';

    public function handle(Request $request, TodayDigest $digest): Response
    {
        return $this->payload($digest->build());
    }
}
