<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\RefreshTaskCardDetailsJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCardDetails;
use App\Models\User;
use App\Support\PrefetchRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Inertia\Support\Header;
use Tests\TestCase;

/** A page fetched ahead on hover may never be shown: it reads only, and the visit does what a visit does. */
final class PrefetchRequestsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false, 'app.display_timezone' => 'Europe/Warsaw']);
        Carbon::setTestNow('2026-10-08 10:00:00');

        $account = Account::factory()->create();
        $this->owner = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $client = Client::factory()->create(['account_id' => $account->id, 'name' => 'Pracownia Mebli']);
        $this->project = Project::create(['account_id' => $account->id, 'client_id' => $client->id, 'name' => 'Strona WWW']);
        $this->task = Task::create(['project_id' => $this->project->id, 'name' => 'Cennik PDF', 'source' => 'manual', 'list_name' => 'Doing']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_inertias_prefetch_and_a_browsers_own_are_told_apart_from_a_visit(): void
    {
        $request = fn (array $headers): Request => Request::create('/clients', 'GET', server: collect($headers)->mapWithKeys(fn ($value, $name) => ['HTTP_'.mb_strtoupper(str_replace('-', '_', $name)) => $value])->all());

        $this->assertTrue(PrefetchRequest::is($request(['Purpose' => 'prefetch'])));
        $this->assertTrue(PrefetchRequest::is($request(['Sec-Purpose' => 'prefetch;prerender'])));
        $this->assertFalse(PrefetchRequest::is($request([])));
        $this->assertFalse(PrefetchRequest::is($request(['Purpose' => 'navigate'])));
    }

    public function test_a_hover_between_a_save_and_its_page_leaves_the_saves_message_for_that_page(): void
    {
        $this->actingAs($this->owner)->from("/tasks/{$this->task->id}")
            ->put("/tasks/{$this->task->id}", ['name' => 'Cennik PDF 2', 'list_name' => 'Doing'])
            ->assertRedirect("/tasks/{$this->task->id}");

        $hover = $this->actingAs($this->owner)->withHeaders([...$this->inertia(), 'Purpose' => 'prefetch'])->get('/contacts');
        $hover->assertOk()->assertJsonPath('props.flash.success', null);

        $page = $this->flushHeaders()->actingAs($this->owner)->withHeaders($this->inertia())->get("/tasks/{$this->task->id}");
        $page->assertJsonPath('props.flash.success', __('Task updated.'));

        $this->actingAs($this->owner)->withHeaders($this->inertia())->get('/contacts')->assertJsonPath('props.flash.success', null);
    }

    public function test_a_hover_on_a_stale_card_starts_its_refresh_once_while_it_is_in_flight(): void
    {
        Bus::fake();
        $card = Task::create(['project_id' => $this->project->id, 'name' => 'Card', 'source' => 'trello', 'trello_card_id' => 'c1', 'trello_activity_at' => '2026-10-08 09:00:00']);
        TaskCardDetails::create(['task_id' => $card->id, 'checklists' => [], 'comments' => [], 'card_attachments' => [], 'card_activity_at' => '2026-10-08 08:00:00', 'fetched_at' => '2026-10-08 08:30:00']);

        $this->actingAs($this->owner)->withHeaders([...$this->inertia(), 'Purpose' => 'prefetch'])->get("/tasks/{$card->id}")->assertOk();
        Bus::assertDispatched(RefreshTaskCardDetailsJob::class, fn (RefreshTaskCardDetailsJob $job) => $job->taskId === $card->id);

        $this->actingAs($this->owner)->withHeaders([...$this->inertia(), 'Purpose' => 'prefetch'])->get("/tasks/{$card->id}")->assertOk();
        $this->flushHeaders()->actingAs($this->owner)->withHeaders($this->inertia())->get("/tasks/{$card->id}")->assertOk();
        Bus::assertDispatchedTimes(RefreshTaskCardDetailsJob::class, 1);
    }

    /** @return array<string, string> */
    private function inertia(): array
    {
        return [Header::INERTIA => 'true', Header::VERSION => (string) app(HandleInertiaRequests::class)->version(request())];
    }
}
