<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * The demo instance's interactive crm prompt. NOT a shell: the input line is
 * matched against a closed whitelist of crm verbs and executed in-process via
 * Artisan::call() - Symfony StringInput does the tokenizing, so there is no
 * PTY, no process spawn, and no shell interpolation anywhere. Everything it
 * can touch is the demo database, which reseeds nightly; visitors writing
 * timers and notes is the demo working, not a risk.
 */
final class DemoCliController extends Controller
{
    /** Verb => artisan command. Closed list - anything else refuses to parse. */
    private const VERBS = [
        'today' => 'crm:today',
        'brief' => 'crm:brief',
        'timer-start' => 'crm:timer-start',
        'timer-stop' => 'crm:timer-stop',
        'task-done' => 'crm:task-done',
        'note' => 'crm:note',
        'lead-capture' => 'crm:lead-capture',
        'lead-stage' => 'crm:lead-stage',
        'time:log' => 'time:log',
    ];

    private const OUTPUT_CAP = 20_000;

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless((bool) config('app.demo'), 404);

        $line = mb_trim((string) $request->validate([
            'command' => ['required', 'string', 'max:500'],
        ])['command']);

        // Accept both the cheat-sheet form ("crm today") and bare verbs.
        $line = preg_replace('/^crm\s+/', '', $line) ?? $line;

        if ($line === '' || $line === 'crm' || $line === 'help') {
            return response()->json(['output' => $this->helpText()]);
        }

        $space = mb_strpos($line, ' ');
        $verb = $space === false ? $line : mb_substr($line, 0, $space);
        $rest = $space === false ? '' : mb_substr($line, $space + 1);

        if (! isset(self::VERBS[$verb])) {
            return response()->json([
                'output' => "Unknown command \"{$verb}\". Type \"help\" for the available crm verbs.",
            ]);
        }

        $buffer = new BufferedOutput();

        try {
            Artisan::call(mb_trim(self::VERBS[$verb].' '.$rest), [], $buffer);
        } catch (Throwable $e) {
            // Console errors (bad option, missing argument) read fine in a
            // terminal; the demo DB holds nothing sensitive to leak.
            return response()->json(['output' => $e->getMessage()]);
        }

        $output = $buffer->fetch();
        if (mb_strlen($output) > self::OUTPUT_CAP) {
            $output = mb_substr($output, 0, self::OUTPUT_CAP)."\n[output truncated]";
        }

        return response()->json(['output' => $output]);
    }

    private function helpText(): string
    {
        return implode("\n", [
            'crm verbs available in this demo:',
            '',
            '  crm today [--json]                    attention list, timers, hot leads',
            '  crm brief "<client>" [--json]         full client context',
            '  crm timer-start "<task>"              start a live timer',
            '  crm timer-stop                        stop it',
            '  crm time:log <minutes> --task="<t>"   log time after the fact',
            '  crm task-done <id>                    complete a task',
            '  crm note "<client>" "<text>"          journal entry on the timeline',
            '  crm lead-capture --name= --source=    capture a lead',
            '  crm lead-stage <id> <stage>           move a lead through the funnel',
            '',
            'Name arguments accept fragments. The database resets nightly - play freely.',
        ]);
    }
}
