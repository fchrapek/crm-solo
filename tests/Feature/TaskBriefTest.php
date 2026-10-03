<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskBrief;
use App\Models\TaskCardDetails;
use App\Models\User;
use App\Services\Agent\AgentIdentity;
use App\Services\Agent\TaskBriefWriter;
use App\Services\Agent\TaskReadiness;
use App\Services\Agent\TaskRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The brief is the CRM's layer on a task: agents draft it, the owner
 * confirms it, a later change drops the confirmation of what it changed,
 * and readiness reads it alongside the card.
 */
final class TaskBriefTest extends TestCase
{
    use RefreshDatabase;

    private Task $task;

    private AgentIdentity $identity;

    protected function setUp(): void
    {
        parent::setUp();
        $account = Account::factory()->create();
        $owner = User::factory()->create(['account_id' => $account->id, 'owner' => true, 'first_name' => 'Jan', 'last_name' => 'C']);
        $client = Client::factory()->create(['account_id' => $account->id]);
        $project = Project::create(['account_id' => $account->id, 'client_id' => $client->id, 'name' => 'Site']);
        $this->task = Task::create(['project_id' => $project->id, 'name' => 'Footer', 'source' => 'manual']);
        $this->identity = new AgentIdentity($account, $owner);
    }

    /**
     * @return array<string, array{string|null, array<string, string>, array<int, array<string, mixed>>, list<string>}>
     */
    public static function readinessCases(): array
    {
        $words = 'Zmień numer telefonu w stopce na nowy numer biura.';
        $checklist = [['name' => 'QA', 'items' => [['name' => 'Telefon', 'done' => false]]]];

        return [
            'nothing' => [null, [], [], ['description', 'target', 'done_condition']],
            'only a trivial description' => ['Popraw', [], [], ['description', 'target', 'done_condition']],
            'description only' => [$words, [], [], ['target', 'done_condition']],
            'description and a link' => [$words.' https://example.com/kontakt', [], [], ['done_condition']],
            'description and a brief target' => [$words, ['where' => 'footer.php'], [], ['done_condition']],
            'description and a checklist' => [$words, [], $checklist, ['target']],
            'description and a brief done condition' => [$words, ['done_when' => 'Numer widoczny'], [], ['target']],
            'target and done condition without description' => [null, ['where' => 'footer.php', 'done_when' => 'Numer widoczny'], [], ['description']],
            'ready from the brief' => [$words, ['where' => 'footer.php', 'done_when' => 'Numer widoczny'], [], []],
            'ready from the card' => [$words.' https://example.com', [], $checklist, []],
        ];
    }

    public function test_an_agent_write_marks_the_brief_drafted_with_its_actor(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');

        app(TaskBriefWriter::class)->write($this->task, ['where' => 'footer.php', 'done_when' => 'The new phone number shows'], false, $this->identity, TaskBrief::VIA_CLI);

        $brief = $this->record()['brief'];
        $this->assertSame('footer.php', $brief['where']);
        $this->assertSame(['id' => $this->identity->user->id, 'name' => 'Jan C', 'via' => 'cli'], $brief['drafted_by']);
        $this->assertSame('2026-10-01T10:00:00+00:00', $brief['drafted_at']);
        $this->assertNull($brief['confirmed_at']);
        $this->assertSame(['where', 'done_when'], $brief['unconfirmed']);
    }

    public function test_confirm_stamps_every_filled_field(): void
    {
        app(TaskBriefWriter::class)->write($this->task, ['where' => 'footer.php'], false, $this->identity, TaskBrief::VIA_CLI);
        Carbon::setTestNow('2026-10-01 11:00:00');

        app(TaskBriefWriter::class)->write($this->task, [], true, $this->identity, TaskBrief::VIA_CLI);

        $brief = $this->record()['brief'];
        $this->assertSame('2026-10-01T11:00:00+00:00', $brief['confirmed_at']);
        $this->assertSame(['id' => $this->identity->user->id, 'name' => 'Jan C'], $brief['confirmed_by']);
        $this->assertSame([], $brief['unconfirmed']);
    }

