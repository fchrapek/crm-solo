<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Clients\Fields\ClientFieldDefinitions;
use Illuminate\Console\Command;

/** Agent verb: add a custom client field, or change its label, options, required flag or overview place. */
#[AccountScope(AccountScope::ACTING)]
final class CrmClientFieldDefine extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:client-field-define
        {key : Field key, e.g. visit_status (never changes)}
        {--label= : Label shown in the app}
        {--type= : text, textarea, select, multiselect, date, datetime or url (new fields only)}
        {--option=* : For select fields, key:Label (repeat); new keys are added, known keys renamed}
        {--required : Values may not be cleared}
        {--optional : Values may be cleared}
        {--overview : Show the field on the client overview and in the client list}
        {--no-overview : Keep the field on the client data tab only}
        {--archive : Hide the field; stored values stay}
        {--restore : Bring an archived field back}
        {--json : Machine-readable output}';

    protected $description = 'Add or change a custom client field (key, label, type, options)';

    public function handle(ClientFieldDefinitions $definitions): int
    {
        $options = null;
        if ($this->option('option') !== []) {
            $options = array_map(function (string $option): array {
                [$key, $label] = array_pad(explode(':', $option, 2), 2, null);

                return ['key' => mb_trim((string) $key), 'label' => mb_trim((string) ($label ?? $key))];
            }, (array) $this->option('option'));
        }

        try {
            $field = $definitions->define($this->actingIdentity()->account->id, (string) $this->argument('key'), [
                'label' => $this->option('label'),
                'type' => $this->option('type'),
                'options' => $options,
                'required' => $this->option('required') ? true : ($this->option('optional') ? false : null),
                'on_overview' => $this->option('overview') ? true : ($this->option('no-overview') ? false : null),
                'archived' => $this->option('archive') ? true : ($this->option('restore') ? false : null),
            ]);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson(['field' => $field->toAgentArray()]));

            return self::SUCCESS;
        }

        $this->raw('Saved field '.$this->literal($field->key).' ('.$field->type.').');

        return self::SUCCESS;
    }
}
