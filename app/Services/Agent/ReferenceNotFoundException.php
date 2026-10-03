<?php

declare(strict_types=1);

namespace App\Services\Agent;

final class ReferenceNotFoundException extends ReferenceException
{
    /**
     * @param  list<array{id: int, name: string, context: string|null}>  $nearMisses  records that match the name but not the purpose, such as finished tasks when starting work
     */
    public function __construct(string $entity, string $needle, ?string $hint = null, public readonly array $nearMisses = [])
    {
        parent::__construct($entity, $needle, "No {$entity} matches \"{$needle}\".".($hint !== null ? " {$hint}" : ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'error' => 'not_found',
            'entity' => $this->entity,
            'needle' => $this->needle,
            'message' => $this->getMessage(),
            ...($this->nearMisses !== [] ? ['near_misses' => $this->nearMisses] : []),
        ];
    }

    /**
     * The CLI rendering of the near misses, one per line.
     */
    public function nearMissLines(): string
    {
        return collect($this->nearMisses)
            ->map(fn (array $c): string => "  #{$c['id']} \"{$c['name']}\"".($c['context'] !== null ? " ({$c['context']})" : ''))
            ->implode("\n");
    }
}
