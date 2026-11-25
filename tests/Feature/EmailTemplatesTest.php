<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Agent\AgentRefusal;
use App\Services\Messaging\MessageRenderer;
use App\Services\Messaging\MessageSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\TestCase;

/**
 * Email templates sit in message_templates beside the text message ones: a
 * subject and a body, the month placeholders only there, filled from a real
 * client and month in the preview.
 */
final class EmailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 10:00:00');
        $account = Account::factory()->create();
        $this->owner = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $this->client = Client::factory()->create(['account_id' => $account->id, 'name' => 'Brzozowy Sad', 'month_close_type' => 'maintenance', 'report_mode' => 'summary_email']);
        $this->client->retainers()->create(['account_id' => $account->id, 'monthly_hours' => 5, 'monthly_fee' => 500, 'currency' => 'PLN', 'effective_from' => '2025-01-01']);
        $project = Project::create(['account_id' => $account->id, 'client_id' => $this->client->id, 'name' => 'Strona']);
        $done = Task::create(['project_id' => $project->id, 'name' => 'Aktualizacja wtyczek.', 'source' => 'manual', 'list_name' => 'Done', 'is_reportable' => true, 'is_completed' => true, 'finished_at' => '2026-10-20 12:00:00']);
        Task::create(['project_id' => $project->id, 'name' => 'Nowa podstrona', 'source' => 'manual', 'list_name' => 'Doing', 'is_reportable' => true]);
        $open = Task::query()->where('name', 'Nowa podstrona')->sole();
        Task::create(['project_id' => $project->id, 'name' => 'Wewnętrzne', 'source' => 'manual', 'list_name' => 'Done', 'is_reportable' => false, 'is_completed' => true, 'finished_at' => '2026-10-21 12:00:00']);
        foreach ([[$done, 120], [$open, 60]] as [$task, $minutes]) {
            TimeEntry::create([
                'account_id' => $account->id, 'project_id' => $project->id, 'client_id' => $this->client->id, 'task_id' => $task->id,
                'start_time' => '2026-10-10 08:00:00', 'end_time' => Carbon::parse('2026-10-10 08:00:00')->addMinutes($minutes), 'duration_minutes' => $minutes, 'source' => 'manual', 'billable' => true,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_email_template_is_saved_humanized_with_its_subject_and_listed_apart_from_the_text_messages(): void
    {
        $this->actingAs($this->owner)->post('/settings/email/templates', [
            'name' => 'Podsumowanie miesiąca',
            'subject' => 'Podsumowanie — {period}',
            'body' => "Dzień dobry,\n\nw {period} zrobiliśmy:\n\n{work_list}\n\n{hours_summary}\n\n“Pozdrawiam”",
        ])->assertSessionHasNoErrors();

        $template = MessageTemplate::query()->sole();
        $this->assertSame([MessageTemplate::EMAIL, 'podsumowanie_miesiaca'], [$template->channel, $template->key]);
        $this->assertStringNotContainsString('—', (string) $template->subject);
        $this->assertStringNotContainsString('“', (string) $template->body);

        $this->actingAs($this->owner)->getJson('/settings/email/templates')->assertOk()->assertJsonPath('templates.0.key', 'podsumowanie_miesiaca');
        $this->actingAs($this->owner)->get('/settings/messages')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('templates', 0));

        $this->actingAs($this->owner)->put('/settings/email/templates/podsumowanie_miesiaca', ['name' => 'Krótko', 'subject' => 'Za {period}', 'body' => 'Krótka notka.'])->assertSessionHasNoErrors();
        $this->assertSame(['Krótko', 'Za {period}', 'Krótka notka.'], [$template->fresh()->name, $template->fresh()->subject, $template->fresh()->body]);

        $this->actingAs($this->owner)->delete('/settings/email/templates/podsumowanie_miesiaca')->assertSessionHasNoErrors();
        $this->assertSame(0, MessageTemplate::query()->count());
    }

    public function test_an_unknown_placeholder_is_refused_in_the_subject_and_in_the_body(): void
    {
        $this->actingAs($this->owner)->post('/settings/email/templates', ['name' => 'A', 'subject' => 'Za {miesiac}', 'body' => 'Notka.'])->assertSessionHasErrors('body');
        $this->actingAs($this->owner)->post('/settings/email/templates', ['name' => 'B', 'subject' => 'Za {period}', 'body' => '{lista}'])->assertSessionHasErrors('body');
        $this->assertSame(0, MessageTemplate::query()->count());
    }

    public function test_the_month_placeholders_are_refused_in_a_text_message(): void
    {
        $this->actingAs($this->owner)->post('/settings/sms/templates', ['name' => 'SMS', 'body' => 'Za {period}'])->assertSessionHasErrors('body');
        $this->assertSame(0, MessageTemplate::query()->count());
    }

    public function test_a_key_belongs_to_one_channel_and_a_rule_takes_only_a_text_message_template(): void
    {
        $settings = app(MessageSettings::class);
        $settings->template($this->owner->account_id, 'monthly', 'Miesięczne', 'Notka.', MessageTemplate::EMAIL, 'Za {period}');

        $this->assertRefused(fn () => $settings->template($this->owner->account_id, 'monthly', 'SMS', 'Tekst'));
        $this->assertRefused(fn () => $settings->deleteTemplate($this->owner->account_id, 'monthly'));
        $this->assertRefused(fn () => $settings->rule($this->owner->account_id, 'r1', ['template' => 'monthly', 'trigger' => 'field_value', 'field' => 'x']));
        $this->assertRefused(fn () => $settings->template($this->owner->account_id, 'nosubject', 'Bez tematu', 'Notka.', MessageTemplate::EMAIL));
    }

    public function test_existing_templates_are_text_messages(): void
    {
        DB::table('message_templates')->insert(['account_id' => $this->owner->account_id, 'key' => 'old', 'name' => 'Old', 'body' => 'x', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(MessageTemplate::SMS, MessageTemplate::query()->sole()->channel);
    }

    public function test_the_preview_fills_the_month_from_a_real_client(): void
    {
        $preview = $this->actingAs($this->owner)->postJson('/settings/email/preview', [
            'subject' => 'Podsumowanie za {period}',
            'body' => "{client_name}\n\n{work_list}\n\n{hours_summary}",
            'client_id' => $this->client->id,
            'period' => '2026-10',
        ])->assertOk()->json();

        $this->assertSame('Podsumowanie za październik 2026', $preview['subject']);
        $this->assertStringContainsString('- Aktualizacja wtyczek', $preview['body']);
        $this->assertStringNotContainsString('Nowa podstrona', $preview['body'], 'an unfinished task is not in the work list');
        $this->assertStringNotContainsString('Wewnętrzne', $preview['body'], 'a non-reportable task is not in the work list');
        $this->assertStringContainsString('Bilans na start miesiąca: 0h', $preview['body']);
        $this->assertStringContainsString('Pula miesiąca: +5h', $preview['body']);
        $this->assertStringContainsString('Wykorzystano w miesiącu: −3h', $preview['body']);
        $this->assertStringContainsString('Bilans na start kolejnego miesiąca: +2h', $preview['body']);
        $this->assertStringNotContainsString('Podsumowanie rozliczeniowe', $preview['body'], 'the summary heading is left to the template');
    }

    public function test_exactly_one_email_template_is_the_default_and_it_goes_last(): void
    {
        $settings = app(MessageSettings::class);
        $accountId = $this->owner->account_id;
        $settings->template($accountId, 'first', 'Pierwszy', 'A', MessageTemplate::EMAIL, 'T');
        $settings->template($accountId, 'second', 'Drugi', 'B', MessageTemplate::EMAIL, 'T');
        $default = fn (): array => MessageTemplate::query()->where('is_default', true)->pluck('key')->all();
        $this->assertSame(['first'], $default(), 'the first template is the default');

        $this->actingAs($this->owner)->put('/settings/email/templates/second', ['name' => 'Drugi', 'subject' => 'T', 'body' => 'B', 'is_default' => true])->assertSessionHasNoErrors();
        $this->assertSame(['second'], $default());

        $this->actingAs($this->owner)->put('/settings/email/templates/second', ['name' => 'Drugi', 'subject' => 'T', 'body' => 'B', 'is_default' => false])->assertSessionHasErrors('is_default');
        $this->actingAs($this->owner)->delete('/settings/email/templates/second')->assertSessionHasErrors();
        $this->actingAs($this->owner)->delete('/settings/email/templates/first')->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->delete('/settings/email/templates/second')->assertSessionHasNoErrors();
        $this->assertSame(0, MessageTemplate::query()->count());
    }

    public function test_the_email_tab_shows_the_templates_and_how_mail_goes_out(): void
    {
        app(MessageSettings::class)->template($this->owner->account_id, 'monthly', 'Miesięczne', 'A', MessageTemplate::EMAIL, 'T');

        $this->actingAs($this->owner)->get('/settings/messages')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('email_templates.0.key', 'monthly')
                ->where('email_templates.0.is_default', true)
                ->where('email_sending.live', false)
                ->where('email_sending.from', null)
                ->where('email_sending.bcc', $this->owner->email));
    }

    public function test_the_email_tab_tells_a_refusing_server_from_a_test_one(): void
    {
        config(['mail.default' => 'smtp', 'mail.live_send' => false, 'mail.to.address' => null]);

        $this->actingAs($this->owner)->get('/settings/messages')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('email_sending.live', false)->where('email_sending.refused', true));

        config(['mail.default' => 'log']);

        $this->actingAs($this->owner)->get('/settings/messages')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('email_sending.refused', false));
    }

    public function test_the_preview_without_a_client_uses_the_newest_summary_email_client(): void
    {
        $this->actingAs($this->owner)->postJson('/settings/email/preview', ['subject' => '{client_name}', 'body' => '{period}', 'period' => '2026-10'])
            ->assertOk()->assertJsonPath('client', 'Brzozowy Sad')->assertJsonPath('subject', 'Brzozowy Sad');
    }

    public function test_the_preview_refuses_a_client_of_another_account_and_is_absent_without_billing(): void
    {
        $stranger = Client::factory()->create(['account_id' => Account::factory()->create()->id]);
        $this->actingAs($this->owner)->postJson('/settings/email/preview', ['subject' => 'x', 'body' => 'y', 'client_id' => $stranger->id])->assertUnprocessable();

        $account = $this->owner->account;
        $account->settings = [...(array) $account->settings, 'features' => ['billing' => false]];
        $account->save();
        $this->actingAs($this->owner)->getJson('/settings/email/templates')->assertNotFound();
        $this->actingAs($this->owner)->get('/settings/messages')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('templates')->missing('email_templates')->missing('email_sending'));
    }

    public function test_a_strict_render_stops_on_a_month_value_that_is_missing(): void
    {
        $bare = Client::factory()->create(['account_id' => $this->owner->account_id, 'name' => 'Bez abonamentu']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing {work_list}');
        app(MessageRenderer::class)->render('{work_list}', $bare, period: '2026-10');
    }

    public function test_the_hours_summary_of_a_client_without_a_retainer_is_empty_and_stops_nothing(): void
    {
        $bare = Client::factory()->create(['account_id' => $this->owner->account_id, 'name' => 'Bez abonamentu']);

        $this->assertSame("Godziny:\n\nKoniec", app(MessageRenderer::class)->render("Godziny:{hours_summary}\n\nKoniec", $bare, period: '2026-10'));
    }

    public function test_a_multi_line_placeholder_is_refused_in_a_subject_and_a_subject_is_one_line(): void
    {
        $this->actingAs($this->owner)->post('/settings/email/templates', ['name' => 'A', 'subject' => 'Prace: {work_list}', 'body' => 'x'])->assertSessionHasErrors('subject');
        $this->actingAs($this->owner)->post('/settings/email/templates', ['name' => 'B', 'subject' => 'Godziny {hours_summary}', 'body' => 'x'])->assertSessionHasErrors('subject');
        $this->actingAs($this->owner)->postJson('/settings/email/preview', ['subject' => '{work_list}', 'body' => 'x'])->assertUnprocessable();

        $this->actingAs($this->owner)->post('/settings/email/templates', ['name' => 'C', 'subject' => "Za\n  {period}\t!", 'body' => 'x'])->assertSessionHasNoErrors();
        $this->assertSame('Za {period} !', MessageTemplate::query()->sole()->subject);
    }

    private function assertRefused(callable $call): void
    {
        try {
            $call();
            $this->fail('expected a refusal');
        } catch (AgentRefusal) {
            $this->addToAssertionCount(1);
        }
    }
}
