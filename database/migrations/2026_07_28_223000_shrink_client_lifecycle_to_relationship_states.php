<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client lifecycle shrinks to relationship states: active / paused / churned.
 * The funnel head (prospect, offer_sent) belongs to the Leads pipeline since
 * the 2026-07-28 leads rework — a converted won lead is a client, not a
 * re-prospect. Existing pre-active clients map to 'paused' with a journal
 * event recording the change; historical events keep their original slugs
 * (append-only, rendered through the label fallback).
 *
 * Default flips to 'active': new clients (manual or lead-convert) start live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('lifecycle_stage', 32)->default('active')->change();
        });

        $stale = DB::table('clients')
            ->whereIn('lifecycle_stage', ['prospect', 'offer_sent'])
            ->get(['id', 'account_id', 'lifecycle_stage']);

        foreach ($stale as $client) {
            DB::table('client_lifecycle_events')->insert([
                'account_id' => $client->account_id,
                'client_id' => $client->id,
                'user_id' => null,
                'from_stage' => $client->lifecycle_stage,
                'to_stage' => 'paused',
                'note' => 'Lifecycle simplified to active/paused/churned — was: '.$client->lifecycle_stage,
                'created_at' => now(),
            ]);
        }

        DB::table('clients')
            ->whereIn('lifecycle_stage', ['prospect', 'offer_sent'])
            ->update(['lifecycle_stage' => 'paused']);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('lifecycle_stage', 32)->default('prospect')->change();
        });
        // Data mapping is irreversible by design — the journal records it.
    }
};
