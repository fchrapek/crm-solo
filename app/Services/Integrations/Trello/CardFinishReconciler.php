<?php

declare(strict_types=1);

namespace App\Services\Integrations\Trello;

use App\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the owner's `finished_at` (and the agent-board Done that goes with
 * it) consistent with what Trello says about the card. Callers hold the
 * task's row lock and pass the row as it is under that lock, then write the
 * returned attributes in the same statement as the card's own fields.
 */
final class CardFinishReconciler
{
    /**
     * A move this close after the tick is treated as the same moment, so a
     * clock running a little ahead on either side cannot reopen a task the
     * owner finished after the move.
     */
    public const int MOVE_SKEW_SECONDS = 60;

    /** A card is completed on Trello when it sits on a Done-mapped list or its due date is marked complete. */
    public static function isCompleted(string $lane, bool $dueComplete): bool
    {
        return $dueComplete || $lane === TrelloListMapper::LANE_DONE;
    }

    /**
     * Re-derives a card's lane and completion after the list mapping changed,
     * under the row lock and with the same rules as a sync. The lane comes
     * from the list the card is on now and the mapping as saved now, both read
     * under the lock, so a card a sync moved meanwhile gets its new list's
     * lane. The card did not move, so this can fill a finish but never clear one.
     */
    public static function remap(Task $card): void
    {
        DB::transaction(function () use ($card): void {
            $current = Task::query()->whereKey($card->id)->lockForUpdate()->first();
            $mapping = $current?->project()->value('settings')['trello_list_mapping'] ?? null;
            if ($current === null || $current->trello_list_id === null || ! isset($mapping[$current->trello_list_id])) {
                return;
            }

            $lane = $mapping[$current->trello_list_id];
            // Until a sync has seen the card's due-complete flag, only a Done lane says anything.
            $isCompleted = $current->trello_due_complete === null
                ? ($lane === TrelloListMapper::LANE_DONE || (bool) $current->is_completed)
                : self::isCompleted($lane, (bool) $current->trello_due_complete);
            $owner = self::ownerChanges($current, $current->trello_list_id, $lane, $isCompleted, CardMove::unknown());
            $current->fill(['list_name' => $lane, 'is_completed' => $isCompleted, ...$owner])->save();
            $card->setRawAttributes($current->getAttributes(), true);
        });
    }

    /**
     * Whether the sync needs to know when the card last moved: the task is
     * finished, its card is no longer completed on Trello and sits on an
     * active list, and either it just changed list or an earlier sync could
     * not get the answer. Only then is the move time worth a request.
     */
    public static function needsMoveCheck(Task $current, ?string $listId, string $lane, bool $isCompleted): bool
    {
        if ($current->finished_at === null || $isCompleted || ! TrelloListMapper::isActiveLane($lane)) {
            return false;
        }

        $listChanged = $current->trello_list_id !== null && $listId !== null && $listId !== $current->trello_list_id;

        return $listChanged || $current->trello_move_pending_at !== null;
    }

    /**
     * The owner-layer attributes to write with the card, from the row as it
     * is now (read under its lock).
     *
     * - Fill: the first time a card is seen turning completed.
     * - Clear: the card is not completed, sits on an active list it moved to,
     *   and that move (its last list-change action on Trello) came after the
     *   finish by more than MOVE_SKEW_SECONDS. The agent board's Done goes
     *   back to Backlog with it.
     * - Pending: while that question has no answer, trello_move_pending_at
     *   stays set so the next sync asks again; any answer clears it.
     *
     * @return array<string, mixed>
     */
    public static function ownerChanges(Task $current, ?string $listId, string $lane, bool $isCompleted, CardMove $move): array
    {
        if (! self::needsMoveCheck($current, $listId, $lane, $isCompleted)) {
            $changes = $current->trello_move_pending_at !== null ? ['trello_move_pending_at' => null] : [];

            return $current->finished_at === null && $isCompleted && ! $current->is_completed
                ? [...$changes, 'finished_at' => now()]
                : $changes;
        }

        if (! $move->answered) {
            return ['trello_move_pending_at' => $current->trello_move_pending_at ?? now()];
        }

        $changes = ['trello_move_pending_at' => null];
        if ($move->at !== null && $move->at->gt($current->finished_at->copy()->addSeconds(self::MOVE_SKEW_SECONDS))) {
            $changes['finished_at'] = null;
            if ($current->agent_lane === Task::AGENT_LANE_DONE) {
                $changes['agent_lane'] = Task::AGENT_LANE_BACKLOG;
            }
        }

        return $changes;
    }
}
