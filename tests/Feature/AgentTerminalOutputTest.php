<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\LiteralText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Text from cards reaches a terminal only through the literal renderer and
 * only inside the external-content fence: no escape sequence, line or
 * paragraph separator or bidi control acts, and no title prints outside the
 * block, on any verb that prints a task or a candidate list.
 */
final class AgentTerminalOutputTest extends TestCase
{
    use RefreshDatabase;

    private const string HOSTILE = "Hostile \e[2J\e[H title\u{2028}line\u{202E}reversed\u{2066}x";

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $account = Account::factory()->create();
        User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $client = Client::factory()->create(['account_id' => $account->id, 'name' => "Client \e]0;owned\x07"]);
        $project = Project::create(['account_id' => $account->id, 'client_id' => $client->id, 'name' => "Board \u{202E}evil"]);
        $this->task = Task::create([
            'project_id' => $project->id, 'name' => self::HOSTILE, 'source' => 'trello', 'trello_card_id' => 'c1',
            'list_name' => 'To-Do', 'priority' => 'high', 'description' => "Body \e[31m\u{2029}end",
        ]);
        Task::create(['project_id' => $project->id, 'name' => 'Hostile twin', 'source' => 'manual', 'priority' => 'high']);
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function verbs(): array
    {
        return [
            'task' => ['crm:task', ['task' => '__ID__']],
            'task-brief' => ['crm:task-brief', ['task' => '__ID__']],
            'task ambiguity' => ['crm:task', ['task' => 'ostile']],
            'task-brief ambiguity' => ['crm:task-brief', ['task' => 'ostile']],
            'timer-start ambiguity' => ['crm:timer-start', ['task' => 'ostile']],
            'timer-start' => ['crm:timer-start', ['task' => '__ID__']],
            'task-done' => ['crm:task-done', ['task' => '__ID__']],
            'today' => ['crm:today', []],
            'brief' => ['crm:brief', ['client' => 'Client']],
        ];
    }

    public function test_the_renderer_shows_every_dangerous_character_as_a_code(): void
    {
        $this->assertSame(
            'Hostile \\u001B[2J\\u001B[H title\\u2028line\\u202Ereversed\\u2066x',
            LiteralText::render(self::HOSTILE),
        );
        $this->assertSame("a\nb", LiteralText::render("a\nb", multiline: true));
        $this->assertSame('a\\u000Ab\\u0085c\\u061Cd\\u200Fe', LiteralText::render("a\nb\u{0085}c\u{061C}d\u{200F}e"));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('verbs')]
    public function test_no_verb_prints_card_text_raw_or_outside_the_fence(string $command, array $arguments): void
    {
        $arguments = array_map(fn (mixed $v): mixed => $v === '__ID__' ? (string) $this->task->id : $v, $arguments);

        Artisan::call($command, $arguments);
        $out = Artisan::output();

        foreach (["\e", "\x07", "\u{2028}", "\u{2029}", "\u{202E}", "\u{2066}"] as $raw) {
            $this->assertStringNotContainsString($raw, $out, $command.' printed a raw control character');
        }
        $this->assertStringContainsString('Hostile', $out);

        $outside = (string) preg_replace('/===== BEGIN EXTERNAL CONTENT ([0-9a-f]{8}) .*?===== END EXTERNAL CONTENT \1 =====/s', '', $out);
        $this->assertStringNotContainsString('Hostile', $outside, $command.' printed a card title outside the fence');
        $this->assertStringNotContainsString('Board', $outside);
        $this->assertStringNotContainsString('owned', $outside);
    }
}
