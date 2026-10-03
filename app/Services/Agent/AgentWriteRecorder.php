<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\AgentAuditEvent;
use App\Models\ClientLifecycleEvent;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;

/**
 * Model observer for every record an agent can write. While an agent call is
 * open (AgentCallContext) it stamps the actor on the record where the table
 * has room for it, and appends one audit row per created, updated or deleted
 * record. Outside an agent call (the web UI, syncs) it does nothing.
 */
final readonly class AgentWriteRecorder
{
    /** Never worth an audit entry on their own. */
    private const array IGNORED = ['created_at', 'updated_at'];

    public function creating(Model $model): void
    {
        $call = $this->context()->current();
        if ($call === null) {
            return;
        }
        $identity = $this->context()->identity();

        if ($model instanceof TimeEntry) {
            $model->actor_user_id = $identity->user?->id;
            $model->actor_token_id = $identity->token?->id;
            $model->actor_via = $call->via;
            $model->actor_session_id = $call->sessionId;
        } elseif ($model instanceof ClientLifecycleEvent) {
            $model->user_id ??= $identity->user?->id;
            $model->actor_token_id = $identity->token?->id;
            $model->actor_via = $call->via;
            $model->actor_session_id = $call->sessionId;
        }
    }

    public function saving(Model $model): void
    {
        $call = $this->context()->current();
        if ($call === null || ! $model instanceof Task || ! $model->isDirty('finished_at')) {
            return;
        }

        $finished = $model->finished_at !== null;
        $identity = $finished ? $this->context()->identity() : null;
        $model->finished_by_user_id = $identity?->user?->id;
        $model->finished_by_token_id = $identity?->token?->id;
        $model->finished_via = $finished ? $call->via : null;
        $model->finished_session_id = $finished ? $call->sessionId : null;
    }

    public function created(Model $model): void
    {
        $this->record($model, 'created', null);
    }

    public function updated(Model $model): void
    {
        $changed = array_diff(array_keys($model->getChanges()), $this->skipped($model));
        if ($changed === []) {
            return;
        }

        $before = [];
        foreach ($changed as $key) {
            $before[$key] = $model->getRawOriginal($key);
        }

        $this->recordUpdate($model, $before);
    }

    /**
     * For a write made with a query update, which fires no model event.
     *
     * @param  array<string, mixed>  $before
     */
    public function recordUpdate(Model $model, array $before): void
    {
        $this->record($model, 'updated', ['before' => array_diff_key($before, array_flip($this->skipped($model)))]);
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'deleted', ['before' => array_diff_key($model->getRawOriginal(), array_flip($this->skipped($model)))]);
    }

    /**
     * Read per call from the current container: under Octane the event
     * dispatcher builds observers from the boot-time app, not the request's.
     */
    private function context(): AgentCallContext
    {
        return Container::getInstance()->make(AgentCallContext::class);
    }

    /**
     * Columns never copied into the trail: timestamps, and whatever the model
     * hides from serialization (credentials such as a task's session token).
     *
     * @return list<string>
     */
    private function skipped(Model $model): array
    {
        return [...self::IGNORED, ...$model->getHidden()];
    }

    /**
     * @param  array<string, mixed>|null  $changes
     */
    private function record(Model $model, string $action, ?array $changes): void
    {
        $call = $this->context()->current();
        if ($call === null) {
            return;
        }
        $identity = $this->context()->identity();

        AgentAuditEvent::create([
            'account_id' => $identity->account->id,
            'user_id' => $identity->user?->id,
            'token_id' => $identity->token?->id,
            'actor' => $this->actorName($identity),
            'via' => $call->via,
            'session_id' => $call->sessionId,
            'verb' => $call->verb,
            'action' => $action,
            'target_type' => $model->getTable(),
            'target_id' => (int) $model->getKey(),
            'changes' => $changes,
        ]);
    }

    private function actorName(AgentIdentity $identity): string
    {
        if ($identity->token !== null) {
            return mb_substr('token:'.$identity->token->name, 0, 191);
        }

        $name = mb_trim((string) $identity->user?->name);

        return mb_substr($name !== '' ? 'user:'.$name : 'owner', 0, 191);
    }
}
