<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskCardDetails;
use App\Services\Integrations\Trello\TrelloAttachmentTooLarge;
use App\Services\Integrations\Trello\TrelloRequestFailed;
use App\Services\Integrations\Trello\TrelloService;
use App\Support\TaskAttachmentTypes;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

/**
 * Brings the files a card holds into the task's attachments, so an agent can
 * read the screenshot a card points at. Only files Trello hosts for the card
 * are downloaded; a card attachment that is a link to elsewhere is recorded
 * as a link and never fetched. Each file passes the task attachment type
 * allowlist and the size caps, and a file that is refused or fails is listed
 * with the reason. A file already pulled is never downloaded again, a failed
 * one is retried after a backoff, and a file the card no longer has is
 * removed. Files uploaded in the CRM are never touched.
 */
final class CardAttachmentPuller
{
    public const string STATUS_SAVED = 'saved';

    public const string STATUS_REFUSED = 'refused';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_LINK = 'link';

    /** A download is not started with less time than this left before the deadline. */
    private const int MIN_DOWNLOAD_SECONDS = 10;

    /** The longest one download may take. */
    private const int DOWNLOAD_SECONDS = 120;

    /** A display name with no path, no control characters and a bounded length. */
    public static function safeName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = mb_substr($name, (int) mb_strrpos('/'.$name, '/'));
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $name);
        $name = mb_trim((string) preg_replace('/\s+/u', ' ', $name), " .\t");

        return $name !== '' ? mb_substr($name, -200) : 'attachment';
    }

    /**
     * @param  array<int, mixed>  $cardAttachments  the card's attachments as Trello returns them
     * @param  array<int, mixed>  $previous  the manifest the last fetch stored
     * @param  CarbonImmutable  $deadline  no download starts or runs past this
     * @param  bool  $force  retry failed and refused files now
     * @return list<array<string, mixed>>
     */
    public function pull(Task $task, TrelloService $trello, array $cardAttachments, array $previous, CarbonImmutable $deadline, bool $force): array
    {
        $before = collect($previous)->filter(fn (mixed $e): bool => is_array($e) && is_string($e['trello_id'] ?? null))->keyBy('trello_id');

        $manifest = [];
        foreach ($cardAttachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $entry = $this->entry($attachment);
            if ($attachment['isUpload'] ?? false) {
                $entry = $this->file($task, $trello, $entry, $before->get($entry['trello_id']), $deadline, $force);
            }
            $manifest[] = $entry;
        }

        return $manifest;
    }

    /**
     * Removes the local copy of every file pulled from the card that the card
     * no longer holds, so it stops counting against the quota and the record
     * matches the card. Uploads made in the CRM are left alone.
     *
     * @param  list<string>  $cardIds  the attachment ids the card holds now
     */
    public function reconcile(Task $task, array $cardIds): void
    {
        $task->attachments()
            ->whereNotNull('trello_attachment_id')
            ->whereNotIn('trello_attachment_id', $cardIds)
            ->get()
            ->each(function (TaskAttachment $gone): void {
                Storage::disk('local')->delete($gone->file_path);
                $gone->delete();
            });
    }

    private static function megabytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024 ? round($bytes / 1024 / 1024).' MB' : max(0, (int) round($bytes / 1024)).' KB';
    }

    /**
     * @param  array<string, mixed>  $attachment
     * @return array<string, mixed>
     */
    private function entry(array $attachment): array
    {
        $url = is_string($attachment['url'] ?? null) ? $attachment['url'] : null;

        return [
            'trello_id' => is_string($attachment['id'] ?? null) ? $attachment['id'] : null,
            'name' => self::safeName(is_string($attachment['name'] ?? null) ? $attachment['name'] : (string) basename((string) parse_url((string) $url, PHP_URL_PATH))),
            'mime' => is_string($attachment['mimeType'] ?? null) && $attachment['mimeType'] !== '' ? $attachment['mimeType'] : null,
            'size' => is_numeric($attachment['bytes'] ?? null) ? (int) $attachment['bytes'] : null,
            'url' => $url,
            'status' => self::STATUS_LINK,
            'reason' => null,
            'attachment_id' => null,
            'failures' => 0,
            'retry_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    private function file(Task $task, TrelloService $trello, array $entry, ?array $previous, CarbonImmutable $deadline, bool $force): array
    {
        if ($entry['trello_id'] === null) {
            return $this->refused($entry, 'The card attachment has no id.');
        }

        $existing = $task->attachments()->where('trello_attachment_id', $entry['trello_id'])->first();
        if ($existing !== null) {
            return [...$entry, 'status' => self::STATUS_SAVED, 'attachment_id' => (int) $existing->id];
        }

        // A refusal stands and a failure waits out its backoff, unless the caller forces a retry.
        if (! $force && $previous !== null) {
            if (($previous['status'] ?? null) === self::STATUS_REFUSED) {
                return [...$entry, 'status' => self::STATUS_REFUSED, 'reason' => $previous['reason'] ?? null];
            }
            if (($previous['status'] ?? null) === self::STATUS_FAILED && is_string($previous['retry_at'] ?? null) && now()->lt($previous['retry_at'])) {
                return [...$entry, 'status' => self::STATUS_FAILED, 'reason' => $previous['reason'] ?? null, 'failures' => (int) ($previous['failures'] ?? 1), 'retry_at' => $previous['retry_at']];
            }
        }

        $fileCap = (int) config('services.trello.attachment_max_kb') * 1024;
        $taskCap = (int) config('services.trello.attachments_task_max_kb') * 1024;

        $extension = mb_strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION) ?: pathinfo($this->downloadName($entry), PATHINFO_EXTENSION));
        if (! TaskAttachmentTypes::allowsExtension($extension)) {
            return $this->refused($entry, $extension === '' ? 'Files without an extension are not allowed.' : 'This file type is not allowed.');
        }

        if ($entry['size'] !== null && $entry['size'] > $fileCap) {
            return $this->refused($entry, 'Larger than the '.self::megabytes($fileCap).' per-file limit.');
        }

        $room = $taskCap - $this->used($task);
        if ($room <= 0 || ($entry['size'] !== null && $entry['size'] > $room)) {
            return $this->refused($entry, 'Over the '.self::megabytes($taskCap).' limit for files pulled into one task.');
        }

        $seconds = (int) min(self::DOWNLOAD_SECONDS, now()->diffInSeconds($deadline, false));
        if ($seconds < self::MIN_DOWNLOAD_SECONDS) {
            return [...$entry, 'status' => self::STATUS_FAILED, 'reason' => 'Not downloaded yet: this fetch ran out of time. It is retried on the next read.', 'failures' => (int) ($previous['failures'] ?? 0), 'retry_at' => now()->toIso8601String()];
        }

        $limit = min($fileCap, $room);
        $directory = "task-attachments/{$task->id}";
        $relative = $directory.'/'.Str::uuid()->toString().'.'.$extension;
        $disk = Storage::disk('local');
        $disk->makeDirectory($directory);
        $absolute = $disk->path($relative);

        try {
            $trello->downloadAttachment((string) $task->trello_card_id, $entry['trello_id'], $this->downloadName($entry), $absolute, $limit, $seconds);
        } catch (TrelloAttachmentTooLarge) {
            $disk->delete($relative);

            return $this->refused($entry, 'Larger than the '.self::megabytes($limit).' limit left for this file.');
        } catch (TrelloRequestFailed $e) {
            $disk->delete($relative);

            return $this->failed($entry, $previous, $e->summary());
        }

        $size = is_file($absolute) ? (int) filesize($absolute) : 0;
        $mime = $size > 0 ? (MimeTypes::getDefault()->guessMimeType($absolute) ?? 'application/octet-stream') : null;

        if ($mime === null) {
            $disk->delete($relative);

            return $this->failed($entry, $previous, 'Trello returned an empty file.');
        }

        if ($size > $limit) {
            $disk->delete($relative);

            return $this->refused($entry, 'Larger than the '.self::megabytes($limit).' limit left for this file.');
        }

        if (! TaskAttachmentTypes::allowsMime($mime)) {
            $disk->delete($relative);

            return $this->refused($entry, 'The file content is not an allowed type.');
        }

        return $this->commit($task, $entry, $relative, $mime, $size, $taskCap);
    }

    /**
     * Stores the file's row under the task's row lock and counts the quota
     * again there, so two pullers never both fit into the same room, and a
     * task deleted during the download keeps no file.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function commit(Task $task, array $entry, string $relative, string $mime, int $size, int $taskCap): array
    {
        $disk = Storage::disk('local');

        try {
            $outcome = DB::transaction(function () use ($task, $entry, $relative, $mime, $size, $taskCap): array|string {
                if (Task::query()->whereKey($task->id)->lockForUpdate()->first() === null) {
                    return 'gone';
                }
                if ($this->used($task) + $size > $taskCap) {
                    return 'quota';
                }

                $saved = TaskAttachment::create([
                    'task_id' => $task->id,
                    'file_path' => $relative,
                    'original_name' => $entry['name'],
                    'mime' => $mime,
                    'size' => $size,
                    'trello_attachment_id' => $entry['trello_id'],
                ]);

                return ['id' => (int) $saved->id];
            });
        } catch (UniqueConstraintViolationException) {
            // Another read of the same card stored it first.
            $disk->delete($relative);
            $saved = $task->attachments()->where('trello_attachment_id', $entry['trello_id'])->firstOrFail();

            return [...$entry, 'mime' => $saved->mime, 'size' => (int) $saved->size, 'status' => self::STATUS_SAVED, 'attachment_id' => (int) $saved->id];
        }

        if ($outcome === 'gone') {
            $disk->delete($relative);
            if ($disk->files("task-attachments/{$task->id}") === []) {
                $disk->deleteDirectory("task-attachments/{$task->id}");
            }

            return $this->refused($entry, 'The task was deleted during the download.');
        }

        if ($outcome === 'quota') {
            $disk->delete($relative);

            return $this->refused($entry, 'Over the '.self::megabytes($taskCap).' limit for files pulled into one task.');
        }

        return [...$entry, 'mime' => $mime, 'size' => $size, 'status' => self::STATUS_SAVED, 'attachment_id' => $outcome['id']];
    }

    private function used(Task $task): int
    {
        return (int) TaskAttachment::query()->where('task_id', $task->id)->whereNotNull('trello_attachment_id')->sum('size');
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function downloadName(array $entry): string
    {
        $segment = basename((string) parse_url((string) $entry['url'], PHP_URL_PATH));

        return $segment !== '' ? rawurldecode($segment) : $entry['name'];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function refused(array $entry, string $reason): array
    {
        return [...$entry, 'status' => self::STATUS_REFUSED, 'reason' => $reason];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    private function failed(array $entry, ?array $previous, string $reason): array
    {
        $failures = (int) ($previous['failures'] ?? 0) + 1;

        return [
            ...$entry,
            'status' => self::STATUS_FAILED,
            'reason' => $reason,
            'failures' => $failures,
            'retry_at' => now()->addSeconds(TaskCardDetails::backoffSeconds($failures))->toIso8601String(),
        ];
    }
}
