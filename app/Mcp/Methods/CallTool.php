<?php

declare(strict_types=1);

namespace App\Mcp\Methods;

use App\Services\Agent\AgentAbilities;
use App\Services\Agent\AgentCall;
use App\Services\Agent\AgentCallContext;
use Generator;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Methods\CallTool as BaseCallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * tools/call inside an agent call named after the tool, so what the tool
 * writes is stamped and audited. Over HTTP the request already opened a call
 * (with the token and session); over stdio this opens one as `mcp`. A
 * token without the tool's abilities is refused before the tool runs.
 */
final class CallTool extends BaseCallTool
{
    public function __construct(private readonly AgentCallContext $calls) {}

    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $tool = (string) ($request->params['name'] ?? '');

        $missing = $this->calls->missing(AgentAbilities::forTool($tool));
        if ($missing !== []) {
            throw new JsonRpcException("Tool [{$tool}] needs the ".implode(', ', $missing).' ability, which this token was not issued with.', -32602, $request->id);
        }

        $current = $this->calls->current();
        $call = $current !== null ? $current->withVerb($tool) : new AgentCall(AgentCall::VIA_MCP, $tool);

        return $this->calls->run($call, fn (): Generator|JsonRpcResponse => parent::handle($request, $context));
    }
}
