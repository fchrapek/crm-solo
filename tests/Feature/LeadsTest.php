<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadStageEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * The Lead entity and its append-only stage history — the measurement surface
 * the Month-2 checkpoint reads to replace assumed conversion rates with real
 * ones. Rules come from config/leadgen.php, never from hardcoded enums.
 */
final class LeadsTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Acc']);
        $this->user = User::factory()->create([
            'account_id' => $this->account->id,
            'first_name' => 'T',
            'last_name' => 'U',
            'email' => 'u@example.com',
            'owner' => true,
        ]);
    }

    public function test_capture_writes_a_creation_event_not_a_transition(): void
    {
        $lead = $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form']);

        // The lead was CREATED at its entry stage, not moved to it. from_stage
        // must be null: inventing a previous stage here would forge a
        // conversion the funnel never made and bias the first measured rate.
        $events = LeadStageEvent::where('lead_id', $lead->id)->get();
        $this->assertCount(1, $events, 'capture writes exactly one event');
        $this->assertNull($events->first()->from_stage,
            'capture event must be null -> entry_stage, never a synthetic hop');
        $this->assertSame('new', $events->first()->to_stage);
        $this->assertSame($this->account->id, $events->first()->account_id);
    }

    public function test_inbound_leads_enter_at_new_because_visitor_is_analytics_only(): void
    {
        // kiwwwi's first stage is 'visitor', but entry is the declared
        // entry_stage. A visitor is anonymous — there is no row to store until
        // they submit a form, so visitor -> lead is a web-analytics boundary.
        $this->assertSame('new', Lead::entryStage('kiwwwi', 'www-form'));
        $this->assertSame('new', $this->lead(['pipeline' => 'kiwwwi', 'source' => 'ads'])->stage);
    }

    public function test_outbound_leads_enter_at_new(): void
    {
        $this->assertSame('new', Lead::entryStage('filipchrapek', 'outbound'));
        $this->assertSame('new', $this->lead([
            'pipeline' => 'filipchrapek',
            'source' => 'outbound',
        ])->stage);
    }

    public function test_social_dms_skip_the_top_of_the_funnel(): void
    {
        // Plan: "LinkedIn inbound DMs enter at conversation directly."
        $this->assertSame('conversation', Lead::entryStage('filipchrapek', 'social'));

        $lead = $this->lead(['pipeline' => 'filipchrapek', 'source' => 'social']);

        $this->assertSame('conversation', $lead->stage);
        $event = LeadStageEvent::where('lead_id', $lead->id)->sole();
        $this->assertNull($event->from_stage);
        $this->assertSame('conversation', $event->to_stage,
            'the override is the capture stage — no walk through new');
    }

    public function test_a_lead_can_never_hold_the_analytics_only_visitor_stage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('funnel arithmetic only');

        $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form', 'stage' => 'visitor']);
    }

    public function test_an_existing_lead_cannot_be_moved_back_to_visitor(): void
    {
        $lead = $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('funnel arithmetic only');

        $lead->transitionTo('visitor');
    }

    public function test_row_stages_exclude_visitor_but_keep_the_rest_in_plan_order(): void
    {
        $this->assertSame(['new', 'conversation', 'offer', 'won'], Lead::rowStages('kiwwwi'));
        $this->assertSame(['new', 'conversation', 'offer', 'won'], Lead::rowStages('filipchrapek'));
        // The one-board vocabulary: the ordered union — identical to each
        // pipeline's row stages by design since the 2026-07-28 unification.
        $this->assertSame(['new', 'conversation', 'offer', 'won'], Lead::unifiedRowStages());
    }

    public function test_transition_records_the_hop_and_moves_the_lead(): void
    {
        $lead = $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form']);

        $event = $lead->transitionTo('conversation', 'Budget confirmed 8k', $this->user);

        $this->assertSame('conversation', $lead->fresh()->stage);
        $this->assertSame('new', $event->from_stage);
        $this->assertSame('conversation', $event->to_stage);
        $this->assertSame('Budget confirmed 8k', $event->note);
        $this->assertSame($this->user->id, $event->user_id);
        // Capture event + this hop — the history is append-only.
        $this->assertSame(2, LeadStageEvent::where('lead_id', $lead->id)->count());
    }

    public function test_same_stage_with_a_note_is_a_timeline_entry_and_without_one_is_a_no_op(): void
    {
        $lead = $this->lead(['pipeline' => 'filipchrapek', 'source' => 'outbound']);

        $this->assertNull($lead->transitionTo('new'),
            'a same-stage move with no note records nothing');
        $this->assertSame(1, LeadStageEvent::where('lead_id', $lead->id)->count());

        $noted = $lead->transitionTo('new', 'Left a voicemail');
        $this->assertNotNull($noted);
        $this->assertSame('new', $noted->from_stage);
        $this->assertSame('new', $noted->to_stage);
    }

    public function test_a_stage_outside_the_pipelines_vocabulary_is_rejected(): void
    {
        $lead = $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a stage of the [kiwwwi] pipeline');

        // 'trial' is retired vocabulary — stages come from config, not history.
        $lead->transitionTo('trial');
    }

    public function test_source_is_immutable_after_capture(): void
    {
        // "No source tag -> the channel doesn't exist." An editable tag would
        // silently rewrite a channel's measured history.
        $lead = $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('set at capture');

        $lead->update(['source' => 'ads']);
    }

    public function test_pipeline_is_immutable_after_capture(): void
    {
        $lead = $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pipeline cannot be changed');

        $lead->update(['pipeline' => 'filipchrapek']);
    }

    public function test_unknown_source_and_pipeline_are_rejected_at_capture(): void
    {
        try {
            $this->lead(['pipeline' => 'kiwwwi', 'source' => 'tiktok-dance']);
            $this->fail('an unlisted source must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Unknown lead source', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown lead pipeline');
        $this->lead(['pipeline' => 'some-other-brand', 'source' => 'www-form']);
    }

    public function test_editable_fields_still_save(): void
    {
        $lead = $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form']);

        $lead->update(['name' => 'Renamed', 'notes' => 'Called back']);

        $this->assertSame('Renamed', $lead->fresh()->name);
        $this->assertSame('Called back', $lead->fresh()->notes);
    }

    public function test_a_won_lead_links_to_the_client_it_became(): void
    {
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'Acme']);
        $lead = $this->lead(['pipeline' => 'kiwwwi', 'source' => 'referral']);

        $lead->transitionTo('won', null, $this->user);
        $lead->update(['client_id' => $client->id]);

        $this->assertSame('won', $lead->fresh()->stage);
        $this->assertSame($client->id, $lead->fresh()->client->id);
    }

    public function test_route_binding_is_account_scoped(): void
    {
        $otherAccount = Account::create(['name' => 'Other']);
        $foreign = $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form'], $otherAccount);

        $this->actingAs($this->user);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        (new Lead)->resolveRouteBinding($foreign->id);
    }

    public function test_capture_stamps_captured_at_and_history_cascades_on_delete(): void
    {
        $lead = $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form']);
        $this->assertNotNull($lead->captured_at);

        // Soft delete keeps the trail; a hard delete takes it with the lead.
        $lead->delete();
        $this->assertSame(1, LeadStageEvent::where('lead_id', $lead->id)->count(),
            'soft delete preserves the measured history');

        $lead->forceDelete();
        $this->assertSame(0, LeadStageEvent::where('lead_id', $lead->id)->count());
    }

    /** @param array<string, mixed> $attributes */
    private function lead(array $attributes = [], ?Account $account = null): Lead
    {
        return Lead::create(array_merge([
            'account_id' => ($account ?? $this->account)->id,
            'name' => 'Test Lead',
        ], $attributes));
    }
}
