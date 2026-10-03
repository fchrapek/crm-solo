<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Services\AI\AIProviderInterface;
use App\Services\Reports\BillingSummary;
use App\Services\Reports\Composers\AiNarrativeComposer;
use App\Services\Reports\Composers\StructuredListComposer;
use App\Services\Reports\NarrativePromptResolver;
use App\Services\Reports\ReportContext;
use App\Services\Reports\ReportDataAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The billing summary is code, never model prose: an AI draft ends with the
 * same four lines, the same figures and the same wording as the structured
 * composer, whatever the model wrote about balances.
 */
final class ReportBillingSummaryEnforcedTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Acme']);
        $this->client = $this->account->clients()->create(['name' => 'Acme', 'currency' => 'PLN']);
        $this->client->retainers()->create([
            'account_id' => $this->account->id,
            'monthly_hours' => 25,
            'rollover_cap_hours' => 24,
            'currency' => 'PLN',
            'effective_from' => '2026-03-01',
        ]);
    }

    public function test_a_model_written_summary_is_replaced_by_the_deterministic_one(): void
    {
        // The model invents a forfeit line and a hyphen-signed balance.
        $model = "# Marzec 2026\n\n## Prace rozwojowe\n\n- Praca nad chatbotem.\n\n## Podsumowanie rozliczeniowe\n\n"
            ."Bilans na start marca: -18h\n\nPula marca: +25h\n\nWykorzystano w marcu: −20h\n\n"
            ."Aktualny bilans: -13h\n\n6h przekroczyło uzgodniony limit i nie zostało przeniesione.\n";

        $body = $this->composer($model)->compose($this->context(-18.0, 'pl'));

        $this->assertSame(1, mb_substr_count($body, 'Podsumowanie rozliczeniowe'));
        $this->assertStringNotContainsString('przekroczyło', $body);
        $this->assertStringNotContainsString('Bilans na start marca', $body);
        $this->assertStringContainsString('- Praca nad chatbotem.', $body);
        $this->assertStringEndsWith(
            "## Podsumowanie rozliczeniowe\n\nBilans na start miesiąca: \u{2212}18h\n\nPula miesiąca: +25h\n\n"
            ."Wykorzystano w miesiącu: 0h\n\nBilans na start kolejnego miesiąca: +7h\n",
            $body,
        );
    }

    public function test_a_summary_is_appended_when_the_model_wrote_none(): void
    {
        $body = $this->composer("# Marzec 2026\n\n## Prace rozwojowe\n\n- Praca.\n")->compose($this->context(0.0, 'pl'));

        $this->assertStringContainsString("## Prace rozwojowe\n\n- Praca.\n\n## Podsumowanie rozliczeniowe", $body);
        $this->assertStringContainsString('Bilans na start miesiąca: 0h', $body);
        $this->assertStringContainsString('Bilans na start kolejnego miesiąca: +24h', $body);
    }

    public function test_the_forfeit_line_appears_only_when_the_cap_really_clipped_hours(): void
    {
        // 10h rolled in + 25h pool = 35h, cap 24h: 11h forfeited.
        $body = $this->composer("# March 2026\n")->compose($this->context(10.0, 'en'));

        $this->assertStringContainsString('Hours above the agreed carry-over cap, not carried forward: 11h', $body);

        $none = $this->composer("# March 2026\n")->compose($this->context(-5.0, 'en'));
        $this->assertStringNotContainsString('carry-over cap', $none);
    }

    public function test_ai_and_structured_summaries_are_identical(): void
    {
        $context = $this->context(-9.5, 'pl');
        $ai = $this->composer("# Marzec 2026\n\n## Podsumowanie rozliczeniowe\n\nCokolwiek.\n")->compose($context);
        $structured = app(StructuredListComposer::class)->compose($context);

        $tail = fn (string $b): string => mb_substr($b, (int) mb_strpos($b, '## Podsumowanie rozliczeniowe'));
        $this->assertSame($tail($structured), $tail($ai));
    }

    public function test_strip_ignores_a_summary_heading_inside_code(): void
    {
        $body = "# X\n\n```md\n## Billing summary\n```\n";

        $this->assertSame($body, BillingSummary::strip($body));
    }

    private function context(float $opening, string $locale): ReportContext
    {
        app()->setLocale($locale);

        return app(ReportDataAggregator::class)->aggregate(
            $this->client,
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-03-31'),
            'month',
            $locale,
            $opening,
        );
    }

    private function composer(string $modelOutput): AiNarrativeComposer
    {
        $provider = new class($modelOutput) implements AIProviderInterface
        {
            public function __construct(private readonly string $out) {}

            public function chat(string $systemPrompt, array $messages, array $options = []): string
            {
                return $this->out;
            }

            public function chatWithTools(string $systemPrompt, array $messages, array $tools, array $options = []): array
            {
                return [];
            }

            public function getProviderName(): string
            {
                return 'fake';
            }
        };

        $prompts = new class implements NarrativePromptResolver
        {
            public function resolve(int $accountId): string
            {
                return 'prompt';
            }

            public function source(int $accountId): ?string
            {
                return 'file';
            }
        };

        return new AiNarrativeComposer($provider, app(StructuredListComposer::class), $prompts);
    }
}
