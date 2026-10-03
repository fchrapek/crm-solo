<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Repository;
use App\Models\Setting;
use App\Services\Concerns\ManagesTtydProcess;
use App\Support\HostExec;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Status-first bridge to herdr (https://herdr.dev), the terminal agent
 * multiplexer: live agent states mapped onto clients by repository path, plus
 * an optional browser attach. herdr's daemon holds the sessions, so closing
 * the browser view never stops an agent.
 */
final class DailySessionService
{
    use ManagesTtydProcess;

    private const SETTING_SCOPE = 'daily_session';

    /**
     * Live snapshot for the dashboard card.
     *
     * @return array<string, mixed>
     */
    public function snapshot(int $accountId): array
    {
        // The hosted demo has no herdr, no repos, and must never spawn a
        // shell - it gets fixture agents plus the whitelisted crm prompt.
        if (config('app.demo')) {
            return $this->demoSnapshot($accountId);
        }

        if (! HostExec::enabled()) {
            return ['available' => false, 'agents' => [], 'attach' => null];
        }

        $herdr = $this->locateBinary(config('terminal.daily_command', 'herdr'), throwIfMissing: false);
        if ($herdr === null) {
            return ['available' => false, 'agents' => [], 'attach' => $this->attachState($accountId)];
        }

        // Web SAPI child processes don't reliably inherit HOME, and the
        // herdr client finds its daemon socket via $HOME/.config/herdr —
        // resolve the home dir from the OS and pass it explicitly.
        $proc = new Process([$herdr, 'agent', 'list'], null, ['HOME' => $this->userHome()]);
        $proc->setTimeout(15);
        $proc->run();
        if (! $proc->isSuccessful()) {
            return [
                'available' => true,
                'running' => false,
                'agents' => [],
                'attach' => $this->attachState($accountId),
                // Surfaced so a broken PATH/daemon shows a diagnosable reason
                // instead of a silent "not running".
                'error' => mb_trim($proc->getErrorOutput()."\n".$proc->getOutput()),
            ];
        }

        $payload = json_decode($proc->getOutput(), true);
        $agents = is_array($payload) ? ($payload['result']['agents'] ?? []) : [];

        $repos = Repository::query()
            ->whereNotNull('local_path')
            ->with('project.client:id,name')
            ->get();

        $mapped = [];
        foreach ($agents as $agent) {
            $cwd = (string) ($agent['cwd'] ?? '');
            $repo = $repos->first(fn (Repository $r): bool => $r->local_path !== null && str_starts_with($cwd, mb_rtrim($r->local_path, '/')));

            $mapped[] = [
                'agent' => $agent['agent'] ?? 'unknown',
                'status' => $agent['agent_status'] ?? 'unknown',
                'title' => $agent['terminal_title_stripped'] ?? '',
                'cwd' => $cwd,
                'dir' => basename($cwd),
                'client' => $repo?->project?->client?->only(['id', 'name']),
                'project' => $repo?->project?->name,
            ];
        }

        return [
            'available' => true,
            'running' => true,
            'agents' => $mapped,
            'attach' => $this->attachState($accountId),
            'dangling_timers' => $this->danglingTimers($accountId),
        ];
    }

    /**
     * Spawn a browser-facing ttyd running the daily command. herdr reattaches
     * to its own daemon, so this is a viewport, not a session owner.
     *
     * @return array{port: int, pid: int}
     */
    public function attach(int $accountId): array
    {
        HostExec::ensureEnabled();

        $existing = $this->attachState($accountId);
        if ($existing !== null) {
            return $existing;
        }

        $command = config('terminal.daily_command', 'herdr');
        $binary = $this->locateBinary($command, throwIfMissing: false)
            ?? throw new RuntimeException("Daily session command not found: {$command}. Configure DAILY_SESSION_COMMAND.");

        $ttyd = $this->locateTtyd();
        $port = $this->allocateFreePort();
        $cwd = (string) (config('terminal.daily_cwd') ?? $this->userHome() ?? '/');

        $inner = sprintf(
            'export HOME=%s; cd %s; exec %s',
            escapeshellarg((string) $this->userHome()),
            escapeshellarg($cwd),
            escapeshellarg($binary),
        );
        $ttydCmd = sprintf(
            '%s -p %d -i 127.0.0.1 -W -O -t titleFixed=daily-session %s -lc %s',
            escapeshellarg($ttyd),
            $port,
            escapeshellarg('/bin/bash'),
            escapeshellarg($inner),
        );

        $logPath = storage_path('logs/ttyd-daily-session.log');
        $pid = $this->spawnDetachedTtyd($ttydCmd, $logPath, 'Daily session ttyd');

        Setting::updateOrCreate(
            ['account_id' => $accountId, 'scope' => self::SETTING_SCOPE],
            ['data' => ['port' => $port, 'pid' => $pid, 'started_at' => now()->toIso8601String()]],
        );

        return ['port' => $port, 'pid' => $pid];
    }

    /**
     * Kill the browser viewport only — the herdr daemon (and every agent in
     * it) keeps running; the native terminal attach is untouched.
     */
    public function detach(int $accountId): void
    {
        $state = $this->attachState($accountId);
        if ($state !== null && $this->isProcessAlive($state['pid'])) {
            posix_kill($state['pid'], SIGTERM);
        }

        Setting::query()
            ->where('account_id', $accountId)
            ->where('scope', self::SETTING_SCOPE)
            ->delete();
    }

    /**
     * Demo card state: three seeded clients dressed up as agent sessions (the
     * links land on real demo records) and the demo_cli flag that swaps the
     * ttyd viewport for the whitelisted in-process crm prompt.
     *
     * @return array<string, mixed>
     */
    private function demoSnapshot(int $accountId): array
    {
        $titles = [
            ['status' => 'working', 'title' => 'Rework the pricing page copy'],
            ['status' => 'idle', 'title' => 'Monthly maintenance: updates + backup check'],
            ['status' => 'blocked', 'title' => 'Fix the cookie banner on mobile'],
        ];

        $agents = \App\Models\Client::query()
            ->where('account_id', $accountId)
            ->orderBy('id')
            ->limit(3)
            ->get(['id', 'name'])
            ->values()
            ->map(fn ($client, int $index) => [
                'agent' => 'claude',
                'status' => $titles[$index % 3]['status'],
                'title' => $titles[$index % 3]['title'],
                'cwd' => '/home/demo/'.\Illuminate\Support\Str::slug($client->name),
                'dir' => \Illuminate\Support\Str::slug($client->name),
                'client' => $client->only(['id', 'name']),
                'project' => null,
            ])
            ->all();

        return [
            'available' => true,
            'running' => true,
            'agents' => $agents,
            'attach' => null,
            'demo_cli' => true,
            'dangling_timers' => $this->danglingTimers($accountId),
        ];
    }

    /**
     * Open time entries (end_time NULL) — the "start the day fresh" check.
     * A timer left running overnight silently inflates billing; the card
     * surfaces it first thing.
     *
     * @return array<int, array<string, mixed>>
     */
    private function danglingTimers(int $accountId): array
    {
        return \App\Models\TimeEntry::query()
            ->whereNull('end_time')
            ->whereHas('client', fn ($q) => $q->where('account_id', $accountId))
            ->with(['client:id,name', 'task:id,name'])
            ->get()
            ->map(fn ($entry) => [
                'id' => $entry->id,
                'client' => $entry->client?->only(['id', 'name']),
                'task' => $entry->task?->name,
                'started_at' => $entry->start_time?->toIso8601String(),
                'minutes' => $entry->start_time !== null ? (int) $entry->start_time->diffInMinutes(now()) : null,
            ])
            ->values()
            ->all();
    }

    private function userHome(): ?string
    {
        $home = $_SERVER['HOME'] ?? null;
        if (is_string($home) && $home !== '') {
            return $home;
        }
        if (function_exists('posix_getpwuid')) {
            return posix_getpwuid(posix_geteuid())['dir'] ?? null;
        }

        return null;
    }

    /** @return array{port: int, pid: int}|null Live attach state, self-healing on dead PIDs. */
    private function attachState(int $accountId): ?array
    {
        $data = Setting::query()
            ->where('account_id', $accountId)
            ->where('scope', self::SETTING_SCOPE)
            ->value('data');

        if (! is_array($data) || ! isset($data['pid'], $data['port'])) {
            return null;
        }

        if (! $this->isProcessAlive((int) $data['pid'])) {
            Setting::query()->where('account_id', $accountId)->where('scope', self::SETTING_SCOPE)->delete();

            return null;
        }

        return ['port' => (int) $data['port'], 'pid' => (int) $data['pid']];
    }
}
