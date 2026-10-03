<?php

declare(strict_types=1);

namespace App\Services\Agent;

final class AmbiguousReferenceException extends ReferenceException
{
    public readonly int $total;

    /**
     * @param  list<array{id: int, name: string, context: string|null}>  $candidates  the first matches, best first
     * @param  int|null  $total  how many records matched in all; more than the candidates when the list is cut
     */
    public function __construct(
        string $entity,
        string $needle,
        public readonly array $candidates,
        ?int $total = null,
    ) {
        $this->total = $total ?? count($candidates);
        $shown = $this->total > count($candidates) ? ' Showing '.count($candidates)." of {$this->total} matches; narrow the name or use an id." : '';

        parent::__construct(
            $entity,
            $needle,
            "Ambiguous {$entity} \"{$needle}\" — call again with the numeric id.{$shown}",
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
            'total' => $this->total,
        ];
    }

    /**
     * The CLI rendering of the same data — one candidate per line.
     */
    public function candidateLines(): string
    {
        return collect($this->candidates)
            ->map(fn (array $c): string => "  #{$c['id']} \"{$c['name']}\"".($c['context'] !== null ? " ({$c['context']})" : ''))
            ->implode("\n")
            .($this->total > count($this->candidates) ? "\n  ... and ".($this->total - count($this->candidates)).' more' : '');
    }
}
