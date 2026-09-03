<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-07-28 leads simplification: one plain-language stage vocabulary
 * (new -> conversation -> offer -> won) across both brand pipelines, and the
 * source taxonomy trimmed to a minimal measurement set.
 *
 * DB::table on purpose — the Lead model guards stage/source immutability at
 * the Eloquent boundary, and this is a vocabulary migration, not an
 * attribution edit. lead_stage_events keep their historical slugs: the
 * append-only history stays honest, and retired slugs still render through
 * the translation/humanize label chain.
 */
return new class extends Migration
{
    private const STAGE_MAP = [
        // kiwwwi
        'lead' => 'new',
        'mql' => 'conversation',
        'sql' => 'conversation',
        'proposal' => 'offer',
        // filipchrapek
        'suspect' => 'new',
        'touch' => 'new',
        'reply' => 'conversation',
        'trial' => 'offer',
        'active_relationship' => 'won',
        // 'conversation' and 'won' already match the unified set.
    ];

    private const SOURCE_MAP = [
        'organic-blog' => 'www-form',
        'organic-landing' => 'www-form',
        'gbp' => 'www-form',
        'magnet' => 'www-form',
        'newsletter' => 'www-form',
        'ads-google' => 'ads',
        'ads-meta' => 'ads',
        'linkedin-inbound' => 'social',
        'outreach-tivc' => 'outbound',
        'audit-value-first' => 'outbound',
        'agency-legacy' => 'other',
        // 'referral' already matches.
    ];

    public function up(): void
    {
        foreach (self::STAGE_MAP as $old => $new) {
            DB::table('leads')->where('stage', $old)->update(['stage' => $new]);
        }
        foreach (self::SOURCE_MAP as $old => $new) {
            DB::table('leads')->where('source', $old)->update(['source' => $new]);
        }
    }

    public function down(): void
    {
        // Irreversible by design: the old->new mapping is many-to-one.
    }
};
