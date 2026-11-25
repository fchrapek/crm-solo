<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

/**
 * Upload caps for the public demo, where every visitor shares one login: a
 * small per-file size, a per-visitor hourly count and a short list of file
 * types. Outside DEMO_MODE none applies and each upload route keeps its own.
 */
final class UploadLimits
{
    /** What the next visitor may be handed: pictures, PDFs and plain text, never an archive, an office file or a script-bearing SVG. */
    private const array DEMO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'md', 'txt'];

    private const array DEMO_MIMETYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'text/markdown', 'text/x-markdown'];

    public static function maxKilobytes(int $normal): int
    {
        return config('app.demo') ? min($normal, (int) config('app.demo_uploads.max_kb')) : $normal;
    }

    public static function limit(Request $request): Limit
    {
        if (! config('app.demo')) {
            return Limit::none();
        }

        return Limit::perHour((int) config('app.demo_uploads.per_hour'))->by('uploads|'.DemoWriteBudget::visitor($request));
    }

    /**
     * @param  list<string>  $normal
     * @return list<string>
     */
    public static function extensions(array $normal): array
    {
        return config('app.demo') ? array_values(array_intersect($normal, self::DEMO_EXTENSIONS)) : $normal;
    }

    /**
     * @param  list<string>  $normal
     * @return list<string>
     */
    public static function mimetypes(array $normal): array
    {
        return config('app.demo') ? array_values(array_intersect($normal, self::DEMO_MIMETYPES)) : $normal;
    }
}
