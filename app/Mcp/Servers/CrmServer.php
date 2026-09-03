<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddNoteTool;
use App\Mcp\Tools\ClientBriefTool;
use App\Mcp\Tools\ClientConfigTool;
use App\Mcp\Tools\LeadCaptureTool;
use App\Mcp\Tools\LeadStageTool;
use App\Mcp\Tools\LogTimeTool;
use App\Mcp\Tools\MonthCloseStatusTool;
use App\Mcp\Tools\MonthCloseTickTool;
use App\Mcp\Tools\TaskDoneTool;
use App\Mcp\Tools\TimerStartTool;
use App\Mcp\Tools\TimerStopTool;
use App\Mcp\Tools\TodayTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('CRM Solo')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
CRM Solo runs a solo web agency: clients, retainers, tasks, time, leads and the
monthly close. These tools are the same verbs the CLI exposes, so anything done
here shows up in the web UI immediately.

Conventions:
- Clients, tasks and timers accept either a numeric id or a name fragment. A
  fragment matching several records returns an "ambiguous_reference" error with
  a candidates list — call again with the numeric id from that list.
- Times you pass (log_time "end") are wall-clock in the CRM's display timezone,
  not UTC. "15:30" means half past three locally.
- A lead's source is immutable attribution set at capture: an unknown slug is
  rejected with the valid ones listed, never silently coerced.
- Start with "today" for the cross-client picture and "client_brief" for one
  client before acting.
TEXT)]
final class CrmServer extends Server
{
    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Server\Tool>>
     */
    protected array $tools = [
        TodayTool::class,
        ClientBriefTool::class,
        ClientConfigTool::class,
        MonthCloseStatusTool::class,
        MonthCloseTickTool::class,
        TaskDoneTool::class,
        AddNoteTool::class,
        TimerStartTool::class,
        TimerStopTool::class,
        LogTimeTool::class,
        LeadCaptureTool::class,
        LeadStageTool::class,
    ];
}
