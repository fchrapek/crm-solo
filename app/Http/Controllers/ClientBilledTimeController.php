<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Services\Clients\BilledTime;
use App\Services\Clients\BilledTimeResult;
use App\Support\LocalCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

/** Billed time of a client's month and its task figures (the Billing dialog), all through BilledTime. */
final class ClientBilledTimeController extends Controller
{
    public function __construct(private readonly BilledTime $billed) {}

    public function show(Client $client, string $period): JsonResponse
    {
        abort_unless(LocalCalendar::isMonth($period), 404);

        return response()->json($this->billed->figures($client, $period));
    }

    /** Sets one task's billed hours for the month; the month's total is the sum of its tasks. */
    public function updateTask(Request $request, Client $client, string $period, Task $task): RedirectResponse
    {
        $this->assertTaskOf($client, $period, $task);
        $data = $request->validate([
            'hours' => ['required', 'numeric', 'between:0,9999'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        // A missing note keeps the stored one; a sent empty one clears it.
        $note = array_key_exists('note', $data) ? (string) ($data['note'] ?? '') : null;

        return $this->answered($this->billed->setTask($task, $period, (float) $data['hours'], $note, Auth::user()));
    }

    /** Drops the task's figure, so it bills its real time again. */
    public function destroyTask(Client $client, string $period, Task $task): RedirectResponse
    {
        $this->assertTaskOf($client, $period, $task);

        return $this->answered($this->billed->setTask($task, $period, null, null, Auth::user()));
    }

    private function assertTaskOf(Client $client, string $period, Task $task): void
    {
        abort_unless(LocalCalendar::isMonth($period), 404);
        abort_unless((int) Project::query()->whereKey($task->project_id)->value('client_id') === $client->id, 404);
    }

    private function answered(BilledTimeResult $result): RedirectResponse
    {
        if ($result->isRefused()) {
            return Redirect::back()->with('error', $result->message);
        }

        return $result->message !== '' ? Redirect::back()->with('success', $result->message) : Redirect::back();
    }
}
