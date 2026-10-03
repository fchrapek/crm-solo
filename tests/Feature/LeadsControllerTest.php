<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadStageEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP surface for the lead funnels. The model guards the invariants
 * (LeadsTest); these cover routing, validation and the convert-to-client seam.
 */
final class LeadsControllerTest extends TestCase
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

    public function test_index_defaults_to_the_mixed_board_and_never_offers_visitor_as_a_lane(): void
    {
        $this->actingAs($this->user)
            ->get('/leads')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('leads/index')
                // One board for everything: the stage vocabulary is unified
                // across pipelines, so mixed lanes are coherent by default.
                ->where('filters.pipeline', 'all')
                // Lanes come from rowStages(): no 'visitor' column to drop onto.
                ->where('stages', ['new', 'conversation', 'offer', 'won'])
                ->has('stage_labels')
                ->where('pipelines.0.stages', ['new', 'conversation', 'offer', 'won'])
                ->where('pipelines.0.won_stage', 'won')
                ->where('pipelines.1.won_stage', 'won')
            );
    }

    public function test_index_filters_by_pipeline_source_and_stage(): void
    {
        $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form', 'name' => 'Form lead']);
        $this->lead(['pipeline' => 'kiwwwi', 'source' => 'ads', 'name' => 'Ads lead']);
        $this->lead(['pipeline' => 'filipchrapek', 'source' => 'outbound', 'name' => 'Outbound lead']);

        $this->actingAs($this->user)
            ->get('/leads?pipeline=kiwwwi&source=www-form')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('leads.data', 1)
                ->where('leads.data.0.name', 'Form lead')
            );

        // The other funnel's leads never bleed into this board.
        $this->actingAs($this->user)
            ->get('/leads?pipeline=filipchrapek')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('leads.data', 1)
                ->where('leads.data.0.name', 'Outbound lead')
            );
    }

    public function test_index_search_matches_name_company_and_email(): void
    {
        $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form', 'name' => 'Ada', 'company' => 'Nowak sp. z o.o.']);
        $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form', 'name' => 'Bob', 'email' => 'bob@corp.test']);

        $this->actingAs($this->user)
            ->get('/leads?search=Nowak')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('leads.data', 1)->where('leads.data.0.name', 'Ada'));

        $this->actingAs($this->user)
            ->get('/leads?search=bob@corp')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('leads.data', 1)->where('leads.data.0.name', 'Bob'));
    }

    public function test_store_captures_at_the_entry_stage_for_the_source(): void
    {
        $this->actingAs($this->user)
            ->post('/leads', [
                'pipeline' => 'filipchrapek',
                'source' => 'social',
                'name' => 'Inbound DM',
            ])
            ->assertRedirect();

        $lead = Lead::where('name', 'Inbound DM')->sole();
        $this->assertSame('conversation', $lead->stage, 'social DMs enter at conversation');
        $this->assertNull(LeadStageEvent::where('lead_id', $lead->id)->sole()->from_stage);
    }

    public function test_store_rejects_an_unlisted_source_and_a_visitor_stage(): void
    {
        $this->actingAs($this->user)
            ->post('/leads', ['pipeline' => 'kiwwwi', 'source' => 'tiktok-dance', 'name' => 'X'])
            ->assertSessionHasErrors('source');

        // 'visitor' is absent from rowStages(), so validation rejects it before
        // the model ever has to.
        $this->actingAs($this->user)
            ->post('/leads', ['pipeline' => 'kiwwwi', 'source' => 'www-form', 'name' => 'X', 'stage' => 'visitor'])
            ->assertSessionHasErrors('stage');

        $this->assertSame(0, Lead::count());
    }

    public function test_update_rejects_source_and_pipeline_edits(): void
    {
        $lead = $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form']);

        $this->actingAs($this->user)
            ->put("/leads/{$lead->id}", ['name' => 'Renamed', 'source' => 'ads'])
            ->assertSessionHasErrors('source');

        $this->actingAs($this->user)
            ->put("/leads/{$lead->id}", ['name' => 'Renamed', 'pipeline' => 'filipchrapek'])
            ->assertSessionHasErrors('pipeline');

        $this->assertSame('www-form', $lead->fresh()->source);
    }

    public function test_update_records_a_stage_change_as_an_event(): void
    {
        $lead = $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form']);

        $this->actingAs($this->user)
            ->put("/leads/{$lead->id}", ['name' => 'Test Lead', 'stage' => 'offer'])
            ->assertRedirect();

        $this->assertSame('offer', $lead->fresh()->stage);
        // Capture + the hop: a silent update() would leave the rate maths blind.
        $this->assertSame(2, LeadStageEvent::where('lead_id', $lead->id)->count());
        $this->assertSame('new', LeadStageEvent::where('lead_id', $lead->id)->orderByDesc('id')->first()->from_stage);
    }

    public function test_stage_endpoint_moves_the_card_and_rejects_stages_outside_the_vocabulary(): void
    {
        $lead = $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form']);

        // Must be JSON, never a redirect. The board moves cards with fetch(),
        // which follows a 302 with the SAME method — a redirect here would come
        // back 405 and the board would roll the card back even though the move
        // committed. Asserting the body (not just a 2xx) pins that contract.
        $this->actingAs($this->user)
            ->patch("/leads/{$lead->id}/stage", ['stage' => 'conversation'])
            ->assertOk()
            ->assertJson(['stage' => 'conversation', 'is_won' => false]);
        $this->assertSame('conversation', $lead->fresh()->stage);

        $this->actingAs($this->user)
            ->patch("/leads/{$lead->id}/stage", ['stage' => 'won'])
            ->assertOk()
            ->assertJson(['stage' => 'won', 'is_won' => true]);

        // 'trial' is retired vocabulary; 'visitor' belongs to analytics.
        $this->actingAs($this->user)
            ->patchJson("/leads/{$lead->id}/stage", ['stage' => 'trial'])
            ->assertStatus(422);
        $this->actingAs($this->user)
            ->patchJson("/leads/{$lead->id}/stage", ['stage' => 'visitor'])
            ->assertStatus(422);

        $this->assertSame('won', $lead->fresh()->stage, 'a rejected move must not shift the card');
    }

    public function test_convert_creates_a_client_and_keeps_the_attribution_link(): void
    {
        $lead = $this->lead([
            'pipeline' => 'kiwwwi',
            'source' => 'referral',
            'name' => 'Jan Kowalski',
            'company' => 'Kowalski sp. z o.o.',
            'email' => 'jan@kowalski.test',
        ]);
        $lead->transitionTo('won', null, $this->user);

        $this->actingAs($this->user)
            ->post("/leads/{$lead->id}/convert")
            ->assertRedirect();

        $client = Client::where('name', 'Kowalski sp. z o.o.')->sole();
        $this->assertSame('jan@kowalski.test', $client->email);
        // The link is what makes "which channel produced this client" answerable.
        $this->assertSame($client->id, $lead->fresh()->client_id);
        $this->assertSame('referral', $lead->fresh()->source);
        $this->assertSame(['General'], $client->projects()->pluck('name')->all(), 'a converted client can take time straight away');
    }

    public function test_convert_refuses_a_lead_that_has_not_been_won(): void
    {
        $lead = $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form']);

        $this->actingAs($this->user)
            ->post("/leads/{$lead->id}/convert")
            ->assertRedirect();

        $this->assertNull($lead->fresh()->client_id);
        $this->assertSame(0, Client::count());
    }

    public function test_convert_is_idempotent_and_does_not_create_a_second_client(): void
    {
        $lead = $this->lead(['pipeline' => 'filipchrapek', 'source' => 'outbound', 'company' => 'Agency']);
        $lead->transitionTo('won', null, $this->user);

        $this->actingAs($this->user)->post("/leads/{$lead->id}/convert")->assertRedirect();
        $this->actingAs($this->user)->post("/leads/{$lead->id}/convert")->assertRedirect();

        $this->assertSame(1, Client::count(), 'a second convert must not mint another client');
    }

    public function test_leads_are_account_scoped(): void
    {
        $otherAccount = Account::create(['name' => 'Other']);
        $foreign = $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form'], $otherAccount);

        $this->actingAs($this->user)->get("/leads/{$foreign->id}/edit")->assertNotFound();
        $this->actingAs($this->user)->patch("/leads/{$foreign->id}/stage", ['stage' => 'conversation'])->assertNotFound();

        $this->actingAs($this->user)
            ->get('/leads')
            ->assertInertia(fn ($page) => $page->has('leads.data', 0));
    }

    public function test_guests_cannot_reach_the_funnel(): void
    {
        $this->get('/leads')->assertRedirect('/login');
    }

    public function test_edit_exposes_the_stage_history_newest_first(): void
    {
        $lead = $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form']);
        $lead->transitionTo('conversation', 'Scored gold', $this->user);

        $this->actingAs($this->user)
            ->get("/leads/{$lead->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('leads/edit')
                ->where('lead.is_won', false)
                ->has('events', 2)
                ->where('events.0.to_stage', 'conversation')
                ->where('events.0.note', 'Scored gold')
                ->where('events.1.from_stage', null)
            );
    }

    public function test_scoring_can_be_saved_and_comes_back_derived(): void
    {
        $lead = $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form']);

        $this->actingAs($this->user)
            ->put("/leads/{$lead->id}", [
                'name' => 'Test Lead',
                'score_factors' => [
                    'fit' => ['budget-signal-5k', 'has-existing-site'],
                    'behaviour' => ['magnet-download'],
                ],
            ])
            ->assertRedirect();

        // The row stores slugs; the page is handed the derived numbers so it
        // never re-implements the plan's maths in TypeScript.
        $this->actingAs($this->user)
            ->get("/leads/{$lead->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('lead.score_total', 7)
                ->where('lead.tier', 'gold')
                ->where('lead.tier_routing', 'personal-reply')
                ->where('lead.score_factors.fit', ['budget-signal-5k', 'has-existing-site'])
            );
    }

    public function test_posting_a_foreign_scoring_factor_is_a_422_not_a_500(): void
    {
        $lead = $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form']);

        // kiwwwi has no TRIGGER category. The model would throw; the request
        // must catch it first so the user gets a validation error, not a crash.
        $this->actingAs($this->user)
            ->put("/leads/{$lead->id}", ['name' => 'Test Lead', 'score_factors' => ['trigger' => ['funding']]])
            ->assertSessionHasErrors('score_factors');

        $this->actingAs($this->user)
            ->put("/leads/{$lead->id}", ['name' => 'Test Lead', 'score_factors' => ['fit' => ['vibes-good']]])
            ->assertSessionHasErrors('score_factors.fit.0');

        $this->assertNull($lead->fresh()->score_factors);
    }

    public function test_index_exposes_the_scoring_map_and_can_filter_by_tier(): void
    {
        // Gold: 3 + 2 + 2 = 7.
        $this->lead([
            'pipeline' => 'kiwwwi', 'source' => 'www-form', 'name' => 'Gold lead',
            'score_factors' => ['fit' => ['budget-signal-5k', 'has-existing-site'], 'behaviour' => ['magnet-download']],
        ]);
        // Oak: 3 + 2 = 5.
        $this->lead([
            'pipeline' => 'kiwwwi', 'source' => 'ads', 'name' => 'Oak lead',
            'score_factors' => ['fit' => ['budget-signal-5k'], 'behaviour' => ['magnet-download']],
        ]);
        // Rowan: unscored.
        $this->lead(['pipeline' => 'kiwwwi', 'source' => 'referral', 'name' => 'Rowan lead']);

        $this->actingAs($this->user)
            ->get('/leads')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('leads.data', 3)
                // The board carries the scoring map so checkboxes render the
                // plan's own points rather than a frontend copy.
                ->where('pipelines.0.scoring.fit.budget-signal-5k', 3)
                ->where('pipelines.0.scoring.fit.template-floor-signal', -3)
                ->where('tiers', ['gold', 'oak', 'rowan'])
            );

        $this->actingAs($this->user)
            ->get('/leads?tier=gold')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('leads.data', 1)
                ->where('leads.data.0.name', 'Gold lead')
                ->where('leads.data.0.score_total', 7)
                ->where('leads.data.0.tier', 'gold')
            );

        $this->actingAs($this->user)
            ->get('/leads?tier=rowan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('leads.data', 1)
                ->where('leads.data.0.name', 'Rowan lead')
            );
    }

    public function test_tier_filter_composes_with_the_other_filters(): void
    {
        $this->lead([
            'pipeline' => 'kiwwwi', 'source' => 'www-form', 'name' => 'Gold form',
            'score_factors' => ['fit' => ['budget-signal-5k', 'has-existing-site'], 'behaviour' => ['magnet-download']],
        ]);
        $this->lead([
            'pipeline' => 'kiwwwi', 'source' => 'ads', 'name' => 'Gold ads',
            'score_factors' => ['fit' => ['budget-signal-5k', 'has-existing-site'], 'behaviour' => ['magnet-download']],
        ]);

        // Tier is resolved in PHP while source is a WHERE clause; the two must
        // still narrow together.
        $this->actingAs($this->user)
            ->get('/leads?tier=gold&source=www-form')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('leads.data', 1)
                ->where('leads.data.0.name', 'Gold form')
            );
    }

    public function test_an_unknown_tier_filter_falls_back_to_all(): void
    {
        $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form']);

        $this->actingAs($this->user)
            ->get('/leads?tier=platinum')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.tier', 'all')
                ->has('leads.data', 1)
            );
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
