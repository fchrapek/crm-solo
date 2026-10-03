<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\TaskAttachment;
use App\Services\Agent\AgentAbilities;
use App\Services\Agent\AgentCallContext;
use App\Services\Agent\AgentIdentityResolver;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A task attachment for an agent that cannot open the server's path: the
 * `url` a task record lists for each saved file. Account-scoped through the
 * task's project; another account's file answers 404, as a missing one does.
 * Always sent as a download, since the contents were written outside the CRM.
 */
final class AttachmentDownloadController extends Controller
{
    public function __invoke(int $attachment, AgentCallContext $context, AgentIdentityResolver $identity): BinaryFileResponse
    {
        abort_if($context->missing([AgentAbilities::READ]) !== [], 403);

        $file = TaskAttachment::query()
            ->whereKey($attachment)
            ->whereHas('task.project', fn ($project) => $project->where('account_id', $identity->resolve()->account->id))
            ->first();
        abort_if($file === null, 404);

        $path = Storage::disk('local')->path($file->file_path);
        abort_unless(is_file($path), 404);

        return response()->download($path, $file->original_name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }
}
