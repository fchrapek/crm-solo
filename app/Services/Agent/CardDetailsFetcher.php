<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Exceptions\UnreadableIntegrationCredentials;
use App\Models\Integration;
use App\Models\Task;
use App\Models\TaskCardDetails;
use App\Services\Integrations\Trello\TrelloRequestFailed;
use App\Services\Integrations\Trello\TrelloService;
use Carbon\CarbonImmutable;
use Illuminate\Cache\Lock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches a card's checklists, comments and attachments when an agent opens
 * the task, and only when the cached copy is missing, older than the card's
 * last synced activity, or holds a file whose retry is due. Never called by
 * the sync, never on the demo, never a write to Trello.
 *
 * A failure is stored and backs off (5 minutes, doubling, at most 6 hours)
 * instead of hitting Trello on every read; a card Trello answers 404 for, or
 * one the sync archived, is not fetched again; `refresh` overrides both. The
 * whole fetch, downloads included, finishes inside its lock lease, and
 * details older than the ones stored are never written over them.
 */
final class CardDetailsFetcher
{
    /** Longest one fetch may hold its task; a second read meanwhile uses the cache. */
    public const int LOCK_SECONDS = 300;

    /** Time kept back at the end of the lease for storing what was fetched. */
    private const int COMMIT_MARGIN_SECONDS = 30;

    public function __construct(
        private readonly CardAttachmentPuller $attachments,
    ) {}

    /**
     * @return string|null why the details are not current (a fixed sentence), or null when they are
     */
    public function refresh(Task $task, bool $force = false): ?string
    {
        if (! $task->hasTrelloCard() || config('app.demo')) {
            return null;
        }

        $details = $task->cardDetails;
        if (! $force) {
            if ($task->isArchived() || $details?->card_gone_at !== null) {
                return $details?->fetch_error;
            }
            if ($details?->retry_after !== null && now()->lt($details->retry_after)) {
                return $details->fetch_error;
            }
            if (! $this->isStale($task, $details)) {
                return $details?->fetch_error;
            }
        }

        $lock = Cache::lock('trello-card-details:'.$task->id, self::LOCK_SECONDS);
        if (! $lock->get()) {
            return $details?->fetch_error;
        }
        $deadline = CarbonImmutable::now()->addSeconds(self::LOCK_SECONDS - self::COMMIT_MARGIN_SECONDS);

        try {
            $trello = $this->trelloFor($task);
            if ($trello === null) {
                return $this->fail($task, 'No Trello integration is configured for this account.');
            }

            return $this->store($task, $trello, $trello->fetchCardDetails((string) $task->trello_card_id), $deadline, $force, $lock);
        } catch (TrelloRequestFailed $e) {
            Log::warning('Trello card details fetch failed', ['task_id' => $task->id, 'error' => $e->getMessage()]);

            return $e->status === 404 ? $this->gone($task) : $this->fail($task, $e->summary());
        } catch (UnreadableIntegrationCredentials $e) {
            Log::warning('Trello card details fetch failed', ['task_id' => $task->id, 'error' => $e->getMessage()]);

            return $this->fail($task, 'The Trello credentials cannot be read; reconnect Trello.');
        } catch (Throwable $e) {
            Log::warning('Trello card details fetch failed', ['task_id' => $task->id, 'exception' => $e::class]);

            return $this->fail($task, 'Card details could not be fetched ('.class_basename($e).').');
        } finally {
            $lock->release();
            $task->unsetRelation('cardDetails');
        }
    }

    public function isStale(Task $task, ?TaskCardDetails $details): bool
    {
        if ($details === null || $details->fetched_at === null) {
            return true;
        }

        if ($task->trello_activity_at !== null
            && ($details->card_activity_at === null || $task->trello_activity_at->gt($details->card_activity_at))) {
            return true;
        }

        return collect($details->card_attachments ?? [])->contains(fn (mixed $entry): bool => is_array($entry)
            && ($entry['status'] ?? null) === CardAttachmentPuller::STATUS_FAILED
            && (! is_string($entry['retry_at'] ?? null) || now()->gte($entry['retry_at'])));
    }

    private function trelloFor(Task $task): ?TrelloService
    {
        $integration = Integration::query()
            ->where('account_id', $task->project?->account_id)
            ->where('provider', 'trello')
            ->where('is_enabled', true)
            ->orderBy('id')
            ->first();

        if ($integration === null) {
            return null;
        }

        $integration->assertSecretsReadable();
        $key = $integration->settings['trello_api_key'] ?? config('services.trello.api_key');
        if (! $integration->hasValidApiKey() || ! $key) {
            return null;
        }

        return new TrelloService($integration);
    }

