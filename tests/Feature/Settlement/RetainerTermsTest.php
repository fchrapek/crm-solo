<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Models\ClientRetainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class RetainerTermsTest extends TestCase
{
    use RefreshDatabase;
    use SettlementFixtures;

    private ClientRetainer $position;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSettlement(nowUtc: '2026-11-20 10:00:00');
        $this->position = $this->retainer(10, '2026-01-01');
    }

    public function test_changing_the_hours_of_a_position_an_elapsed_month_owes_on_is_refused(): void
    {
        $this->update(['monthly_hours' => 12])
            ->assertSessionHas('error', 'September 2026 is closed or being settled; change the retainer from next month on.');

        $this->assertSame(10.0, (float) $this->position->fresh()->monthly_hours);
    }

    public function test_a_closed_month_protects_its_terms_too(): void
    {
        $this->closure('2026-09', 0, 10, 4);
        $this->closure('2026-10', 6, 10, 4);

        $this->update(['monthly_hours' => 10, 'rollover_cap_hours' => 15])
            ->assertSessionHas('error', 'September 2026 is closed or being settled; change the retainer from next month on.');
        $this->assertNull($this->position->fresh()->rollover_cap_hours);
    }

    public function test_deactivating_or_deleting_a_position_elapsed_months_depend_on_is_refused(): void
    {
        $this->update(['monthly_hours' => 10, 'is_active' => false])->assertSessionHas('error');
        $this->actingAs($this->user)->from('/x')->delete($this->url())->assertRedirect('/x')->assertSessionHas('error');

        $this->assertTrue($this->position->fresh()->is_active);
    }

    public function test_edits_that_leave_the_terms_alone_are_saved(): void
    {
        $this->update(['monthly_hours' => 10, 'label' => 'Opieka', 'monthly_fee' => 1500])->assertSessionHas('success', 'Position updated.');

        $this->assertSame('Opieka', $this->position->fresh()->label);
    }

    public function test_a_position_ends_from_the_current_month_on_and_the_backlog_stays(): void
    {
        $this->update(['monthly_hours' => 10, 'effective_to' => '2026-10-01'])->assertSessionHasErrors('effective_to');
        $this->assertNull($this->position->fresh()->effective_to);

        $this->update(['monthly_hours' => 10, 'effective_to' => '2026-11-01'])->assertSessionHas('success');

        $this->assertSame('2026-11-01', $this->position->fresh()->effective_to->toDateString());
        $this->assertSame(['2026-09', '2026-10'], app(\App\Services\Clients\HoursBank::class)->settlement($this->client)->eligible);
    }

    public function test_a_future_end_date_is_cleared_to_renew_the_position(): void
    {
        $this->position->update(['effective_to' => '2026-12-01']);

        $this->update(['monthly_hours' => 10, 'effective_to' => null])->assertSessionHas('success', 'Position updated.');

        $this->assertNull($this->position->fresh()->effective_to);
    }

    public function test_an_end_date_already_past_is_not_cleared(): void
    {
        $this->position->update(['effective_to' => '2026-10-01']);

        $this->update(['monthly_hours' => 10, 'effective_to' => null])->assertSessionHasErrors('effective_to');

        $this->assertSame('2026-10-01', $this->position->fresh()->effective_to->toDateString());
    }

    public function test_an_end_date_is_after_the_start(): void
    {
        $late = $this->retainer(3, '2027-02-01');

        $this->actingAs($this->user)->from('/x')
            ->put("/clients/{$this->client->id}/retainers/{$late->id}", ['monthly_hours' => 3, 'effective_to' => '2027-01-01'])
            ->assertSessionHasErrors('effective_to');
    }

    public function test_a_new_position_backdated_into_an_elapsed_month_is_refused_but_one_from_this_month_is_saved(): void
    {
        $this->store(['monthly_hours' => 20, 'effective_from' => '2026-09-01'])->assertSessionHas('error');
        $this->assertSame(1, ClientRetainer::query()->count());

        $this->store(['monthly_hours' => 20, 'effective_from' => '2026-11-01'])->assertSessionHas('success', 'Position saved.');
        $this->assertSame(2, ClientRetainer::query()->count());
    }

    public function test_a_position_backdated_before_a_closed_month_is_refused(): void
    {
        $this->position->update(['effective_from' => '2026-10-01']);
        $this->closure('2026-10', 0, 10, 4);

        $this->store(['monthly_hours' => 5, 'effective_from' => '2026-09-01'])
            ->assertSessionHas('error', 'September 2026 is closed or being settled; change the retainer from next month on.');

        $this->assertSame(1, ClientRetainer::query()->count());
        $this->assertSame([], array_values(array_diff(['2026-10'], app(\App\Services\Clients\HoursBank::class)->settlement($this->client)->eligible)));
    }

    public function test_backdating_into_an_open_backlog_with_no_closure_after_it_is_saved(): void
    {
        $this->position->update(['effective_from' => '2026-10-01']);

        $this->store(['monthly_hours' => 5, 'effective_from' => '2026-09-01'])->assertSessionHas('success', 'Position saved.');

        $this->assertSame(['2026-09', '2026-10'], app(\App\Services\Clients\HoursBank::class)->settlement($this->client)->eligible);
    }

    public function test_a_position_that_only_ran_before_the_baseline_can_go(): void
    {
        $old = $this->retainer(40, '2025-01-01', to: '2026-08-01');

        $this->actingAs($this->user)->from('/x')->delete("/clients/{$this->client->id}/retainers/{$old->id}")->assertSessionHas('success');

        $this->assertNull($old->fresh());
    }

    private function url(): string
    {
        return "/clients/{$this->client->id}/retainers/{$this->position->id}";
    }

    /** @param  array<string, mixed>  $data */
    private function update(array $data): TestResponse
    {
        return $this->actingAs($this->user)->from('/x')->put($this->url(), $data)->assertRedirect('/x');
    }

    /** @param  array<string, mixed>  $data */
    private function store(array $data): TestResponse
    {
        return $this->actingAs($this->user)->from('/x')->post("/clients/{$this->client->id}/retainers", $data)->assertRedirect('/x');
    }
}
