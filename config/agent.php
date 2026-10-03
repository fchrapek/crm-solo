<?php

declare(strict_types=1);

/*
| The remote agent bridge: agents on the Mac reach a hosted CRM over HTTPS
| through MCP (/mcp) and the crm verb endpoint (/agent/verb), each request
| carrying a personal access token. docs/development/agent-crm-interface.md.
*/

return [
    // Requests per minute one token may make across both transports.
    'per_minute' => (int) env('AGENT_RATE_PER_MINUTE', 120),

    // Failed token checks per minute from one address before it gets 429.
    'failed_per_minute' => (int) env('AGENT_FAILED_AUTH_PER_MINUTE', 20),

    // Lifetime in days of a new token unless agent-tokens:issue says otherwise.
    'token_days' => (int) env('AGENT_TOKEN_DAYS', 90),

    // The largest verb output returned in one response, in bytes.
    'output_cap' => 1_000_000,
];
