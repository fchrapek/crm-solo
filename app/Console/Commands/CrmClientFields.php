<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Models\ClientFieldDefinition;
use App\Services\Clients\Fields\ClientFieldDefinitions;
use Illuminate\Console\Command;

/** Agent verb: the custom fields the account defined for its clients. */
#[AccountScope(AccountScope::ACTING)]
final class CrmClientFields extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:client-fields
        {--all : Include archived fields}
        {--json : Machine-readable output}';

    protected $description = 'List the custom client fields of the account: key, label, type, options';

    public function handle(ClientFieldDefinitions $definitions): int
    {
        $fields = $definitions->list($this->actingIdentity()->account->id, (bool) $this->option('all'))
            ->map(fn (ClientFieldDefinition $field): array => $field->toAgentArray())->values()->all();

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson(['fields' => $fields]));

            return self::SUCCESS;
        }

        if ($fields === []) {
            $this->raw('No custom client fields.');

            return self::SUCCESS;
        }

        $this->fenced(array_map(function (array $field): string {
            $options = $field['options'] !== null ? ' ['.implode(', ', array_map(fn (array $o): string => "{$o['key']}={$o['label']}", $field['options'])).']' : '';
            $flags = ($field['required'] ? ' required' : '').($field['archived'] ? ' archived' : '');

            return $this->literal($field['key']).' · '.$this->literal($field['label']).' · '.$field['type'].$this->literal($options).$flags;
        }, $fields));

        return self::SUCCESS;
    }
}
