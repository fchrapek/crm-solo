<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Manages user-uploaded files attached to tasks — QA screenshots, PDF specs,
 * markdown notes, copy payloads, etc. Surfaced into the terminal session as
 * absolute paths via CRM_TASK.md so claude/codex can Read them on demand.
 *
 * Files live on the `local` disk at `storage/app/private/task-attachments/{task_id}/`.
 * Account scoping is via the parent task → project chain.
 */
final class TaskAttachmentsController extends Controller
{
    /**
     * Extension whitelist passed to Laravel's `mimes:` rule — validated against
     * MIME-from-content + extension consistency, so spoofed-extension uploads
     * (e.g. payload.php renamed to payload.txt) are rejected on real MIME.
     *
     * `sql` + `gz` + `zip` are here for DB dumps the user wants the agent to
     * import to a local environment — claude reads them via absolute path
     * (CRM_TASK.md) and runs `gunzip | mysql` (or similar) inside the worktree.
     */
    private const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
        'pdf',
        'md', 'txt',
        'doc', 'docx',
        'sql', 'gz', 'zip',
    ];

    /**
     * Broad mime allowlist that runs alongside `extensions:` — catches the
     * "renamed payload.exe to payload.txt" spoof case where the content's
     * actual mime betrays the disguise. text/plain covers md/txt/sql since
     * Symfony detects them all as plain text.
     */
    private const ALLOWED_MIMETYPES = [
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

    // 2 GB. PHP request size also needs to permit it — see composer run dev's
    // `-d upload_max_filesize=2G -d post_max_size=2G` overrides. Production
    // nginx needs `client_max_body_size 2G` to match.
    private const MAX_FILE_KB = 2_097_152;

    public function index(Task $task): JsonResponse
    {
        $this->authorizeTask($task);

        $attachments = $task->attachments()->get()->map(fn (TaskAttachment $a) => $this->serialize($a))->values();

        return response()->json(['attachments' => $attachments]);
    }

    public function store(Request $request, Task $task): JsonResponse
    {
        $this->authorizeTask($task);

        // `extensions:` + `mimetypes:` belt-and-suspenders: extension catches
        // the common case (validates the user-supplied name) and mimetypes
        // catches the spoof case (e.g. payload.exe renamed to payload.txt —
        // the actual file content reveals `application/x-msdownload`, not
        // an allowed mime). We need both because:
        //   - `mimes:sql` doesn't work — Symfony detects SQL as text/plain
        //     and the mime→ext reverse map doesn't include sql.
        //   - `extensions:` alone doesn't catch renamed-content spoofs.
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.self::MAX_FILE_KB,
                'extensions:'.implode(',', self::ALLOWED_EXTENSIONS),
                'mimetypes:'.implode(',', self::ALLOWED_MIMETYPES),
            ],
            'label' => ['nullable', 'string', 'max:200'],
        ]);

        $file = $validated['file'];
        $mime = $file->getMimeType() ?? 'application/octet-stream';

        $extension = $file->getClientOriginalExtension() ?: 'bin';
        $filename = Str::uuid()->toString().'.'.Str::lower($extension);
        $relativePath = "task-attachments/{$task->id}/{$filename}";

        Storage::disk('local')->putFileAs(
            "task-attachments/{$task->id}",
            $file,
            $filename
        );

        $attachment = TaskAttachment::create([
            'task_id' => $task->id,
            'file_path' => $relativePath,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $mime,
            'size' => $file->getSize(),
            'label' => $validated['label'] ?? null,
        ]);

        return response()->json($this->serialize($attachment), 201);
    }

    public function show(TaskAttachment $attachment): BinaryFileResponse|Response
    {
        $attachment->load('task.project');

        $project = $attachment->task?->project;
        if ($project === null || $project->account_id !== Auth::user()->account_id) {
            abort(403);
        }

        $absolutePath = Storage::disk('local')->path($attachment->file_path);
        if (! is_file($absolutePath)) {
            abort(404);
        }

        $headers = [
            'Content-Type' => $attachment->mime,
            'Cache-Control' => 'private, max-age=300',
        ];
        // SVG rendered inline executes embedded scripts on the app origin
        // (stored XSS). Force download; every other type stays viewable.
        if (str_contains((string) $attachment->mime, 'svg')) {
            return response()->download($absolutePath, $attachment->original_name, $headers);
        }

        return response()->file($absolutePath, $headers);
    }

    public function destroy(TaskAttachment $attachment): JsonResponse
    {
        $this->authorizeAttachment($attachment);

        Storage::disk('local')->delete($attachment->file_path);
        $attachment->delete();

        return response()->json(['ok' => true]);
    }

    private function authorizeTask(Task $task): void
    {
        $task->loadMissing('project');
        if ($task->project?->account_id !== Auth::user()->account_id) {
            abort(403);
        }
    }

    private function authorizeAttachment(TaskAttachment $attachment): void
    {
        $attachment->load('task.project');
        $project = $attachment->task?->project;
        if ($project === null || $project->account_id !== Auth::user()->account_id) {
            abort(403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(TaskAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'url' => route('task-attachments.show', $attachment->id),
            'original_name' => $attachment->original_name,
            'mime' => $attachment->mime,
            'size' => $attachment->size,
            'label' => $attachment->label,
        ];
    }
}
