<?php

declare(strict_types=1);

namespace App\Mcp\Methods;

use App\Mcp\ResourceError;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Methods\ReadResource as BaseReadResource;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Throwable;

/**
 * resources/read with real resource errors: a ResourceError leaves as a
 * JSON-RPC error with its own code and data instead of the package's
 * generic internal error.
 */
final class ReadResource extends BaseReadResource
{
    protected function callHandler(callable $handler, JsonRpcRequest $request): mixed
    {
        try {
            return $handler();
        } catch (ResourceError $e) {
            throw new JsonRpcException($e->getMessage(), $e->getCode(), $request->id, $e->data);
        } catch (Throwable $e) {
            throw $this->toJsonRpcException($e, $request->id);
        }
    }
}
