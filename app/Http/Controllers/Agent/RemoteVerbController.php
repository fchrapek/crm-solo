<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Services\Agent\AgentAbilities;
use App\Services\Agent\AgentCallContext;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The crm shim's remote mode: one verb and its argument list, run in-process
 * as the token's identity, answered with exactly what the verb prints and its
 * exit status (X-Crm-Exit). The arguments arrive as a list and are handed to
 * the command as argv tokens: nothing is parsed from a command line and no
 * process is spawned. AgentAbilities::VERBS is the whole surface.
 */
final class RemoteVerbController extends Controller
{
    public function __invoke(Request $request, AgentCallContext $context): Response
    {
        // Answered as text, never as the redirect a failed validate() gives a non-JSON caller.
        $validator = Validator::make($request->all(), [
            'verb' => ['required', 'string', 'max:64'],
            'args' => ['sometimes', 'array', 'list', 'max:64'],
            'args.*' => ['string', 'max:20000'],
        ]);
        if ($validator->fails()) {
            return $this->text('crm: '.$validator->errors()->first()."\n", 1, 422);
        }

        $verb = (string) $request->input('verb');
        /** @var list<string> $args */
        $args = array_values((array) $request->input('args', []));

        $needed = AgentAbilities::forVerb($verb, $args);
        if ($needed === null) {
            return $this->text(
                "crm: \"{$verb}\" is not available in remote mode. Remote verbs: ".implode(', ', array_keys(AgentAbilities::VERBS)).".\n",
                1,
                404,
            );
        }

        $missing = $context->token() === null ? $needed : $context->missing($needed);
        if ($missing !== []) {
            return $this->text("crm: this token cannot run \"{$verb}\": it needs the ".implode(', ', $missing)." ability.\n", 1, 403);
        }

        $name = AgentAbilities::VERBS[$verb][0];
        $class = AgentAbilities::COMMANDS[$name] ?? null;
        if ($class === null) {
            return $this->text("crm: \"{$verb}\" is not installed on this server.\n", 1, 404);
        }

        // Only this command is built, never the whole console application, so
        // the argv carries no command token: without an Application there is
        // no merged `command` argument to take it.
        /** @var Command $command */
        $command = app($class);
        $command->setLaravel(app());
        $input = new ArgvInput(['crm', ...$args]);
        $input->setInteractive(false);
        $output = new BufferedOutput;

        $current = $context->current();
        $call = $current !== null ? $current->withVerb($name) : null;

        try {
            $exit = $call !== null
                ? $context->run($call, fn (): int => $command->run($input, $output))
                : $command->run($input, $output);
        } catch (ConsoleException $e) {
            $output->writeln($e->getMessage());
            $exit = 1;
        } catch (Throwable $e) {
            report($e);
            $output->writeln("crm: \"{$verb}\" failed on the server. The error is in the server log.");
            $exit = 1;
        }

        $text = $output->fetch();
        $cap = (int) config('agent.output_cap');
        if (mb_strlen($text, '8bit') > $cap) {
            return $this->tooLarge($text, $cap, in_array('--json', $args, true) && ! in_array('--', $args, true));
        }

        return $this->text($text, $exit);
    }

    /**
     * Output over the cap fails the call: JSON callers get one complete error
     * object, text callers a prefix cut on a character boundary and a marker.
     */
    private function tooLarge(string $text, int $cap, bool $json): Response
    {
        $bytes = mb_strlen($text, '8bit');
        if ($json) {
            return $this->text(json_encode([
                'error' => 'output_too_large',
                'message' => "The output was {$bytes} bytes, over the {$cap} byte limit. Narrow the request.",
                'bytes' => $bytes,
                'limit' => $cap,
            ], JSON_UNESCAPED_SLASHES)."\n", 1);
        }

        return $this->text(mb_strcut($text, 0, $cap)."\n[output truncated: {$bytes} bytes, limit {$cap}]\n", 1);
    }

    private function text(string $body, int $exit, int $status = 200): Response
    {
        return response($body, $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Crm-Exit' => (string) $exit,
            'Cache-Control' => 'no-store',
        ]);
    }
}
