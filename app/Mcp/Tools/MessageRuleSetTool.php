<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\AgentRefusal;
use App\Services\Messaging\MessageSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Add, change or delete (delete: true; its waiting messages are withdrawn) when a template goes out. field_value: when a client field takes one of values (any value when left out), e.g. a booking confirmation. date_offset: offset_minutes from a date or datetime field (negative is before), optionally at a local HH:MM, e.g. a reminder two days before a visit. Quiet hours move a message to the morning; past expire_minutes it is skipped. Confirm with the user before calling.')]
final class MessageRuleSetTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'message_rule_set';

    public function handle(Request $request, MessageSettings $settings): Response
    {
        $validated = $request->validate([
            'key' => ['required', 'string'],
            'template' => ['nullable', 'string'],
            'trigger' => ['nullable', 'string'],
            'field' => ['nullable', 'string'],
            'values' => ['nullable', 'array'],
            'values.*' => ['string'],
            'offset_minutes' => ['nullable', 'integer'],
            'at' => ['nullable', 'string'],
            'expire_minutes' => ['nullable', 'integer'],
            'enabled' => ['nullable', 'boolean'],
            'delete' => ['nullable', 'boolean'],
        ], ['key.required' => 'Pass the rule key, e.g. visit_reminder.']);

        if ($validated['delete'] ?? false) {
            try {
                $settings->deleteRule($this->identity()->account->id, $validated['key']);
            } catch (AgentRefusal $e) {
                return $this->refusalError($e);
            }

            return $this->payload(['deleted' => $validated['key']]);
        }

        try {
            $rule = $settings->rule($this->identity()->account->id, $validated['key'], $validated);
        } catch (AgentRefusal $e) {
            return $this->refusalError($e);
        }

        return $this->payload(['rule' => $rule->toAgentArray()]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('Rule key: lowercase letters, digits and _.')->required(),
            'template' => $schema->string()->description('Template key. Required for a new rule.'),
            'trigger' => $schema->string()->enum(['field_value', 'date_offset'])->description('Required for a new rule.'),
            'field' => $schema->string()->description('The client field key the rule watches.'),
            'values' => $schema->array()->items($schema->string())->description('field_value: option keys that trigger it; leave out for any value.'),
            'offset_minutes' => $schema->integer()->description('date_offset: minutes from the date, negative is before (-2880 is two days before).'),
            'at' => $schema->string()->description('date_offset: local send time HH:MM on the offset day.'),
            'expire_minutes' => $schema->integer()->description('Minutes after the planned time a message may still go out (default 900 for field_value, 120 for date_offset).'),
            'enabled' => $schema->boolean()->description('Turn the rule on or off.'),
            'delete' => $schema->boolean()->description('true deletes the rule.'),
        ];
    }
}
