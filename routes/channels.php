<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('reverb.{uuid}', function () {
    return true;
});

// Per-task session attention channel — receives SessionAttention events when
// the CLI's hook fires. Public on purpose: payload is just task_id + name +
// short event name, no sensitive content. The hook itself is gated by the
// per-session token; this channel is read-only from the browser's side.
Broadcast::channel('reverb.session.{taskId}', function () {
    return true;
});
