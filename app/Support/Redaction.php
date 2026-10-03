<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The forms a secret can take inside an error message: as stored, trimmed
 * (HTTP clients trim header values before echoing them), and line by line
 * (a value with a newline is split by the client that rejects it).
 */
final class Redaction
{
    /**
     * @param  list<string>  $secrets
     * @return list<string> longest first, so a shorter form never leaves part of a longer one
     */
    public static function variants(array $secrets): array
    {
        $variants = [];
        foreach ($secrets as $secret) {
            $variants[] = $secret;
            $variants[] = mb_trim($secret);
            foreach (preg_split('/\R/', $secret) ?: [] as $line) {
                $variants[] = mb_trim($line);
            }
        }

        $variants = array_values(array_unique(array_filter($variants, fn (string $v): bool => mb_strlen($v) >= 4)));
        usort($variants, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $variants;
    }
}
