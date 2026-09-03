<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when the CLI hook in a live task session reports it needs the user's
 * attention (claude's `Notification` hook: permission prompt or idle > 60s).
 * Broadcasts on a per-task channel so the frontend can fire a toast + flip
 * the kanban card's "waiting" badge.
 */
final class SessionAttention implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $taskId,
        public readonly string $taskName,
        public readonly string $event,
        public readonly ?string $message,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('reverb.session.'.$this->taskId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'reverb.session.attention';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'task_id' => $this->taskId,
            'task_name' => $this->taskName,
            'event' => $this->event,
            'message' => $this->message,
        ];
    }
}