    public function test_a_later_agent_change_drops_only_that_fields_confirmation(): void
    {
        app(TaskBriefWriter::class)->write($this->task, ['where' => 'footer.php', 'notes' => 'Ask Anna'], true, $this->identity, TaskBrief::VIA_CLI);

        app(TaskBriefWriter::class)->write($this->task, ['where' => 'partials/footer.php', 'notes' => 'Ask Anna'], false, $this->identity, TaskBrief::VIA_MCP);

        $brief = $this->record()['brief'];
        $this->assertSame(['where'], $brief['unconfirmed']);
        $this->assertNull($brief['confirmed_at']);
        $this->assertSame('mcp', $brief['drafted_by']['via']);
        $this->assertArrayHasKey('notes', TaskBrief::sole()->confirmations);
    }

    public function test_writing_the_same_text_again_changes_nothing(): void
    {
        app(TaskBriefWriter::class)->write($this->task, ['where' => 'footer.php'], true, $this->identity, TaskBrief::VIA_CLI);

        app(TaskBriefWriter::class)->write($this->task, ['where' => '  footer.php '], false, $this->identity, TaskBrief::VIA_MCP);

        $this->assertSame([], $this->record()['brief']['unconfirmed']);
        $this->assertSame('cli', TaskBrief::sole()->drafted_via);
    }

    public function test_an_empty_value_clears_the_field_and_its_confirmation(): void
    {
        app(TaskBriefWriter::class)->write($this->task, ['where' => 'footer.php', 'notes' => 'x'], true, $this->identity, TaskBrief::VIA_CLI);

        app(TaskBriefWriter::class)->write($this->task, ['notes' => ''], false, $this->identity, TaskBrief::VIA_CLI);

        $brief = TaskBrief::sole();
        $this->assertNull($brief->notes);
        $this->assertSame(['where'], array_keys($brief->confirmations));
    }

    public function test_brief_text_is_humanized_but_card_text_is_not(): void
    {
        $card = Task::create([
            'project_id' => $this->task->project_id, 'name' => 'Karta — “pilna”', 'source' => 'trello', 'trello_card_id' => 'c1',
            'description' => 'Opis — “klienta”…',
        ]);
        TaskCardDetails::create([
            'task_id' => $card->id,
            'checklists' => [['name' => 'Lista — “QA”', 'items' => [['name' => 'Punkt — “1”', 'done' => false]]]],
            'comments' => [['author' => 'Anna', 'at' => null, 'text' => 'Stopka — “pilne”']],
            'fetched_at' => now(),
        ]);

        app(TaskBriefWriter::class)->write($card, ['constraints' => 'Nie ruszaj menu — “tylko stopka”'], false, $this->identity, TaskBrief::VIA_CLI);

        $record = app(TaskRecord::class)->build($card->fresh());
        $this->assertSame('Nie ruszaj menu - "tylko stopka"', $record['brief']['constraints']);
        $this->assertSame('Karta — “pilna”', $record['name']);
        $this->assertSame('Opis — “klienta”…', $record['description']['markdown']);
        $this->assertSame('Lista — “QA”', $record['checklists'][0]['name']);
        $this->assertSame('Punkt — “1”', $record['checklists'][0]['items'][0]['name']);
        $this->assertSame('Stopka — “pilne”', $record['comments'][0]['text']);
    }

    public function test_a_manual_task_description_is_still_humanized(): void
    {
        $this->task->update(['description' => 'Opis — “mój”']);

        $this->assertSame('Opis - "mój"', $this->task->fresh()->description);
    }

    /**
     * @param  array<string, string>  $brief
     * @param  array<int, array<string, mixed>>  $checklists
     * @param  list<string>  $missing
     */
    #[DataProvider('readinessCases')]
    public function test_readiness_names_what_is_missing(?string $description, array $brief, array $checklists, array $missing): void
    {
        $this->task->update(['description' => $description]);
        if ($brief !== []) {
            app(TaskBriefWriter::class)->write($this->task, $brief, false, $this->identity, TaskBrief::VIA_CLI);
        }
        if ($checklists !== []) {
            TaskCardDetails::create(['task_id' => $this->task->id, 'checklists' => $checklists, 'fetched_at' => now()]);
        }

        $task = $this->task->fresh();
        $this->assertSame(['ready' => $missing === [], 'missing' => $missing], TaskReadiness::forTask($task));
        $this->assertSame(TaskReadiness::forTask($task), $this->record()['readiness']);
    }

    /**
     * @return array<string, mixed>
     */
    private function record(): array
    {
        return app(TaskRecord::class)->build($this->task->fresh());
    }
}
