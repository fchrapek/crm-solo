<?php

declare(strict_types=1);

namespace App\Services\Messaging\Sender;

use JsonSerializable;
use LogicException;
use SensitiveParameter;

final readonly class SenderConfig implements JsonSerializable
{
    public function __construct(
        public string $host,
        public int $port,
        public string $username,
        #[SensitiveParameter] private string $password,
        public string $fromAddress,
        public ?string $fromName,
    ) {}

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    public function __serialize(): array
    {
        throw new LogicException('A sender config holds a password and is never serialized; queue the account id instead.');
    }

    public function password(): string
    {
        return $this->password;
    }

    /** @return array{host: string, port: int, username: string, from_address: string, from_name: ?string} */
    public function jsonSerialize(): array
    {
        return ['host' => $this->host, 'port' => $this->port, 'username' => $this->username, 'from_address' => $this->fromAddress, 'from_name' => $this->fromName];
    }
}
