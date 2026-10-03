<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use App\Support\HostExec;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Shared ttyd/tmux process plumbing for TerminalSessionLauncher and
 * ProjectPreviewLauncher — binary discovery, port allocation, liveness
 * checks, and the detached nohup spawn. Extracted so portability fixes
 * (new binary paths, spawn flags) land in one place.
 */
trait ManagesTtydProcess
{
    private function locateTtyd(): string
    {
        return $this->locateBinary('ttyd', throwIfMissing: true)
            ?? throw new RuntimeException('ttyd binary not found. Install it (macOS: brew install ttyd / Debian: apt install ttyd).');
    }

    private function locateTmux(bool $throwIfMissing = true): ?string
    {
        $path = $this->locateBinary('tmux', $throwIfMissing);
        if ($path === null && $throwIfMissing) {
            throw new RuntimeException('tmux binary not found. Install it (macOS: brew install tmux / Debian: apt install tmux).');
        }

        return $path;
    }

    private function locateBinary(string $name, bool $throwIfMissing): ?string
    {
        HostExec::ensureEnabled();

        $candidates = ["/opt/homebrew/bin/{$name}", "/usr/local/bin/{$name}", "/usr/bin/{$name}"];
        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        $which = mb_trim((string) shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null'));
        if ($which !== '' && is_executable($which)) {
            return $which;
        }

        return null;
    }

    private function allocateFreePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("Failed to allocate free port: {$errstr}");
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) mb_substr($name, (int) mb_strrpos($name, ':') + 1);
        if ($port <= 0) {
            throw new RuntimeException('Failed to parse allocated port.');
        }

        return $port;
    }

    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        // Signal 0 = existence check, no actual signal sent.
        return @posix_kill($pid, 0);
    }

    /**
     * Detached spawn: nohup + & + redirected fds so the process outlives the
     * PHP request. No setsid (macOS lacks it) — callers kill the ttyd PID
     * directly on stop and rely on its signal handler for the inner shell.
     *
     * `env -i …` (SpawnEnvironment) strips the parent env so the spawned
     * shell + whatever runs inside it do NOT inherit CRM Solo's DB/Redis/
     * OAuth credentials. Without this, an agent running `php artisan migrate`
     * inside a different project's repo wipes CRM Solo's DB (2026-05-27).
     *
     * @param  string  $failureLabel  Prefix for error messages ('ttyd' / 'Preview ttyd')
     */
    private function spawnDetachedTtyd(string $ttydCmd, string $logPath, string $failureLabel): int
    {
        HostExec::ensureEnabled();

        $envPrefix = SpawnEnvironment::allowlistedPrefix();
        $full = sprintf(
            'nohup %s %s >%s 2>&1 < /dev/null & echo $!',
            $envPrefix,
            $ttydCmd,
            escapeshellarg($logPath),
        );

        $output = shell_exec($full);
        $pid = (int) mb_trim((string) $output);
        if ($pid <= 0) {
            throw new RuntimeException("Failed to spawn {$failureLabel} process.");
        }

        // Give ttyd a beat to bind the port; surface bind failure early.
        usleep(300_000);
        if (! $this->isProcessAlive($pid)) {
            $tail = is_file($logPath) ? (string) shell_exec(sprintf('tail -n 10 %s', escapeshellarg($logPath))) : '';
            throw new RuntimeException("{$failureLabel} exited immediately: ".mb_trim($tail));
        }

        return $pid;
    }

    /**
     * @param  array<int,string>  $command
     */
    private function run(array $command): Process
    {
        HostExec::ensureEnabled();

        $proc = new Process($command, null, SpawnEnvironment::withoutGitRepository());
        $proc->setTimeout(30);
        $proc->run();

        return $proc;
    }
}
