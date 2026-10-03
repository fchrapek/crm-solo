<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Setting;
use App\Services\AI\AIProviderInterface;
use App\Services\Reports\Composers\AiNarrativeComposer;
use App\Services\Reports\Composers\StructuredListComposer;
use App\Services\Reports\NarrativePromptResolver;
use App\Services\Reports\NarrativePromptUnavailable;
use App\Services\Reports\ReportDataAggregator;
use App\Services\Reports\SettingsNarrativePromptResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use LogicException;
use Tests\TestCase;

/**
 * The prompt seam: wording lives in the account's settings row when it has
 * one and in resources/prompts otherwise, so an instance can carry its own
 * without a code change and a clone still works out of the box.
 */
final class ReportNarrativePromptTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Acme']);
        $this->client = $this->account->clients()->create([
            'name' => 'Acme',
            'currency' => 'PLN',
        ]);
    }

    public function test_the_shipped_prompt_resolves_when_no_override_is_stored(): void
    {
        $resolver = new SettingsNarrativePromptResolver;

        $this->assertSame('file', $resolver->source($this->account->id));

        $prompt = $resolver->resolve($this->account->id);

        // The shape contract has to ship publicly, or a clone produces
        // differently structured reports from the same code.
        $this->assertStringContainsString('Prace rozwojowe', $prompt);
        $this->assertStringContainsString('Development work', $prompt);
        $this->assertStringContainsString('Podsumowanie rozliczeniowe', $prompt);
        $this->assertStringContainsString('report_baseline_markdown', $prompt);
    }

    public function test_the_shipped_prompt_carries_no_worked_example(): void
    {
        $prompt = (new SettingsNarrativePromptResolver)->resolve($this->account->id);

        // The few-shot example and the hours-bank rationale are the parts
        // that stay on the instance; the contract is what ships. A bare
        // format hint like `# Marzec 2026` is contract, so it stays.
        $this->assertStringNotContainsString('Example shape', $prompt);
        $this->assertStringNotContainsString('heatmapy', $prompt);
        $this->assertStringNotContainsString('landing page', $prompt);
        $this->assertStringNotContainsString('Bilans na start marca', $prompt);
        $this->assertStringNotContainsString('Pula marca', $prompt);
    }

    public function test_a_stored_override_wins_over_the_shipped_prompt(): void
    {
        Setting::create([
            'account_id' => $this->account->id,
            'scope' => SettingsNarrativePromptResolver::SETTING_SCOPE,
            'data' => [SettingsNarrativePromptResolver::SETTING_KEY => 'Instance wording.'],
        ]);

        $resolver = new SettingsNarrativePromptResolver;

        $this->assertSame('settings', $resolver->source($this->account->id));
        $this->assertSame('Instance wording.', $resolver->resolve($this->account->id));
    }

    public function test_a_blank_override_falls_through_to_the_shipped_prompt(): void
    {
        Setting::create([
            'account_id' => $this->account->id,
            'scope' => SettingsNarrativePromptResolver::SETTING_SCOPE,
            'data' => [SettingsNarrativePromptResolver::SETTING_KEY => "   \n "],
        ]);

        $this->assertSame('file', (new SettingsNarrativePromptResolver)->source($this->account->id));
    }

    public function test_an_override_is_scoped_to_its_own_account(): void
    {
        $other = Account::create(['name' => 'Other']);

        Setting::create([
            'account_id' => $other->id,
            'scope' => SettingsNarrativePromptResolver::SETTING_SCOPE,
            'data' => [SettingsNarrativePromptResolver::SETTING_KEY => 'Not yours.'],
        ]);

        $this->assertSame('file', (new SettingsNarrativePromptResolver)->source($this->account->id));
    }

    public function test_an_unresolvable_prompt_degrades_to_the_structured_composer_and_logs(): void
    {
        Log::spy();
        $composer = new AiNarrativeComposer(
            $this->neverCalledProvider(),
            $this->app->make(StructuredListComposer::class),
            $this->failingResolver(),
        );

        $body = $composer->compose($this->context());

        $this->assertSame($this->app->make(StructuredListComposer::class)->compose($this->context()), $body);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_a_missing_shipped_prompt_still_generates_a_report(): void
    {
        $this->app->bind(NarrativePromptResolver::class, fn () => $this->failingResolver());
        $this->app->bind(AIProviderInterface::class, fn () => $this->neverCalledProvider());
        $user = \App\Models\User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);

        $this->actingAs($user)
            ->post("/clients/{$this->client->id}/reports", [
                'period_type' => 'month',
                'period_start' => '2026-03-01',
                'period_end' => '2026-03-31',
                'composer_key' => 'ai_narrative',
            ])
            ->assertRedirect();

        $this->assertSame('ai_narrative', $this->client->reports()->sole()->composer_key);
    }

    public function test_the_import_command_round_trips_a_prompt_and_clears_it(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'prompt').'.md';
        file_put_contents($path, 'Imported wording.');

        $this->artisan('reports:prompt-import', ['path' => $path])->assertSuccessful();
        $this->assertSame('Imported wording.', (new SettingsNarrativePromptResolver)->resolve($this->account->id));

        $this->artisan('reports:prompt-show')->assertSuccessful();

        $this->artisan('reports:prompt-import', ['path' => $path, '--clear' => true])->assertSuccessful();
        $this->assertSame('file', (new SettingsNarrativePromptResolver)->source($this->account->id));

        @unlink($path);
    }

    public function test_the_import_command_rejects_a_missing_file(): void
    {
        $this->artisan('reports:prompt-import', ['path' => '/nope/missing.md'])->assertFailed();
    }

    private function context(): \App\Services\Reports\ReportContext
    {
        return app(ReportDataAggregator::class)->aggregate(
            $this->client,
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-03-31'),
            'month',
        );
    }

    private function failingResolver(): NarrativePromptResolver
    {
        return new class implements NarrativePromptResolver
        {
            public function resolve(int $accountId): string
            {
                throw NarrativePromptUnavailable::forPath('/gone/report-narrative.md');
            }

            public function source(int $accountId): ?string
            {
                return null;
            }
        };
    }

    private function neverCalledProvider(): AIProviderInterface
    {
        return new class implements AIProviderInterface
        {
            public function chat(string $systemPrompt, array $messages, array $options = []): string
            {
                throw new LogicException('the provider must not be reached without a prompt');
            }

            public function chatWithTools(string $systemPrompt, array $messages, array $tools, array $options = []): array
            {
                throw new LogicException('the provider must not be reached without a prompt');
            }

            public function getProviderName(): string
            {
                return 'never';
            }
        };
    }
}
