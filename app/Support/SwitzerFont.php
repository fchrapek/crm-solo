<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Switzer is under the ITF Free Font License: self-hosting is allowed, publishing the files is not.
 * The private repository serves its own copy; the public snapshot export-ignores it and falls back to the Fontshare CDN.
 */
final class SwitzerFont
{
    public const string FILE = 'fonts/switzer/Switzer-Variable.woff2';

    public static function selfHosted(): bool
    {
        return is_file(public_path(self::FILE));
    }
}
