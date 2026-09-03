<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * FIT / BEHAVIOUR / TRIGGER scoring and Gold-Oak-Rowan tiers.
 *
 * Only the ticked factor slugs are stored; points and tier are derived from
 * config('leadgen.*') on read. These tests pin that derivation, including the
 * plan's own worked example.
 */
final class LeadScoringTest extends TestCase
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

    public function test_an_unscored_lead_is_rowan_with_zero_points(): void
    {
        $lead = $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form']);

        $this->assertSame(0, $lead->scoreTotal());
        $this->assertSame(Lead::TIER_ROWAN, $lead->tier(), 'no signal means no Filip-minutes');
        $this->assertSame([], $lead->scoreFactors());
    }

    public function test_the_plans_worked_example_scores_gold(): void
    {
        // Plan: budget signal (+3) + existing site (+2) + magnet download (+2)
        // = 7 -> Gold.
        $lead = $this->lead([
            'pipeline' => 'kiwwwi',
            'source' => 'www-form',
            'score_factors' => [
                'fit' => ['budget-signal-5k', 'has-existing-site'],
                'behaviour' => ['magnet-download'],
            ],
        ]);

        $this->assertSame(7, $lead->scoreTotal());
        $this->assertSame(Lead::TIER_GOLD, $lead->tier());
        // Gold in kiwwwi earns the "Zlec projekt" promise: a personal reply.
        $this->assertSame('personal-reply', $lead->tierRouting());
    }

    public function test_the_template_floor_signal_subtracts(): void
    {
        // -3 is the plan's only negative factor: a lead actively asking for
        // the cheap template floor is worth less, not merely neutral.
        $this->assertSame(-3, Lead::factorPoints('kiwwwi', 'fit', 'template-floor-signal'));

        $lead = $this->lead([
            'pipeline' => 'kiwwwi',
            'source' => 'www-form',
            'score_factors' => [
                'fit' => ['budget-signal-5k', 'has-existing-site', 'template-floor-signal'],
                'behaviour' => ['magnet-download'],
            ],
        ]);

        // 3 + 2 - 3 + 2 = 4 -> drops out of Gold into Oak.
        $this->assertSame(4, $lead->scoreTotal());
        $this->assertSame(Lead::TIER_OAK, $lead->tier());
        $this->assertSame('nurture', $lead->tierRouting());
    }

    public function test_tier_boundaries_sit_exactly_where_the_plan_puts_them(): void
    {
        // Gold >= 7, Oak 4-6, Rowan < 4. The boundary values are the whole
        // point of a threshold, so pin them rather than a midpoint.
        $this->assertSame(7, config('leadgen.tiers.gold_min'));
        $this->assertSame(4, config('leadgen.tiers.oak_min'));

        // 3 + 3 = 6 -> Oak (one below Gold).
        $six = $this->lead([
            'pipeline' => 'filipchrapek',
            'source' => 'outbound',
            'score_factors' => ['fit' => ['agency-10-50-people', 'wp-woo-stack']],
        ]);
        $this->assertSame(6, $six->scoreTotal());
        $this->assertSame(Lead::TIER_OAK, $six->tier());

        // 3 + 3 + 2 = 8 -> Gold.
        $eight = $this->lead([
            'pipeline' => 'filipchrapek',
            'source' => 'outbound',
            'score_factors' => ['fit' => ['agency-10-50-people', 'wp-woo-stack', 'hires-senior-roles']],
        ]);
        $this->assertSame(8, $eight->scoreTotal());
        $this->assertSame(Lead::TIER_GOLD, $eight->tier());

        // 3 -> Rowan (one below Oak).
        $three = $this->lead([
            'pipeline' => 'filipchrapek',
            'source' => 'outbound',
            'score_factors' => ['fit' => ['agency-10-50-people']],
        ]);
        $this->assertSame(3, $three->scoreTotal());
        $this->assertSame(Lead::TIER_ROWAN, $three->tier());
        $this->assertSame('follow-list', $three->tierRouting());
    }

    public function test_outbound_triggers_count_toward_the_same_total(): void
    {
        $lead = $this->lead([
            'pipeline' => 'filipchrapek',
            'source' => 'outbound',
            'score_factors' => [
                'fit' => ['agency-10-50-people'],          // 3
                'trigger' => ['senior-dev-job-ad'],        // 3
                'behaviour' => ['case-study-visit'],       // 2
            ],
        ]);

        $this->assertSame(8, $lead->scoreTotal());
        $this->assertSame(Lead::TIER_GOLD, $lead->tier());
        // Gold outbound earns the value-first audit, not a plain TIVC message.
        $this->assertSame('value-first-audit', $lead->tierRouting());
    }

    public function test_a_factor_from_the_other_funnel_is_rejected(): void
    {
        // kiwwwi scores no TRIGGER category at all. Accepting it silently
        // would contribute 0 and make the score quietly wrong.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not part of the [kiwwwi] pipeline');

        $this->lead([
            'pipeline' => 'kiwwwi',
            'source' => 'www-form',
            'score_factors' => ['trigger' => ['senior-dev-job-ad']],
        ]);
    }

    public function test_an_unknown_factor_slug_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not part of the [kiwwwi] pipeline\'s [fit] map');

        $this->lead([
            'pipeline' => 'kiwwwi',
            'source' => 'www-form',
            'score_factors' => ['fit' => ['vibes-good']],
        ]);
    }

    public function test_scores_re_derive_when_the_plan_retunes_its_weights(): void
    {
        // The reason points are never stored: Month-2 re-runs the back-solve
        // and may re-tune weights. Yesterday's leads must re-score under
        // today's config without a backfill.
        $lead = $this->lead([
            'pipeline' => 'kiwwwi',
            'source' => 'www-form',
            'score_factors' => ['behaviour' => ['magnet-download']],
        ]);
        $this->assertSame(2, $lead->scoreTotal());
        $this->assertSame(Lead::TIER_ROWAN, $lead->tier());

        config(['leadgen.pipelines.kiwwwi.scoring.behaviour.magnet-download' => 9]);

        $this->assertSame(9, $lead->fresh()->scoreTotal(), 'the stored row re-scores under the new weight');
        $this->assertSame(Lead::TIER_GOLD, $lead->fresh()->tier());
    }

    public function test_a_factor_retired_from_config_stops_counting_instead_of_throwing(): void
    {
        $lead = $this->lead([
            'pipeline' => 'kiwwwi',
            'source' => 'www-form',
            'score_factors' => ['behaviour' => ['magnet-download', 'repeat-visit']],
        ]);
        $this->assertSame(3, $lead->scoreTotal());

        // The plan drops a factor; rows still mentioning it must degrade
        // quietly rather than break every read.
        config(['leadgen.pipelines.kiwwwi.scoring.behaviour' => ['magnet-download' => 2]]);

        $fresh = $lead->fresh();
        $this->assertSame(2, $fresh->scoreTotal());
        $this->assertSame(['behaviour' => ['magnet-download']], $fresh->scoreFactors());
    }

    public function test_score_factors_survive_a_round_trip_through_the_json_column(): void
    {
        $lead = $this->lead([
            'pipeline' => 'filipchrapek',
            'source' => 'outbound',
            'score_factors' => ['fit' => ['wp-woo-stack'], 'trigger' => ['funding']],
        ]);

        $fresh = $lead->fresh();
        $this->assertIsArray($fresh->score_factors, 'the array cast must be wired (CLAUDE.md pitfall)');
        $this->assertSame(['fit' => ['wp-woo-stack'], 'trigger' => ['funding']], $fresh->scoreFactors());
        $this->assertSame(5, $fresh->scoreTotal());
    }

    public function test_scoring_can_be_updated_and_cleared_on_an_existing_lead(): void
    {
        $lead = $this->lead([
            'pipeline' => 'kiwwwi',
            'source' => 'www-form',
            'score_factors' => ['fit' => ['budget-signal-5k']],
        ]);
        $this->assertSame(3, $lead->scoreTotal());

        $lead->update(['score_factors' => ['fit' => ['budget-signal-5k', 'has-existing-site']]]);
        $this->assertSame(5, $lead->fresh()->scoreTotal());

        $lead->update(['score_factors' => null]);
        $this->assertSame(0, $lead->fresh()->scoreTotal());
        $this->assertSame(Lead::TIER_ROWAN, $lead->fresh()->tier());
    }

    public function test_updating_with_a_foreign_factor_is_rejected(): void
    {
        $lead = $this->lead(['pipeline' => 'kiwwwi', 'source' => 'www-form']);

        $this->expectException(InvalidArgumentException::class);

        $lead->update(['score_factors' => ['trigger' => ['funding']]]);
    }

    /** @param array<string, mixed> $attributes */
    private function lead(array $attributes = []): Lead
    {
        return Lead::create(array_merge([
            'account_id' => $this->account->id,
            'name' => 'Test Lead',
        ], $attributes));
    }
}
