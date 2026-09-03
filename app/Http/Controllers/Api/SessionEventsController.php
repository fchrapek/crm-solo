<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\SessionAttention;
use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SessionEventsController extends Controller
{
    /**
     * Receive an event from a live terminal session's CLI hook. The token in
     * the URL is per-session, generated in TerminalSessionLauncher::launch()
     * and cleared on stop() — so a leaked token from a finished session can't
     * be used to spam toasts later.
     *
     * Body: { event: string, message?: string }
     *   event:   short hook name, e.g. "notification" | "stop" | "idle"
     *   message: optional human text claude wants surfaced to the user
     *
     * Side effects:
     *   - tasks.session_attention_at = now()
     *   - broadcast SessionAttention on reverb.session.{task.id}
     */
    public function store(Request $request, string $token): JsonResponse
    {
        $task = Task::query()->where('session_token', $token)->first();

        if ($task === null) {
            // Don't reveal whether the token shape is valid — just 404. The hook
            // script logs the response code locally for debugging.
            return response()->json(['ok' => false], 404);
        }

        $data = $request->validate([
            'event' => 'required|string|max:64',
            'message' => 'nullable|string|max:2000',
        ]);

        $event = $data['event'];
        $message = $data['message'] ?? null;

        $task->forceFill(['session_attention_at' => now()])->save();

        try {
            SessionAttention::dispatch(
                $task->id,
                $task->name,
                $event,
                $message,
            );
        } catch (Throwable $e) {
            // Broadcasting failure shouldn't block the hook — the
            // session_attention_at flag is the durable signal; broadcast is
            // just for the live toast.
            Log::warning('Failed to broadcast SessionAttention', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
