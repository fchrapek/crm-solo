<?php

declare(strict_types=1);

namespace App\Services\Agent;

/**
 * The short name a screen gives an agent, read off the name its token was
 * issued under ("codex macbook" is Codex, "claude-code mac" is Claude Code).
 * A write through a local transport carries no token and reads as Agent.
 */
final class AgentName
{
    public const string FALLBACK = 'Agent';

    /** Checked in order, so the longer name wins over its prefix. */
    private const array KNOWN = [
        'claude-code' => 'Claude Code',
        'claude code' => 'Claude Code',
        'claudecode' => 'Claude Code',
        'codex' => 'Codex',
        'claude' => 'Claude',
        'chatgpt' => 'ChatGPT',
        'cursor' => 'Cursor',
        'gemini' => 'Gemini',
    ];

    public static function fromToken(?string $tokenName): string
    {
        $name = mb_strtolower(mb_trim((string) $tokenName));
        if ($name === '') {
            return self::FALLBACK;
        }

        foreach (self::KNOWN as $needle => $label) {
            if (str_contains($name, $needle)) {
                return $label;
            }
        }

        $first = preg_split('/[\s_\-]+/u', mb_trim((string) $tokenName))[0] ?? '';

        return $first === '' ? self::FALLBACK : mb_substr(mb_strtoupper(mb_substr($first, 0, 1)).mb_substr($first, 1), 0, 24);
    }
}
