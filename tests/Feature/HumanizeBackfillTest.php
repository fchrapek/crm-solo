<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HumanizeBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_leaves_clean_markdown_byte_for_byte(): void
    {
        $client = Account::create(['name' => 'Acme'])->clients()->create(['name' => 'Acme', 'currency' => 'PLN']);
        $markdown = "- top\n  - nested  \n    - 5 - 3 = 2\n\n```\nraw \u{2014} code\n```";
        DB::table('clients')->where('id', $client->id)->update(['report_baseline_markdown' => $markdown]);

        $this->artisan('crm:humanize --write')->assertSuccessful();

        $this->assertSame($markdown, DB::table('clients')->where('id', $client->id)->value('report_baseline_markdown'));
    }

    public function test_backfill_cleans_dashes_without_joining_lines(): void
    {
        $client = Account::create(['name' => 'Acme'])->clients()->create(['name' => 'Acme', 'currency' => 'PLN']);
        DB::table('clients')->where('id', $client->id)->update(['notes' => "Zakres:\n\u{2013} hero\n\u{2013} stopka"]);

        $this->artisan('crm:humanize --write')->assertSuccessful();

        $this->assertSame("Zakres:\n- hero\n- stopka", DB::table('clients')->where('id', $client->id)->value('notes'));
    }

    public function test_without_write_it_only_lists_what_would_change(): void
    {
        $client = Account::create(['name' => 'Acme'])->clients()->create(['name' => 'Acme', 'currency' => 'PLN']);
        DB::table('clients')->where('id', $client->id)->update(['notes' => "a \u{2014} b"]);

        $this->artisan('crm:humanize')
            ->expectsOutputToContain("[dry] clients #{$client->id}: notes")
            ->assertSuccessful();

        $this->assertSame("a \u{2014} b", DB::table('clients')->where('id', $client->id)->value('notes'));
    }

    public function test_a_report_body_is_changed_through_a_revision(): void
    {
        $account = Account::create(['name' => 'Acme']);
        $client = $account->clients()->create(['name' => 'Acme', 'currency' => 'PLN']);
        $report = $client->reports()->create([
            'account_id' => $account->id,
            'period_type' => 'month',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'actual_hours' => 0,
            'currency' => 'PLN',
            'composer_key' => 'manual',
            'body_markdown' => 'x',
            'status' => 'finalized',
        ]);
        DB::table('client_reports')->where('id', $report->id)->update(['body_markdown' => "Prace \u{2013} strona"]);

        $this->artisan('crm:humanize --write')->assertSuccessful();

        $report->refresh();
        $this->assertSame('Prace - strona', $report->body_markdown);
        $revision = $report->revisions()->sole();
        $this->assertSame('update', $revision->reason);
        $this->assertSame("Prace \u{2013} strona", $revision->body_markdown_before);
        $this->assertSame('finalized', $revision->status_before);
    }

    public function test_text_saved_after_the_scan_read_the_row_is_cleaned_not_overwritten(): void
    {
        $client = Account::create(['name' => 'Acme'])->clients()->create(['name' => 'Acme', 'currency' => 'PLN']);
        DB::table('clients')->where('id', $client->id)->update(['notes' => "old \u{2014} text"]);

        // An editor saves new text right after the scan's select returns.
        $saved = false;
        DB::listen(function ($query) use ($client, &$saved): void {
            if (! $saved && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"clients"')) {
                $saved = true;
                DB::table('clients')->where('id', $client->id)->update(['notes' => "new \u{2013} text"]);
            }
        });

        $this->artisan('crm:humanize --write')->assertSuccessful();

        $this->assertSame('new - text', DB::table('clients')->where('id', $client->id)->value('notes'));
    }
}
