<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The file types a task may carry, for every way a file arrives: an upload
 * through the task page, or a file pulled from the task's Trello card. The
 * extension list is checked against the name, the mime list against the
 * content, so a renamed executable is refused on what it really is.
 */
final class TaskAttachmentTypes
{
    /**
     * `sql` + `gz` + `zip` are here for DB dumps the user wants the agent to
     * import to a local environment.
     */
    public const array EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
        'pdf',
        'md', 'txt',
        'doc', 'docx',
        'sql', 'gz', 'zip',
    ];

    /**
     * text/plain covers md/txt/sql since Symfony detects them all as plain text.
     */
    public const array MIMETYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/svg',
        'application/pdf',
        'text/plain', 'text/markdown', 'text/x-markdown',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/sql', 'application/x-sql', 'text/x-sql',
        'application/gzip', 'application/x-gzip',
        'application/zip', 'application/x-zip-compressed',
        'application/octet-stream', // catch-all for binary uploads (gz from some clients)
    ];

    public static function allowsExtension(string $extension): bool
    {
        return in_array(mb_strtolower($extension), self::EXTENSIONS, true);
    }

    public static function allowsMime(string $mime): bool
    {
        return in_array(mb_strtolower($mime), self::MIMETYPES, true);
    }
}
