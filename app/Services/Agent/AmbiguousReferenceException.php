<?php

declare(strict_types=1);

namespace App\Services\Agent;

final class AmbiguousReferenceException extends ReferenceException
{
    /**
     * @param  list<array{id: int, name: string, context: string|null}>  $candidates
     */
    public function __construct(
        string $entity,
        string $needle,
        public readonly array $candidates,
    ) {
        parent::__construct(
            $entity,
            $needle,
            "Ambiguous {$entity} \"{$needle}\" — call again with the numeric id.",
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'error' => 'ambiguous_reference',
            'entity' => $this->entity,
            'needle' => $this->needle,
            'message' => $this->getMessage(),
            'candidates' => $this->candidates,
        ];
    }

    /**
     * The CLI rendering of the same data — one candidate per line.
     */
    public function candidateLines(): string
    {
        return collect($this->candidates)
            ->map(fn (array $c): string => "  #{$c['id']} \"{$c['name']}\"".($c['context'] !== null ? " ({$c['context']})" : ''))
            ->implode("\n");
    }
}
