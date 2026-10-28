<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\CrmServer;
use App\Services\Agent\AgentAbilities;
use ReflectionClass;
use Tests\TestCase;

/**
 * The CLI verbs and the MCP tools are one set of operations: every tool has a
 * verb, every verb a tool, and both need the same write group, so a write
 * added to one transport cannot be missing from the other.
 */
final class AgentSurfaceParityTest extends TestCase
{
    /** A read-only tool may pair with a write verb only through the verb's read form: these arguments. */
    private const array READ_FORMS = [
        'task-brief' => ['42'],
        'plan' => ['--day=tomorrow'],
        'month-close:tick' => ['42', 'list'],
    ];

    public function test_every_registered_tool_maps_to_a_remote_verb_and_every_verb_to_a_tool(): void
    {
        $registered = array_map(
            fn (string $tool): string => app($tool)->name(),
            (new ReflectionClass(CrmServer::class))->getDefaultProperties()['tools'],
        );
        sort($registered);
        $mapped = array_keys(AgentAbilities::TOOL_VERBS);
        sort($mapped);

        $this->assertSame($registered, $mapped, 'every MCP tool has an entry in AgentAbilities::TOOL_VERBS');
        $this->assertSame([], array_values(array_diff(AgentAbilities::TOOL_VERBS, array_keys(AgentAbilities::VERBS))), 'every mapped verb is a remote verb');
        $this->assertSame([], array_values(array_diff(array_keys(AgentAbilities::VERBS), AgentAbilities::TOOL_VERBS)), 'every remote verb has an MCP tool');
    }

    public function test_a_tool_and_its_verb_need_the_same_write_group(): void
    {
        foreach (AgentAbilities::TOOL_VERBS as $tool => $verb) {
            $toolWrite = AgentAbilities::TOOLS[$tool];
            $verbWrite = AgentAbilities::VERBS[$verb][1];
            if ($toolWrite === $verbWrite) {
                $this->addToAssertionCount(1);

                continue;
            }

            $this->assertNull($toolWrite, "{$tool} and {$verb} need different write groups");
            $this->assertArrayHasKey($verb, self::READ_FORMS, "read-only {$tool} pairs with write verb {$verb}, which has no read form");
            $this->assertSame([AgentAbilities::READ], AgentAbilities::forVerb($verb, self::READ_FORMS[$verb]));
        }
    }

    public function test_every_new_style_verb_prints_json(): void
    {
        foreach (AgentAbilities::VERBS as $verb => [$command]) {
            if ($command === 'time:log') {
                continue;
            }
            $this->assertTrue(app(AgentAbilities::COMMANDS[$command])->getDefinition()->hasOption('json'), "{$verb} takes --json");
        }
    }

    public function test_the_plan_read_form_is_only_the_day_and_json(): void
    {
        foreach ([[], ['--json'], ['--day=tomorrow'], ['--day='], ['--day=2026-10-08', '--json']] as $args) {
            $this->assertSame([AgentAbilities::READ], AgentAbilities::forVerb('plan', $args), (string) json_encode($args));
        }
        foreach ([['42'], ['--day', 'tomorrow'], ['--day', 'tomorrow', '42'], ['--', '42'], ['--day=tomorrow', '42'], ['42', '--json']] as $args) {
            $this->assertSame([AgentAbilities::READ, AgentAbilities::TASKS], AgentAbilities::forVerb('plan', $args), (string) json_encode($args));
        }
    }

    public function test_the_month_close_list_is_a_read_only_in_its_exact_form(): void
    {
        foreach ([['42', 'list'], ['42', 'list', '--json', '--period=2026-09']] as $args) {
            $this->assertSame([AgentAbilities::READ], AgentAbilities::forVerb('month-close:tick', $args), (string) json_encode($args));
        }
        foreach ([['42', 'db_dump', 'done'], ['42', 'list', 'done'], ['--project', 'x', '42', 'list'], ['--period', '2026-09', '42', 'list'], ['42', 'list', '--note=x'], ['--', '42', 'list']] as $args) {
            $this->assertSame([AgentAbilities::READ, AgentAbilities::MONTH_CLOSE], AgentAbilities::forVerb('month-close:tick', $args), (string) json_encode($args));
        }
    }
}
