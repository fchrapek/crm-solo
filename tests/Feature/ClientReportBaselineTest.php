<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClientReportBaselineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $account = Account::create(['name' => 'Acme']);
        $this->user = User::factory()->create([
            'account_id' => $account->id,
            'owner' => true,
        ]);
        $this->client = $account->clients()->create([
            'name' => 'Acme',
            'currency' => 'PLN',
        ]);
    }

    public function test_update_baseline_stores_markdown(): void
    {
        $this->actingAs($this->user)
            ->put("/clients/{$this->client->id}/report-baseline", [
                'report_baseline_markdown' => "### W ramach abonamentu\n\n- Monitoring\n- Aktualizacje",
            ])
            ->assertRedirect();

        $this->assertSame(
            "### W ramach abonamentu\n\n- Monitoring\n- Aktualizacje",
            $this->client->fresh()->report_baseline_markdown,
        );
    }

    public function test_update_baseline_with_blank_clears_the_field(): void
    {
        $this->client->update(['report_baseline_markdown' => 'existing']);

        $this->actingAs($this->user)
            ->put("/clients/{$this->client->id}/report-baseline", ['report_baseline_markdown' => '   '])
            ->assertRedirect();

        $this->assertNull($this->client->fresh()->report_baseline_markdown);
    }

    public function test_baseline_keeps_its_list_structure_through_the_punctuation_gate(): void
    {
        $this->actingAs($this->user)
            ->put("/clients/{$this->client->id}/report-baseline", [
                'report_baseline_markdown' => "### Monitoring\n\n\u{2013} Kopie\n  \u{2013} codziennie  \n  \u{2013} 30 dni",
            ])
            ->assertRedirect();

        $this->assertSame(
            "### Monitoring\n\n- Kopie\n  - codziennie  \n  - 30 dni",
            $this->client->fresh()->report_baseline_markdown,
        );
    }
}
