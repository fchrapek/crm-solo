<?php

declare(strict_types=1);

namespace App\Services\Agent;

/**
 * One agent call in progress: the transport it came through, the verb or
 * tool it runs, and the caller's session id when it sent one. Writes made
 * while a call is open are attributed to it.
 */
final readonly class AgentCall
{
    public const string VIA_CLI = 'cli';

    public const string VIA_CLI_REMOTE = 'cli-remote';

    public const string VIA_MCP = 'mcp';

    public const string VIA_MCP_WEB = 'mcp-web';

    /** The demo instance's in-page crm prompt. */
    public const string VIA_WEB_DEMO = 'web-demo';

    public function __construct(
        public string $via,
        public string $verb,
        public ?string $sessionId = null,
    ) {}

    /**
     * A caller-supplied session id, kept only when it looks like one (a Claude
     * session UUID, an MCP session id): anything else is dropped, not stored.
     */
    public static function cleanSessionId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = mb_trim($value);

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $value) === 1 ? $value : null;
    }

    public function withVerb(string $verb): self
    {
        return new self($this->via, $verb, $this->sessionId);
    }
}
