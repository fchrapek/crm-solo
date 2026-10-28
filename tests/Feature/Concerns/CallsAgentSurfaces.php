<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Mcp\Servers\CrmServer;
use Illuminate\Support\Facades\Artisan;
use ReflectionProperty;

/** Runs a `crm:*` verb with --json and an MCP tool, and returns what each answered. */
trait CallsAgentSurfaces
{
    /**
     * @param  array<string, mixed>  $parameters
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function verb(string $command, array $parameters = []): array
    {
        $exit = Artisan::call($command, [...$parameters, '--json' => true]);

        return [$exit, json_decode(mb_trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)];
    }

    /**
     * @param  class-string  $tool
     * @param  array<string, mixed>  $arguments
     * @return array{0: bool, 1: array<string, mixed>}
     */
    private function tool(string $tool, array $arguments = []): array
    {
        $response = CrmServer::tool($tool, $arguments);
        $result = (new ReflectionProperty($response, 'response'))->getValue($response)->toArray()['result'];

        return [! ($result['isError'] ?? false), json_decode((string) $result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR)];
    }
}