    /**
     * Pulls the files, then writes the details under the row lock unless a
     * newer version is already stored or this fetch no longer holds the task.
     *
     * @param  array<string, mixed>  $card
     */
    private function store(Task $task, TrelloService $trello, array $card, CarbonImmutable $deadline, bool $force, Lock $lock): ?string
    {
        $activity = isset($card['dateLastActivity']) && is_string($card['dateLastActivity'])
            ? Carbon::parse($card['dateLastActivity'])->setTimezone(config('app.timezone'))
            : null;
        $stored = TaskCardDetails::query()->where('task_id', $task->id)->first();
        if ($this->isOlder($activity, $stored)) {
            return $stored?->fetch_error;
        }

        $cardAttachments = is_array($card['attachments'] ?? null) ? $card['attachments'] : [];
        $manifest = $this->attachments->pull($task, $trello, $cardAttachments, $stored->card_attachments ?? [], $deadline, $force);
        $failedFiles = collect($manifest)->where('status', CardAttachmentPuller::STATUS_FAILED)->count();
        $note = $failedFiles > 0 ? "{$failedFiles} attached file(s) could not be downloaded yet; they are retried on a later read." : null;

        if (! $lock->isOwnedByCurrentProcess()) {
            return 'Card details were not stored: the fetch outlived its lock.';
        }

        $written = DB::transaction(function () use ($task, $card, $manifest, $activity, $note): bool {
            if (Task::query()->whereKey($task->id)->lockForUpdate()->first() === null) {
                return false;
            }
            $current = TaskCardDetails::query()->where('task_id', $task->id)->lockForUpdate()->first();
            if ($this->isOlder($activity, $current)) {
                return false;
            }

            $values = [
                'checklists' => $this->checklists($card['checklists'] ?? []),
                'comments' => $this->comments($card['actions'] ?? []),
                'card_attachments' => $manifest,
                'card_activity_at' => $activity,
                'fetched_at' => now(),
                'fetch_error' => $note,
                'fetch_failures' => 0,
                'retry_after' => null,
                'card_gone_at' => null,
            ];
            $current !== null ? $current->update($values) : TaskCardDetails::create(['task_id' => $task->id, ...$values]);

            return true;
        });

        if ($written) {
            $this->attachments->reconcile($task, collect($cardAttachments)->pluck('id')->filter(fn (mixed $id): bool => is_string($id))->values()->all());
        }

        return $written ? $note : TaskCardDetails::query()->where('task_id', $task->id)->value('fetch_error');
    }

    /** A fetch that saw an older version of the card than the one stored. */
    private function isOlder(?Carbon $activity, ?TaskCardDetails $stored): bool
    {
        return $activity !== null && $stored?->card_activity_at !== null && $activity->lt($stored->card_activity_at);
    }

    private function fail(Task $task, string $error): string
    {
        DB::transaction(function () use ($task, $error): void {
            if (Task::query()->whereKey($task->id)->lockForUpdate()->first() === null) {
                return;
            }
            $details = TaskCardDetails::query()->where('task_id', $task->id)->lockForUpdate()->first()
                ?? new TaskCardDetails(['task_id' => $task->id]);
            $failures = (int) $details->fetch_failures + 1;
            $details->fill([
                'fetch_error' => $error,
                'fetch_failures' => $failures,
                'retry_after' => now()->addSeconds(TaskCardDetails::backoffSeconds($failures)),
            ])->save();
        });

        return $error;
    }

    private function gone(Task $task): string
    {
        $error = 'The card is gone from Trello; details are no longer fetched.';
        if (! Task::query()->whereKey($task->id)->exists()) {
            return $error;
        }
        TaskCardDetails::query()->updateOrCreate(['task_id' => $task->id], [
            'fetch_error' => $error,
            'card_gone_at' => now(),
            'retry_after' => null,
        ]);

        return $error;
    }

    /**
     * @return list<array{name: string|null, items: list<array{name: string|null, done: bool}>}>
     */
    private function checklists(mixed $checklists): array
    {
        return collect(is_array($checklists) ? $checklists : [])
            ->filter(fn (mixed $checklist): bool => is_array($checklist))
            ->sortBy(fn (array $checklist): float => (float) ($checklist['pos'] ?? 0))
            ->map(fn (array $checklist): array => [
                'name' => $this->text($checklist['name'] ?? null),
                'items' => collect(is_array($checklist['checkItems'] ?? null) ? $checklist['checkItems'] : [])
                    ->filter(fn (mixed $item): bool => is_array($item))
                    ->sortBy(fn (array $item): float => (float) ($item['pos'] ?? 0))
                    ->map(fn (array $item): array => [
                        'name' => $this->text($item['name'] ?? null),
                        'done' => ($item['state'] ?? null) === 'complete',
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Oldest first, so a thread reads in the order it was written.
     *
     * @return list<array{author: string|null, at: string|null, text: string|null}>
     */
    private function comments(mixed $actions): array
    {
        return collect(is_array($actions) ? $actions : [])
            ->filter(fn (mixed $action): bool => is_array($action) && ($action['type'] ?? null) === 'commentCard')
            ->map(fn (array $action): array => [
                'author' => $this->text($action['memberCreator']['fullName'] ?? null) ?? $this->text($action['memberCreator']['username'] ?? null),
                'at' => is_string($action['date'] ?? null) ? Carbon::parse($action['date'])->setTimezone(config('app.timezone'))->toIso8601String() : null,
                'text' => $this->text($action['data']['text'] ?? null),
            ])
            ->sortBy(fn (array $comment): string => (string) $comment['at'])
            ->values()
            ->all();
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
