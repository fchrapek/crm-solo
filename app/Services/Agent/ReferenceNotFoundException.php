<?php

declare(strict_types=1);

namespace App\Services\Agent;

final class ReferenceNotFoundException extends ReferenceException
{
    public function __construct(string $entity, string $needle)
    {
        parent::__construct($entity, $needle, "No {$entity} matches \"{$needle}\".");
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
        ];
    }
}
